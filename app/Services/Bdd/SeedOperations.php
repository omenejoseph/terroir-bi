<?php

declare(strict_types=1);

namespace App\Services\Bdd;

use App\Enums\MembershipStatus;
use App\Enums\StockMovementType;
use App\Enums\TenantRole;
use App\Models\Customer;
use App\Models\CustomerPrice;
use App\Models\InventoryItem;
use App\Models\Membership;
use App\Models\PricingTier;
use App\Models\StockMovement;
use App\Models\TierPrice;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Built-in Given primitives (always available, no grant needed). Each seed runs
 * under the sandbox tenant context, so BelongsToTenant scopes every row to the
 * sandbox automatically; the whole run is rolled back afterwards. Defaults
 * mirror the Essentials reference data (R3 2025 / Sangreal Shiraz 2021).
 */
class SeedOperations
{
    /**
     * @return list<OperationSpec>
     */
    public static function specs(): array
    {
        return [
            new OperationSpec(
                key: 'seed.inventory_item',
                kind: 'seed',
                summary: 'Create an inventory item (wine/product) in the sandbox with stock. Defaults: bottles, 6/case, 100 in stock, €12.00 list (1200 minor), €5.42 cost (542 minor).',
                parameters: [
                    'name' => 'string — product name (default "R3")',
                    'vintage' => 'string|null — e.g. "2025"',
                    'unit' => '"bottles"|"cases" — the STORAGE unit (default bottles)',
                    'sales_unit' => '"bottles"|"cases" — the unit it is SOLD in (defaults to unit)',
                    'bottles_per_case' => 'int (default 6)',
                    'current_stock' => 'numeric string — stock in the storage unit (default "100")',
                    'default_price' => 'int minor units per bottle (default 1200 = €12.00)',
                    'cost_per_unit' => 'int|null minor units per storage unit (default 542)',
                    'category' => 'string (default FINISHED)',
                    'is_for_sale' => 'bool (default true)',
                ],
            ),
            new OperationSpec(
                key: 'seed.customer',
                kind: 'seed',
                summary: 'Create a customer in the sandbox. Defaults: no rebate, included in stats.',
                parameters: [
                    'company_name' => 'string (default generated)',
                    'rebate_percent' => 'number 0–100 (default 0)',
                    'exclude_from_stats' => 'bool — internal outlet flag (default false)',
                    'customer_type' => 'string|null — e.g. "Retailer / Shop"',
                    'pricing_tier' => '$ref to a seed.pricing_tier capture (optional)',
                    'allow_single_bottle' => 'bool (default false)',
                ],
            ),
            new OperationSpec(
                key: 'seed.member',
                kind: 'seed',
                summary: 'Create a user with a membership in the sandbox tenant. Returns the User.',
                parameters: [
                    'name' => 'string (default generated)',
                    'roles' => 'list of role strings: ADMIN, TEAM, ORDERS, INVENTORY… (default ["TEAM"])',
                    'can_edit_orders' => 'bool (default false)',
                    'can_see_shipped_orders' => 'bool (default false)',
                ],
            ),
            new OperationSpec(
                key: 'seed.pricing_tier',
                kind: 'seed',
                summary: 'Create a pricing tier (B2B price book).',
                parameters: [
                    'name' => 'string (default generated)',
                    'rebate_percent' => 'number 0–100 (default 0)',
                ],
            ),
            new OperationSpec(
                key: 'seed.tier_price',
                kind: 'seed',
                summary: "Set a tier's catalog price for an item.",
                parameters: [
                    'tier' => '$ref to a seed.pricing_tier capture (required)',
                    'item' => '$ref to a seed.inventory_item capture (required)',
                    'price' => 'int minor units per bottle (required)',
                ],
            ),
            new OperationSpec(
                key: 'seed.customer_price',
                kind: 'seed',
                summary: 'Set a negotiated absolute price for a customer+item (no rebate applied on top).',
                parameters: [
                    'customer' => '$ref to a seed.customer capture (required)',
                    'item' => '$ref to a seed.inventory_item capture (required)',
                    'price' => 'int minor units per bottle (required)',
                ],
            ),
            new OperationSpec(
                key: 'seed.stock_movement',
                kind: 'seed',
                summary: 'Write a raw historical ledger row (and sync stock) — for legacy-data scenarios. Negative quantity = outflow.',
                parameters: [
                    'item' => '$ref to a seed.inventory_item capture (required)',
                    'type' => 'MANUAL_IN|MANUAL_OUT|ORDER_DEDUCT|ADJUSTMENT|PRODUCTION_IN|PRODUCTION_OUT|PURCHASE_IN (required)',
                    'quantity' => 'number — signed, in the movement unit (required)',
                    'unit' => '"bottles"|"cases"|null — the unit THIS movement was recorded in',
                    'is_reconciliation' => 'bool — count-correction flag (default false)',
                    'reference' => 'string|null',
                    'adjust_stock' => 'bool — keep book stock in sync (default true)',
                ],
            ),
        ];
    }

    private int $sequence = 0;

    public function __construct(private readonly SandboxContext $sandbox) {}

    /**
     * @param  array<string, mixed>  $args  already interpolated ($refs resolved to models)
     */
    public function execute(string $key, array $args): Model
    {
        return match ($key) {
            'seed.inventory_item' => $this->inventoryItem($args),
            'seed.customer' => $this->customer($args),
            'seed.member' => $this->member($args),
            'seed.pricing_tier' => $this->pricingTier($args),
            'seed.tier_price' => $this->tierPrice($args),
            'seed.customer_price' => $this->customerPrice($args),
            'seed.stock_movement' => $this->stockMovement($args),
            default => throw new InvalidArgumentException("Unknown seed operation [{$key}]."),
        };
    }

