<?php

declare(strict_types=1);

namespace App\Services\LegacyImport\Steps;

use App\Enums\VillaBookingStatus;
use App\Enums\VillaOrderStatus;
use App\Models\Villa;
use App\Services\LegacyImport\ImportContext;
use App\Services\LegacyImport\Support\Normalize;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The old app's villas (docs/21 §4): villas, rates, portal sections, bookings, the in-villa menu, and
 * guest orders. Portal tokens are kept as they are, so QR codes already printed and handed to guests
 * keep working. Money is copied verbatim — bookings are never re-priced.
 *
 * Optional legacy columns are read with `?? null`, so a column the old schema does not have reads
 * as empty instead of failing the run. Unknown statuses are mapped and reported.
 */
class AccommodationStep extends AbstractStep
{
    public function name(): string
    {
        return 'accommodation';
    }

    public function dependsOn(): array
    {
        return ['inventory'];
    }

    public function run(ImportContext $ctx): void
    {
        $this->villas($ctx);
        $this->rates($ctx);
        $this->sections($ctx);
        $this->bookings($ctx);
        $this->menu($ctx);
        $this->orders($ctx);
    }

    private function villas(ImportContext $ctx): void
    {
        $slugs = [];

        foreach ($this->rows($ctx, 'Villa') as $r) {
            $ctx->report->read('villas');
            $name = trim((string) $r->name);

            // The old app could hold two villas with one slug; the rebuild keeps slugs unique per tenant.
            $slug = Str::slug((string) ($r->slug ?? $name)) ?: 'villa';
            $base = $slug;
            for ($n = 2; in_array($slug, $slugs, true) || $this->slugTaken($ctx, $slug, (string) $r->id); $n++) {
                $slug = "{$base}-{$n}";
            }
            $slugs[] = $slug;

            // Kept only when it is valid JSON; anything else would fail the json column.
            $order = $r->portalTabOrder ?? null;
            $order = is_string($order) && $order !== '' && json_decode($order) !== null ? $order : null;

            $this->put($ctx, 'Villa', (string) $r->id, 'villas', [
                'name' => $this->fit($ctx, 'villas', 'villa name', $name) ?? '',
                'slug' => $slug,
                'portal_token' => Normalize::str($r->portalToken ?? null) ?? Villa::newPortalToken(),
                'address' => $this->fit($ctx, 'villas', 'address', Normalize::str($r->address ?? null)),
                'coordinates' => $this->fit($ctx, 'villas', 'coordinates', Normalize::str($r->coordinates ?? null)),
                'wifi_name' => $this->fit($ctx, 'villas', 'wifi name', Normalize::str($r->wifiName ?? null)),
                'wifi_password' => $this->fit($ctx, 'villas', 'wifi password', Normalize::str($r->wifiPassword ?? null)),
                'check_in_time' => Normalize::str($r->checkInTime ?? null),
                'check_out_time' => Normalize::str($r->checkOutTime ?? null),
                'instructions' => Normalize::str($r->instructions ?? null),
                'rules' => Normalize::str($r->rules ?? $r->houseRules ?? null),
                'welcome' => Normalize::str($r->welcome ?? $r->welcomeMessage ?? null),
                'transport' => Normalize::str($r->transport ?? null),
                'amenities' => Normalize::str($r->amenities ?? null),
                'emergency_phone' => $this->fit($ctx, 'villas', 'emergency phone', Normalize::str($r->emergencyPhone ?? null)),
                'contact_phone' => $this->fit($ctx, 'villas', 'contact phone', Normalize::str($r->contactPhone ?? null)),
                'contact_email' => $this->fit($ctx, 'villas', 'contact email', Normalize::str($r->contactEmail ?? null)),
                'logo' => Normalize::str($r->logo ?? $r->logoUrl ?? null),
                'portal_tab_order' => $order,
                'is_active' => Normalize::bool($r->isActive ?? true),
                'created_at' => Normalize::date($r->createdAt ?? null) ?? now(),
                'updated_at' => Normalize::date($r->updatedAt ?? null) ?? now(),
            ]);
            $ctx->report->written('villas');
        }
    }

    private function slugTaken(ImportContext $ctx, string $slug, string $legacyId): bool
    {
        $mine = $ctx->ids->get('Villa', $legacyId);

        return DB::table('villas')
            ->where('tenant_id', $ctx->tenant->getKey())
            ->where('slug', $slug)
            ->when($mine !== null, fn ($q) => $q->where('id', '!=', $mine))
            ->exists();
    }

