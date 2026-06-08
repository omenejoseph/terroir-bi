<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\TenantRole;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CreateTenantCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function commandOptions(array $overrides = []): array
    {
        return array_merge([
            '--name' => 'Acme Wines',
            '--slug' => 'acme',
            '--currency' => 'EUR',
            '--locale' => 'hr',
            '--admin-first-name' => 'Ana',
            '--admin-last-name' => 'Horvat',
            '--admin-email' => 'ana@acme.hr',
            '--admin-password' => 'secret123',
        ], $overrides);
    }

    public function test_it_provisions_a_tenant_with_an_admin(): void
    {
        $this->assertSame(0, Artisan::call('tenant:create', $this->commandOptions()));

        $tenant = Tenant::query()->where('slug', 'acme')->first();
        $this->assertNotNull($tenant);
        $this->assertSame('EUR', $tenant->settings()->first()?->default_currency);

        $user = User::query()->where('email', 'ana@acme.hr')->first();
        $this->assertNotNull($user);

        $membership = Membership::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('user_id', $user->getKey())
            ->first();
        $this->assertNotNull($membership);
        $this->assertTrue($membership->hasRole(TenantRole::Admin));
    }

    public function test_it_rejects_a_duplicate_slug(): void
    {
        $this->assertSame(0, Artisan::call('tenant:create', $this->commandOptions(['--slug' => 'dup', '--admin-email' => 'a@dup.hr'])));
        $this->assertSame(1, Artisan::call('tenant:create', $this->commandOptions(['--slug' => 'dup', '--admin-email' => 'c@dup.hr'])));
    }
}
