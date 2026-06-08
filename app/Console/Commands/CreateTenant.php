<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Tenancy\CreateTenantAction;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Auth\TokenIssuer;
use App\Support\Money\CurrencyRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Interactively provision a tenant and its first admin user, then print a ready
 * API token so you can start calling the API immediately.
 */
class CreateTenant extends Command
{
    protected $signature = 'tenant:create
        {--name= : Tenant (winery) name}
        {--slug= : Subdomain slug, e.g. acme}
        {--currency= : Base currency code, e.g. EUR}
        {--locale= : Default locale (hr or en)}
        {--admin-first-name=}
        {--admin-last-name=}
        {--admin-email=}
        {--admin-password=}
        {--token-name=cli : Name for the issued API token}';

    protected $description = 'Create a new tenant and its first admin user (interactive)';

    public function handle(CreateTenantAction $createTenant, TokenIssuer $tokens): int
    {
        $name = $this->option('name') ?: text('Tenant (winery) name', required: true);

        $slug = $this->option('slug') ?: text(
            label: 'Subdomain slug',
            placeholder: 'acme',
            required: true,
            validate: fn (string $value) => $this->validateSlug($value),
            transform: fn (string $value) => Str::slug($value),
        );
        $slug = Str::slug($slug);

        if (Tenant::query()->where('slug', $slug)->exists()) {
            $this->error("A tenant with slug [{$slug}] already exists.");

            return self::FAILURE;
        }

        $currency = $this->option('currency') ?: select(
            label: 'Base currency',
            options: array_keys(CurrencyRegistry::all()),
            default: CurrencyRegistry::default()->code,
        );
        $currency = strtoupper((string) $currency);

        if (! CurrencyRegistry::isSupported($currency)) {
            $this->error("Unsupported currency [{$currency}]. Add it to config/money.php.");

            return self::FAILURE;
        }

        /** @var list<string> $locales */
        $locales = (array) config('app.supported_locales', ['hr']);
        $locale = (string) ($this->option('locale') ?: select('Default locale', $locales, (string) config('app.locale')));

        $planId = $this->choosePlan();

        $this->line('');
        $this->info('First admin user');
        $firstName = $this->option('admin-first-name') ?: text('First name', required: true);
        $lastName = $this->option('admin-last-name') ?: text('Last name', required: true);
        $email = $this->option('admin-email') ?: text('Email', required: true, validate: fn (string $v) => filter_var($v, FILTER_VALIDATE_EMAIL) ? null : 'Enter a valid email address.');
        $password = $this->option('admin-password') ?: password('Password', validate: fn (string $v) => $this->validatePassword($v));

        $result = $createTenant->execute([
            'name' => $name,
            'slug' => $slug,
            'currency' => $currency,
            'locale' => $locale,
            'plan_id' => $planId,
            'admin' => [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'password' => $password,
            ],
        ]);

        /** @var Tenant $tenant */
        $tenant = $result['tenant'];
        $token = $tokens->issue($result['user'], $tenant, (string) $this->option('token-name'));

        $this->newLine();
        $this->info('✓ Tenant created.');
        $this->table(['Field', 'Value'], [
            ['Tenant', $tenant->name],
            ['Tenant ID', $tenant->getKey()],
            ['Slug', $tenant->slug],
            ['Currency', $currency],
            ['Locale', $locale],
            ['Admin', $email.($result['user_created'] ? '' : ' (existing user)')],
        ]);

        $this->newLine();
        $this->line('<comment>API token (store it now — it is not shown again):</comment>');
        $this->line($token);

        $this->newLine();
        $this->line('<comment>Try it:</comment>');
        $this->line('  curl -s http://localhost:8000/api/v1/auth/me \\');
        $this->line("    -H 'Authorization: Bearer {$token}' -H 'Accept: application/json'");

        return self::SUCCESS;
    }

    private function choosePlan(): ?string
    {
        $plans = Plan::query()->orderBy('name')->get();

        if ($plans->isEmpty()) {
            return null;
        }

        /** @var array<string, string> $options */
        $options = ['' => 'No plan'];
        foreach ($plans as $plan) {
            $options[$plan->getKey()] = $plan->name;
        }

        $choice = (string) ($this->option('name') !== null && $this->option('slug') !== null
            ? '' // non-interactive default: no plan
            : select('Plan', $options, ''));

        return $choice === '' ? null : $choice;
    }

    private function validateSlug(string $value): ?string
    {
        $slug = Str::slug($value);

        if ($slug === '') {
            return 'Slug must contain letters or numbers.';
        }

        return Tenant::query()->where('slug', $slug)->exists()
            ? 'That slug is already taken.'
            : null;
    }

    private function validatePassword(string $value): ?string
    {
        $validator = validator(
            ['password' => $value],
            ['password' => ['required', Password::min(8)]],
        );

        return $validator->fails()
            ? (string) $validator->errors()->first('password')
            : null;
    }
}
