<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Module;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A SaaS subscription plan. Central (not tenant-scoped). Groups the modules a
 * tenant on the plan may use, the Stripe price it bills on, and the trial /
 * grace windows applied when the subscription lapses.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property Money|null $price_minor
 * @property string $currency
 * @property array<int, string>|null $modules
 * @property string|null $stripe_price_id
 * @property int $trial_days
 * @property int $grace_full_days
 * @property int $grace_readonly_days
 * @property string $interval
 * @property bool $is_active
 * @property bool $is_public
 */
class Plan extends Model
{
    use HasUlids;

    protected $fillable = [
        'name',
        'slug',
        'price_minor',
        'currency',
        'modules',
        'stripe_price_id',
        'trial_days',
        'grace_full_days',
        'grace_readonly_days',
        'interval',
        'is_active',
        'is_public',
    ];

    protected function casts(): array
    {
        return [
            // price_minor (bigint) + currency column -> Money value object.
            'price_minor' => MoneyCast::class.':currency',
            'modules' => 'array',
            'trial_days' => 'integer',
            'grace_full_days' => 'integer',
            'grace_readonly_days' => 'integer',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
        ];
    }

    /**
     * The plan's modules as enum cases (unknown/legacy keys are dropped).
     *
     * @return list<Module>
     */
    public function modules(): array
    {
        return array_values(array_filter(
            array_map(fn (string $key) => Module::tryFrom($key), $this->modules ?? []),
        ));
    }

    /** @return list<string> */
    public function moduleKeys(): array
    {
        return array_map(fn (Module $m) => $m->value, $this->modules());
    }

    public function hasModule(Module $module): bool
    {
        return in_array($module->value, $this->modules ?? [], true);
    }

    /** A free/internal plan that never bills (no Stripe price). */
    public function isFree(): bool
    {
        return $this->stripe_price_id === null;
    }

    /**
     * @return HasMany<Tenant, $this>
     */
    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }
}