    private function rates(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'VillaRate') as $r) {
            $ctx->report->read('villa_rates');
            $villa = $ctx->ids->get('Villa', (string) $r->villaId);
            if ($villa === null) {
                $ctx->report->skipped('villa_rates', "rate {$r->id}: villa not migrated");

                continue;
            }

            $this->put($ctx, 'VillaRate', (string) $r->id, 'villa_rates', [
                'villa_id' => $villa,
                'label' => $this->fit($ctx, 'villa_rates', 'label', Normalize::str($r->label ?? null)) ?? '',
                'start_date' => Normalize::date($r->startDate)?->toDateString(),
                'end_date' => Normalize::date($r->endDate)?->toDateString(),
                'nightly_rate' => Normalize::minor($r->nightlyRate ?? null),
                'min_nights' => ($r->minNights ?? null) === null ? null : (int) $r->minNights,
                'notes' => Normalize::str($r->notes ?? null),
                'created_at' => Normalize::date($r->createdAt ?? null) ?? now(),
                'updated_at' => Normalize::date($r->updatedAt ?? null) ?? now(),
            ]);
            $ctx->report->written('villa_rates');
        }
    }

    private function sections(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'VillaSection') as $r) {
            $ctx->report->read('villa_sections');
            $villa = $ctx->ids->get('Villa', (string) $r->villaId);
            if ($villa === null) {
                $ctx->report->skipped('villa_sections', "section {$r->id}: villa not migrated");

                continue;
            }

            $this->put($ctx, 'VillaSection', (string) $r->id, 'villa_sections', [
                'villa_id' => $villa,
                'title' => $this->fit($ctx, 'villa_sections', 'title', trim((string) ($r->title ?? ''))) ?? '',
                'icon' => $this->fit($ctx, 'villa_sections', 'icon', Normalize::str($r->icon ?? null)),
                'content' => Normalize::str($r->content ?? null),
                'sort_order' => (int) ($r->sortOrder ?? 0),
                'is_active' => Normalize::bool($r->isActive ?? true),
                'created_at' => Normalize::date($r->createdAt ?? null) ?? now(),
                'updated_at' => Normalize::date($r->updatedAt ?? null) ?? now(),
            ]);
            $ctx->report->written('villa_sections');
        }
    }

    private function bookings(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'VillaBooking') as $r) {
            $ctx->report->read('villa_bookings');
            $villa = $ctx->ids->get('Villa', (string) $r->villaId);
            if ($villa === null) {
                $ctx->report->skipped('villa_bookings', "booking {$r->id}: villa not migrated");

                continue;
            }

            $status = VillaBookingStatus::tryFrom(strtoupper(trim((string) ($r->status ?? ''))));
            if ($status === null) {
                $ctx->report->warn('villa_bookings', "booking {$r->id}: unknown status '".(string) ($r->status ?? '')."'; imported as UPCOMING");
            }

            $this->put($ctx, 'VillaBooking', (string) $r->id, 'villa_bookings', [
                'villa_id' => $villa,
                'guest_name' => $this->fit($ctx, 'villa_bookings', 'guest name', trim((string) ($r->guestName ?? ''))) ?? '',
                'guest_email' => $this->fit($ctx, 'villa_bookings', 'guest email', Normalize::str($r->guestEmail ?? null)),
                'guest_phone' => $this->fit($ctx, 'villa_bookings', 'guest phone', Normalize::str($r->guestPhone ?? null)),
                'check_in' => Normalize::date($r->checkIn)?->toDateString(),
                'check_out' => Normalize::date($r->checkOut)?->toDateString(),
                'guests' => (int) ($r->guests ?? 2),
                'portal_token' => Normalize::str($r->portalToken ?? null) ?? Villa::newPortalToken(),
                'notes' => Normalize::str($r->notes ?? null),
                'status' => ($status ?? VillaBookingStatus::Upcoming)->value,
                'total_amount' => Normalize::minorOrNull($r->totalAmount ?? null),
                'rebate_percent' => Normalize::dec($r->rebatePercent ?? 0, 2) ?? '0.00',
                'auto_calculated_amount' => Normalize::minorOrNull($r->autoCalculatedAmount ?? null),
                'deposit_amount' => Normalize::minorOrNull($r->depositAmount ?? null),
                'deposit_paid' => Normalize::bool($r->depositPaid ?? false),
                'created_at' => Normalize::date($r->createdAt ?? null) ?? now(),
                'updated_at' => Normalize::date($r->updatedAt ?? null) ?? now(),
            ]);
            $ctx->report->written('villa_bookings');
        }
    }

    private function menu(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'VillaMenuItem') as $r) {
            $ctx->report->read('villa_menu_items');
            $villa = $ctx->ids->get('Villa', (string) $r->villaId);
            if ($villa === null) {
                $ctx->report->skipped('villa_menu_items', "menu item {$r->id}: villa not migrated");

                continue;
            }

            $legacyItem = Normalize::str($r->inventoryItemId ?? null);

            $this->put($ctx, 'VillaMenuItem', (string) $r->id, 'villa_menu_items', [
                'villa_id' => $villa,
                'category' => $this->fit($ctx, 'villa_menu_items', 'category', Normalize::str($r->category ?? null)) ?? '',
                'name' => $this->fit($ctx, 'villa_menu_items', 'name', trim((string) ($r->name ?? ''))) ?? '',
                'description' => Normalize::str($r->description ?? null),
                'price' => Normalize::minor($r->price ?? null),
                'image' => Normalize::str($r->image ?? null),
                'is_active' => Normalize::bool($r->isActive ?? true),
                'sort_order' => (int) ($r->sortOrder ?? 0),
                'vat_included' => Normalize::bool($r->vatIncluded ?? true),
                'inventory_item_id' => $legacyItem === null ? null : $ctx->ids->get('InventoryItem', $legacyItem),
                'created_at' => Normalize::date($r->createdAt ?? null) ?? now(),
                'updated_at' => Normalize::date($r->updatedAt ?? null) ?? now(),
            ]);
            $ctx->report->written('villa_menu_items');
        }
    }

    private function orders(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'VillaOrder') as $r) {
            $ctx->report->read('villa_orders');
            $booking = $ctx->ids->get('VillaBooking', (string) $r->bookingId);
            if ($booking === null) {
                $ctx->report->skipped('villa_orders', "order {$r->id}: booking not migrated");

                continue;
            }

            $status = VillaOrderStatus::tryFrom(strtoupper(trim((string) ($r->status ?? ''))));
            $paidAt = Normalize::date($r->paidAt ?? null);
            if ($status === null) {
                // The old app kept "PAID" as a status; the rebuild keeps payment apart from status.
                $paidAt ??= strtoupper(trim((string) ($r->status ?? ''))) === 'PAID' ? (Normalize::date($r->updatedAt ?? null) ?? now()->toImmutable()) : null;
                $ctx->report->warn('villa_orders', "order {$r->id}: status '".(string) ($r->status ?? '')."' imported as ".($paidAt !== null ? 'CONFIRMED (paid)' : 'PENDING'));
                $status = $paidAt !== null ? VillaOrderStatus::Confirmed : VillaOrderStatus::Pending;
            }

            $this->put($ctx, 'VillaOrder', (string) $r->id, 'villa_orders', [
                'booking_id' => $booking,
                'status' => $status->value,
                'total' => Normalize::minor($r->total ?? $r->totalAmount ?? null),
                'delivery_date' => Normalize::date($r->deliveryDate ?? null),
                'notes' => Normalize::str($r->notes ?? null),
                'stripe_payment_id' => Normalize::str($r->stripePaymentId ?? null),
                'paid_at' => $paidAt,
                'created_at' => Normalize::date($r->createdAt ?? null) ?? now(),
                'updated_at' => Normalize::date($r->updatedAt ?? null) ?? now(),
            ]);
            $ctx->report->written('villa_orders');
        }

        foreach ($this->rows($ctx, 'VillaOrderItem') as $r) {
            $ctx->report->read('villa_order_items');
            $order = $ctx->ids->get('VillaOrder', (string) $r->orderId);
            if ($order === null) {
                $ctx->report->skipped('villa_order_items', "order line {$r->id}: order not migrated");

                continue;
            }

            $legacyMenu = Normalize::str($r->menuItemId ?? null);
            $menu = $legacyMenu === null ? null : $ctx->ids->get('VillaMenuItem', $legacyMenu);
            $name = Normalize::str($r->name ?? null)
                ?? ($menu !== null ? (string) DB::table('villa_menu_items')->where('id', $menu)->value('name') : null)
                ?? __('(deleted item)');

            $this->put($ctx, 'VillaOrderItem', (string) $r->id, 'villa_order_items', [
                'order_id' => $order,
                'menu_item_id' => $menu,
                'name' => $name,
                'quantity' => (int) ($r->quantity ?? 1),
                'unit_price' => Normalize::minor($r->unitPrice ?? null),
                'total' => Normalize::minor($r->total ?? null),
                'notes' => Normalize::str($r->notes ?? null),
            ]);
            $ctx->report->written('villa_order_items');
        }
    }
}
