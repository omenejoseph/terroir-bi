<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Actions\Orders\UpdateOrderStatusAction;
use App\Enums\OrderStatus;
use App\Enums\StockMovementType;
use App\Enums\TenantRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Customers\CustomerMergeService;
use App\Services\Inventory\StockLedger;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The two halves of "log every significant action": the automatic
 * App\Services\Audit\Auditable floor (plain model create/update/delete) and
 * the hand-picked explicit AuditLogger calls for actions that aren't one
 * model save (see AuditLogger's docblock and each call site's own comment).
 */
class AuditLoggingTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->admin = $this->createMember($this->tenant, [TenantRole::Admin]);
        Sanctum::actingAs($this->admin);
        $this->actingAsTenant($this->tenant);
    }

    public function test_creating_a_customer_is_logged_automatically_and_scoped_to_the_tenant(): void
    {
        $customer = Customer::create([
            'company_name' => 'Konoba Fjaka', 'email' => 'fjaka@example.test', 'customer_type' => 'RETAIL',
        ]);

        $log = AuditLog::query()->where('action', 'customer.created')->firstOrFail();

        $this->assertSame($this->tenant->getKey(), $log->tenant_id);
        $this->assertSame($this->admin->getKey(), $log->actor_id);
        $this->assertSame(Customer::class, $log->subject_type);
        $this->assertSame($customer->getKey(), $log->subject_id);
    }

    public function test_an_order_status_change_writes_one_richly_named_entry_not_a_bare_diff(): void
    {
        $customer = Customer::create(['company_name' => 'Restoran Adio', 'email' => 'adio@example.test']);
        $order = Order::create([
            'order_number' => 'VT-1', 'status' => OrderStatus::Received,
            'customer_id' => $customer->getKey(), 'created_by_id' => $this->admin->getKey(),
            'total_amount' => Money::fromMinor(1000, 'EUR'),
        ]);
        AuditLog::query()->delete(); // isolate the status-change entry from the create noise above.

        app(UpdateOrderStatusAction::class)->execute($order, OrderStatus::Shipped, 'Left the cellar', $this->admin->getKey());

        // Exactly one entry for the change — Order::auditIgnoreOnUpdate()
        // suppresses the generic `order.updated` a bare status write would
        // otherwise also produce.
        $this->assertSame(1, AuditLog::query()->count());
        $log = AuditLog::query()->firstOrFail();
        $this->assertSame('order.status_changed', $log->action);
        // Individual keys, not a whole-array comparison: a JSON column's key
        // order isn't guaranteed across DB drivers, and isn't the point here.
        $metadata = $log->metadata;
        $this->assertIsArray($metadata);
        $this->assertSame('RECEIVED', $metadata['from']);
        $this->assertSame('SHIPPED', $metadata['to']);
        $this->assertSame('Left the cellar', $metadata['note']);
    }

    public function test_a_stock_adjustment_writes_a_richly_named_entry_not_a_bare_diff(): void
    {
        $item = InventoryItem::create([
            'name' => 'Malvazija 2023', 'sku' => 'MAL-23', 'category' => 'FINISHED',
            'unit' => 'bottles', 'sales_unit' => 'bottles', 'current_stock' => '0',
            'is_for_sale' => true, 'default_price' => Money::fromMinor(1500, 'EUR'),
        ]);
        AuditLog::query()->delete();

        app(StockLedger::class)->record($item, StockMovementType::ManualIn, '120', null, 'Delivery received');

        // Exactly one entry — InventoryItem::auditIgnoreOnUpdate() suppresses
        // the generic `inventory_item.updated` a bare current_stock write
        // would otherwise also produce.
        $this->assertSame(1, AuditLog::query()->count());
        $log = AuditLog::query()->firstOrFail();
        $this->assertSame('inventory_item.stock_adjusted', $log->action);
        $metadata = $log->metadata;
        $this->assertIsArray($metadata);
        $this->assertSame('MANUAL_IN', $metadata['type']);
        $this->assertSame('120', $metadata['signed_quantity']);
    }

    public function test_merging_customers_logs_where_the_loser_went(): void
    {
        $winner = Customer::create(['company_name' => 'Konzum', 'email' => 'konzum@example.test']);
        $loser = Customer::create(['company_name' => 'Konzum d.o.o.', 'email' => 'konzum2@example.test']);
        AuditLog::query()->delete();

        app(CustomerMergeService::class)->merge($winner, [$loser->getKey()]);

        $log = AuditLog::query()->where('action', 'customer.merged')->firstOrFail();

        $this->assertSame($loser->getKey(), $log->subject_id);
        $metadata = $log->metadata;
        $this->assertIsArray($metadata);
        $this->assertSame($winner->getKey(), $metadata['into_customer_id']);

        // The loser's own deletion is still logged automatically too.
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.deleted', 'subject_id' => $loser->getKey()]);
    }

    public function test_a_platform_admin_action_with_no_bound_tenant_lands_with_a_null_tenant_id(): void
    {
        $this->forgetTenant();

        $log = app(AuditLogger::class)->record(null, 'user.suspended');

        $this->assertNull($log->tenant_id);
    }
}
