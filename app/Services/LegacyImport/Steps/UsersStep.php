<?php

declare(strict_types=1);

namespace App\Services\LegacyImport\Steps;

use App\Enums\MembershipStatus;
use App\Enums\TenantRole;
use App\Models\Membership;
use App\Models\User;
use App\Services\LegacyImport\ImportContext;
use App\Services\LegacyImport\Support\Normalize;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * Legacy `User` → global `users` + a membership of the target tenant. Keeps the
 * bcrypt hash (so staff keep their passwords), splits the comma-separated role
 * text into TenantRole[], and creates a suspended "Legacy Import" user that owns
 * rows whose creator can't be mapped (many created_by_id columns are NOT NULL).
 */
class UsersStep extends AbstractStep
{
    public const FALLBACK_EMAIL = 'legacy-import@bibich.invalid';

    /** Test/dev accounts present in the production dump — never migrated. */
    public const SKIP_EMAILS = ['admin@example.com', 'runoomene01@gmail.com'];

    public function name(): string
    {
        return 'users';
    }

    public function run(ImportContext $ctx): void
    {
        Model::withoutEvents(function () use ($ctx): void {
            $this->fallback($ctx);

            foreach ($ctx->legacy->table('User')->orderBy('createdAt')->get() as $row) {
                $ctx->report->read($this->name());
                $email = Str::lower(trim((string) $row->email));

                if (in_array($email, self::SKIP_EMAILS, true)) {
                    $ctx->report->skipped($this->name(), "test/dev account {$email} not migrated");

                    continue;
                }

                $this->import($ctx, $row, $email);
            }
        });
    }

    private function import(ImportContext $ctx, stdClass $row, string $email): void
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            [$first, $last] = Normalize::splitName((string) $row->name);
            $user = User::create([
                'first_name' => $first,
                'last_name' => $last,
                'email' => $email,
                'password' => Str::random(40), // replaced with the legacy hash below
            ]);
        }

        // Raw update: the model's `hashed` cast would otherwise re-hash.
        DB::table('users')->where('id', $user->getKey())->update([
            'password' => Normalize::bcrypt((string) $row->hashedPassword),
        ]);

        $roles = $this->roles($ctx, $row, $email);

        Membership::query()->updateOrCreate(
            ['tenant_id' => $ctx->tenant->getKey(), 'user_id' => $user->getKey()],
            [
                'roles' => $roles,
                'status' => MembershipStatus::Active,
                'can_edit_orders' => Normalize::bool($row->canEditOrders),
                'can_see_shipped_orders' => Normalize::bool($row->canSeeShippedOrders),
                'joined_at' => Normalize::date($row->createdAt),
            ],
        );

        $ctx->ids->put('User', (string) $row->id, (string) $user->getKey());
        $ctx->report->written($this->name());
    }

    /** @return Collection<int, TenantRole> */
    private function roles(ImportContext $ctx, stdClass $row, string $email): Collection
    {
        $roles = collect(explode(',', (string) $row->role))
            ->map(fn (string $r) => trim($r))
            ->filter()
            ->map(function (string $r) use ($ctx, $email): ?TenantRole {
                $role = TenantRole::tryFrom(strtoupper($r));
                if ($role === null) {
                    $ctx->report->warn($this->name(), "unknown role '{$r}' for {$email} dropped");
                }

                return $role;
            })
            ->filter()
            ->unique()
            ->values();

        if ($roles->isEmpty()) {
            $ctx->report->warn($this->name(), "{$email} has no mappable role; defaulting to EMPLOYEE");
            $roles = $roles->push(TenantRole::Employee);
        }

        return $roles;
    }

    private function fallback(ImportContext $ctx): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => self::FALLBACK_EMAIL],
            [
                'first_name' => 'Legacy',
                'last_name' => 'Import',
                'password' => Str::random(60),
            ],
        );
        // Not fillable by design: this account can never log in.
        DB::table('users')->where('id', $user->getKey())->update(['suspended_at' => now()]);

        Membership::query()->updateOrCreate(
            ['tenant_id' => $ctx->tenant->getKey(), 'user_id' => $user->getKey()],
            [
                'roles' => collect([TenantRole::Employee]),
                'status' => MembershipStatus::Suspended,
                'joined_at' => now(),
            ],
        );

        $ctx->fallbackUser = $user->refresh();
    }
}
