<?php

declare(strict_types=1);

namespace Tests\Feature\LegacyImport;

use App\Services\LegacyImport\IdMap;
use App\Services\LegacyImport\ImportContext;
use App\Services\LegacyImport\LegacyImporter;
use App\Services\LegacyImport\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenancy;
use Tests\Concerns\LegacyFixture;
use Tests\TestCase;

/** Villas come across with their portal tokens unchanged and their money verbatim. */
class AccommodationStepTest extends TestCase
{
    use InteractsWithTenancy;
    use LegacyFixture;
    use RefreshDatabase;

    private const STEPS = ['users', 'pricing_tiers', 'inventory', 'accommodation'];

    public function test_villas_bookings_menu_and_orders_come_across(): void
    {
        $this->createLegacySchema();
        $this->legacyInsert('Villa', ['id' => 'v1', 'name' => 'Villa Maslina', 'slug' => 'maslina', 'portalToken' => 'printed-qr-token', 'isActive' => 't', 'createdAt' => '2025-01-01', 'updatedAt' => '2025-01-01']);
        // Same slug as the first: made unique rather than failing.
        $this->legacyInsert('Villa', ['id' => 'v2', 'name' => 'Maslina II', 'slug' => 'maslina', 'portalToken' => 'other-token', 'isActive' => 't', 'createdAt' => '2025-01-01', 'updatedAt' => '2025-01-01']);
        $this->legacyInsert('VillaRate', ['id' => 'r1', 'villaId' => 'v1', 'label' => 'Peak', 'startDate' => '2026-07-01', 'endDate' => '2026-08-31', 'nightlyRate' => '300', 'createdAt' => '2025-01-01', 'updatedAt' => '2025-01-01']);
        $this->legacyInsert('VillaBooking', ['id' => 'b1', 'villaId' => 'v1', 'guestName' => 'Anna Weber', 'checkIn' => '2026-07-03', 'checkOut' => '2026-07-07', 'guests' => 4, 'portalToken' => 'guest-token', 'status' => 'UPCOMING', 'totalAmount' => '1150.50', 'rebatePercent' => '5', 'depositAmount' => '300', 'depositPaid' => 't', 'createdAt' => '2025-01-01', 'updatedAt' => '2025-01-01']);
        $this->legacyInsert('VillaMenuItem', ['id' => 'm1', 'villaId' => 'v1', 'category' => 'Breakfast', 'name' => 'Pancakes', 'price' => '12', 'isActive' => 't', 'sortOrder' => 1, 'vatIncluded' => 'f', 'createdAt' => '2025-01-01', 'updatedAt' => '2025-01-01']);
        $this->legacyInsert('VillaOrder', ['id' => 'o1', 'bookingId' => 'b1', 'status' => 'PAID', 'total' => '24', 'createdAt' => '2026-07-04', 'updatedAt' => '2026-07-04']);
        $this->legacyInsert('VillaOrderItem', ['id' => 'oi1', 'orderId' => 'o1', 'menuItemId' => 'm1', 'quantity' => 2, 'unitPrice' => '12', 'total' => '24']);

        $tenant = $this->createTenant();
        $ctx = new ImportContext($tenant, DB::connection('legacy'), new IdMap($tenant->getKey()), new Report);
        app(LegacyImporter::class)->run($ctx, self::STEPS);

        $this->assertSame('printed-qr-token', DB::table('villas')->where('name', 'Villa Maslina')->value('portal_token'));
        $this->assertSame(['maslina', 'maslina-2'], DB::table('villas')->orderBy('slug')->pluck('slug')->all());

        $booking = DB::table('villa_bookings')->first();
        $this->assertNotNull($booking);
        $this->assertSame(115050, (int) $booking->total_amount);
        $this->assertSame('guest-token', $booking->portal_token);
        $this->assertTrue((bool) $booking->deposit_paid);

        $this->assertSame(30000, (int) DB::table('villa_rates')->value('nightly_rate'));
        $this->assertSame('Pancakes', DB::table('villa_order_items')->value('name'));

        // The old "PAID" status becomes a paid, confirmed order.
        $order = DB::table('villa_orders')->first();
        $this->assertNotNull($order);
        $this->assertSame('CONFIRMED', $order->status);
        $this->assertNotNull($order->paid_at);
    }
}
