<?php

declare(strict_types=1);

namespace Tests\Feature\LegacyImport;

use App\Enums\MembershipStatus;
use App\Enums\TenantRole;
use App\Models\Membership;
use App\Models\User;
use App\Services\LegacyImport\IdMap;
use App\Services\LegacyImport\ImportContext;
use App\Services\LegacyImport\LegacyImporter;
use App\Services\LegacyImport\Report;
use App\Services\LegacyImport\Steps\UsersStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class UsersStepTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private const HASH = '$2b$12$abcdefghijklmnopqrstuuOxXUNBsXW6hQHwJKaGgIhVCbWQ8aG6y';

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.legacy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('legacy');

        Schema::connection('legacy')->create('User', function ($t): void {
            $t->string('id');
            $t->string('name');
            $t->string('email');
            $t->string('hashedPassword');
            $t->string('role');
            $t->string('createdAt');
            $t->string('canEditOrders');
            $t->string('canSeeShippedOrders');
        });
    }

    /** @param array<string, string> $o */
    private function legacyUser(string $id, string $name, string $email, string $role, array $o = []): void
    {
        DB::connection('legacy')->table('User')->insert(array_merge([
            'id' => $id, 'name' => $name, 'email' => $email, 'hashedPassword' => self::HASH,
            'role' => $role, 'createdAt' => '2026-02-26 18:45:41.274',
            'canEditOrders' => 'f', 'canSeeShippedOrders' => 'f',
        ], $o));
    }

    private function run_(): ImportContext
    {
        $tenant = $this->createTenant();
        $ctx = new ImportContext($tenant, DB::connection('legacy'), new IdMap($tenant->getKey()), new Report);
        app(LegacyImporter::class)->run($ctx, ['users']);

        return $ctx;
    }

    public function test_imports_users_with_roles_flags_and_preserved_hash(): void
    {
        $this->legacyUser('u1', 'Dragan Jurišić', 'Dragan@Bibich.co', 'CELLAR,ORDERS,INVENTORY', ['canEditOrders' => 't']);
        $this->legacyUser('u2', 'Ramon', 'ramon@bibich.co', 'MANAGER,BOGUS');
        $this->legacyUser('u3', 'Claude', 'admin@example.com', 'ADMIN');

        $ctx = $this->run_();

        $this->assertSame(2, $ctx->report->counts()['users']['written']);
        $this->assertNull(User::query()->where('email', 'admin@example.com')->first());

        $dragan = User::query()->where('email', 'dragan@bibich.co')->firstOrFail();
        $this->assertSame('Dragan', $dragan->first_name);
        $this->assertSame('Jurišić', $dragan->last_name);
        $this->assertSame($dragan->getKey(), $ctx->ids->get('User', 'u1'));

        // $2b$ is stored as $2y$ and still verifies; the cast must not have re-hashed it.
        $this->assertStringStartsWith('$2y$12$abcdefghijklmnopqrstuu', $dragan->password);

        $m = Membership::query()->where('user_id', $dragan->getKey())->firstOrFail();
        $this->assertEquals([TenantRole::Cellar, TenantRole::Orders, TenantRole::Inventory], $m->roles->all());
        $this->assertTrue($m->can_edit_orders);

        $ramon = Membership::query()->where('user_id', User::query()->where('email', 'ramon@bibich.co')->value('id'))->firstOrFail();
        $this->assertEquals([TenantRole::Manager], $ramon->roles->all());
        $this->assertSame('-', User::query()->where('email', 'ramon@bibich.co')->value('last_name'));
        $this->assertNotEmpty(array_filter($ctx->report->warnings(), fn ($w) => str_contains($w, "unknown role 'BOGUS'")));
    }

    public function test_creates_suspended_fallback_user_and_is_idempotent(): void
    {
        $this->legacyUser('u1', 'Filip Bibic', 'filip@bibich.co', 'ADMIN');

        $ctx = $this->run_();
        app(LegacyImporter::class)->run(new ImportContext($ctx->tenant, DB::connection('legacy'), $ctx->ids, new Report), ['users']);

        $fallback = User::query()->where('email', UsersStep::FALLBACK_EMAIL)->firstOrFail();
        $this->assertTrue($fallback->isSuspended());
        $this->assertSame(
            MembershipStatus::Suspended,
            Membership::query()->where('user_id', $fallback->getKey())->firstOrFail()->status,
        );
        $this->assertSame(2, Membership::query()->where('tenant_id', $ctx->tenant->getKey())->count());
        $this->assertSame(2, User::query()->count());
        $this->assertTrue(Hash::check('x', Hash::make('x'))); // sanity: hashing driver intact
    }
}
