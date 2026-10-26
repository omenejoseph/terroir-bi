<?php

declare(strict_types=1);

namespace Tests\Feature\Hospitality;

use App\Enums\TenantRole;
use App\Models\Customer;
use App\Models\HospitalityProgram;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/** Shared fixtures for the hospitality tests: one tenant with a hospitality user, bound for the test. */
abstract class HospitalityTestCase extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    protected Tenant $tenant;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->user = $this->createMember($this->tenant, [TenantRole::Hospitality]);
        $this->actingAsTenant($this->tenant);
    }

    /** @param array<string, mixed> $attributes */
    protected function program(int $baseMinor = 5000, array $attributes = []): HospitalityProgram
    {
        return HospitalityProgram::create(array_merge(['name' => 'Classic tasting', 'base_price' => $baseMinor], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    protected function customer(string $name, bool $agency = false, float $rebate = 0, array $attributes = []): Customer
    {
        return Customer::create(array_merge([
            'company_name' => $name,
            'email' => strtolower(str_replace(' ', '', $name)).'@x.hr',
            'is_agency' => $agency,
            'rebate_percent' => $rebate,
        ], $attributes));
    }
}
