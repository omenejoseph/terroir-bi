<?php

declare(strict_types=1);

namespace App\Services\LegacyImport;

use App\Models\Tenant;
use App\Models\User;
use App\Services\LegacyImport\Steps\UsersStep;
use Illuminate\Database\Connection;

class ImportContext
{
    public ?User $fallbackUser = null;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly Connection $legacy,
        public readonly IdMap $ids,
        public readonly Report $report,
        public readonly int $chunk = 500,
    ) {}

    /** Mapped user id for a legacy user id, else the fallback import user (many created_by columns are NOT NULL). */
    public function userId(?string $legacyId, string $step): string
    {
        $mapped = $this->ids->get('User', $legacyId);
        if ($mapped !== null) {
            return $mapped;
        }
        $this->report->warn($step, 'creator '.($legacyId ?? 'NULL').' not migrated; attributed to Legacy Import user');

        return (string) $this->fallbackUser()->getKey();
    }

    public function fallbackUser(): User
    {
        // Set by UsersStep in the same run, otherwise looked up (e.g. `--only=orders`).
        return $this->fallbackUser ??= User::query()->where('email', UsersStep::FALLBACK_EMAIL)->first()
            ?? throw new \LogicException('Fallback import user does not exist yet; run the users step first.');
    }
}