    private function uniq(): string
    {
        $this->sequence++;

        return substr((string) str()->ulid(), -8).$this->sequence;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function inventoryItem(array $args): InventoryItem
    {
        $unit = (string) ($args['unit'] ?? 'bottles');

        return InventoryItem::create([
            'name' => (string) ($args['name'] ?? 'R3'),
            'vintage' => array_key_exists('vintage', $args) ? $args['vintage'] : '2025',
            'sku' => 'BDD-'.$this->uniq(),
            'category' => (string) ($args['category'] ?? 'FINISHED'),
            'unit' => $unit,
            'sales_unit' => (string) ($args['sales_unit'] ?? $unit),
            'bottles_per_case' => (int) ($args['bottles_per_case'] ?? 6),
            'current_stock' => (string) ($args['current_stock'] ?? '100'),
            'is_for_sale' => (bool) ($args['is_for_sale'] ?? true),
            'default_price' => (int) ($args['default_price'] ?? 1200),
            'cost_per_unit' => array_key_exists('cost_per_unit', $args)
                ? ($args['cost_per_unit'] !== null ? (int) $args['cost_per_unit'] : null)
                : 542,
        ]);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function customer(array $args): Customer
    {
        $tier = $args['pricing_tier'] ?? null;

        return Customer::create([
            'company_name' => (string) ($args['company_name'] ?? 'Customer '.$this->uniq()),
            'email' => 'bdd-'.$this->uniq().'@sandbox.test',
            'rebate_percent' => (float) ($args['rebate_percent'] ?? 0),
            'exclude_from_stats' => (bool) ($args['exclude_from_stats'] ?? false),
            'customer_type' => isset($args['customer_type']) ? (string) $args['customer_type'] : null,
            'pricing_tier_id' => $tier instanceof PricingTier ? $tier->getKey() : null,
            'allow_single_bottle' => (bool) ($args['allow_single_bottle'] ?? false),
        ]);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function member(array $args): User
    {
        $roleInput = $args['roles'] ?? ['TEAM'];
        $roles = collect(is_array($roleInput) ? $roleInput : [$roleInput])
            ->map(fn ($r) => $r instanceof TenantRole ? $r : TenantRole::from((string) $r));

        $user = User::create([
            'first_name' => (string) ($args['name'] ?? 'Member '.$this->uniq()),
            'last_name' => 'Sandbox',
            'email' => 'bdd-member-'.$this->uniq().'@sandbox.test',
            'password' => str()->random(32),
        ]);

        Membership::create([
            'tenant_id' => $this->sandbox->tenant->getKey(),
            'user_id' => $user->getKey(),
            'roles' => $roles,
            'status' => MembershipStatus::Active,
            'can_edit_orders' => (bool) ($args['can_edit_orders'] ?? false),
            'can_see_shipped_orders' => (bool) ($args['can_see_shipped_orders'] ?? false),
            'joined_at' => now(),
        ]);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function pricingTier(array $args): PricingTier
    {
        return PricingTier::create([
            'name' => (string) ($args['name'] ?? 'Tier '.$this->uniq()),
            'rebate_percent' => (float) ($args['rebate_percent'] ?? 0),
        ]);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function tierPrice(array $args): TierPrice
    {
        $tier = $args['tier'] ?? null;
        $item = $args['item'] ?? null;

        if (! $tier instanceof PricingTier || ! $item instanceof InventoryItem) {
            throw new InvalidArgumentException('seed.tier_price needs $refs for "tier" and "item".');
        }

        return TierPrice::create([
            'pricing_tier_id' => $tier->getKey(),
            'inventory_item_id' => $item->getKey(),
            'price' => (int) ($args['price'] ?? 0),
        ]);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function customerPrice(array $args): CustomerPrice
    {
        $customer = $args['customer'] ?? null;
        $item = $args['item'] ?? null;

        if (! $customer instanceof Customer || ! $item instanceof InventoryItem) {
            throw new InvalidArgumentException('seed.customer_price needs $refs for "customer" and "item".');
        }

        return CustomerPrice::create([
            'customer_id' => $customer->getKey(),
            'inventory_item_id' => $item->getKey(),
            'price' => (int) ($args['price'] ?? 0),
        ]);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function stockMovement(array $args): StockMovement
    {
        $item = $args['item'] ?? null;

        if (! $item instanceof InventoryItem) {
            throw new InvalidArgumentException('seed.stock_movement needs a $ref for "item".');
        }

        $quantity = (float) ($args['quantity'] ?? 0);

        $movement = StockMovement::create([
            'inventory_item_id' => $item->getKey(),
            'type' => StockMovementType::from((string) ($args['type'] ?? 'MANUAL_OUT')),
            'quantity' => $quantity,
            'unit' => isset($args['unit']) ? (string) $args['unit'] : null,
            'reference' => isset($args['reference']) ? (string) $args['reference'] : null,
            'is_reconciliation' => (bool) ($args['is_reconciliation'] ?? false),
        ]);

        if ((bool) ($args['adjust_stock'] ?? true)) {
            $item->refresh();
            $item->current_stock = (string) ((float) $item->current_stock + $quantity);
            $item->save();
        }

        return $movement;
    }
}
