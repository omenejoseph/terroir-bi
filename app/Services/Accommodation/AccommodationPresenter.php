<?php

declare(strict_types=1);

namespace App\Services\Accommodation;

use App\Enums\VillaBookingStatus;
use App\Enums\VillaOrderStatus;
use App\Models\Villa;
use App\Models\VillaBooking;
use App\Models\VillaMenuItem;
use App\Models\VillaOrder;
use App\Models\VillaOrderItem;
use App\Models\VillaRate;
use App\Models\VillaSection;

/** Shapes villas, bookings, menu items and guest orders for the Accommodation pages. */
class AccommodationPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function villa(Villa $villa, bool $full = false): array
    {
        $row = [
            'id' => $villa->getKey(),
            'name' => $villa->name,
            'slug' => $villa->slug,
            'address' => $villa->address,
            'is_active' => $villa->is_active,
            'portal_path' => '/villa/'.$villa->portal_token,
        ];

        if (! $full) {
            return $row;
        }

        return $row + [
            'coordinates' => $villa->coordinates,
            'wifi_name' => $villa->wifi_name,
            'wifi_password' => $villa->wifi_password,
            'check_in_time' => $villa->check_in_time,
            'check_out_time' => $villa->check_out_time,
            'instructions' => $villa->instructions,
            'rules' => $villa->rules,
            'welcome' => $villa->welcome,
            'transport' => $villa->transport,
            'amenities' => $villa->amenities,
            'emergency_phone' => $villa->emergency_phone,
            'contact_phone' => $villa->contact_phone,
            'contact_email' => $villa->contact_email,
            'logo' => $villa->logo,
            'rates' => $villa->rates->map(fn (VillaRate $r): array => [
                'id' => $r->getKey(),
                'label' => $r->label,
                'start_date' => $r->start_date->toDateString(),
                'end_date' => $r->end_date->toDateString(),
                'nightly_rate' => $r->nightly_rate->jsonSerialize(),
                'min_nights' => $r->min_nights,
                'notes' => $r->notes,
            ])->values()->all(),
            'sections' => $villa->sections->map(fn (VillaSection $s): array => [
                'id' => $s->getKey(),
                'title' => $s->title,
                'icon' => $s->icon,
                'content' => $s->content,
                'sort_order' => $s->sort_order,
                'is_active' => $s->is_active,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function booking(VillaBooking $booking, bool $withOrders = false): array
    {
        $row = [
            'id' => $booking->getKey(),
            'villa' => $booking->villa instanceof Villa ? ['id' => $booking->villa->getKey(), 'name' => $booking->villa->name] : null,
            'villa_id' => $booking->villa_id,
            'guest_name' => $booking->guest_name,
            'guest_email' => $booking->guest_email,
            'guest_phone' => $booking->guest_phone,
            'check_in' => $booking->check_in->toDateString(),
            'check_out' => $booking->check_out->toDateString(),
            'nights' => $booking->nights(),
            'guests' => $booking->guests,
            'notes' => $booking->notes,
            'status' => $booking->status->value,
            'total_amount' => $booking->total_amount?->jsonSerialize(),
            'auto_calculated_amount' => $booking->auto_calculated_amount?->jsonSerialize(),
            'rebate_percent' => (float) $booking->rebate_percent,
            'deposit_amount' => $booking->deposit_amount?->jsonSerialize(),
            'deposit_paid' => $booking->deposit_paid,
            'portal_path' => '/villa/'.$booking->portal_token,
        ];

        if (! $withOrders) {
            return $row;
        }

        return $row + [
            'orders' => $booking->orders->map(fn (VillaOrder $o): array => $this->order($o))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function menuItem(VillaMenuItem $item): array
    {
        return [
            'id' => $item->getKey(),
            'villa_id' => $item->villa_id,
            'villa_name' => $item->villa?->name,
            'category' => $item->category,
            'name' => $item->name,
            'description' => $item->description,
            'price' => $item->price->jsonSerialize(),
            'image' => $item->image,
            'is_active' => $item->is_active,
            'vat_included' => $item->vat_included,
            'inventory_item_id' => $item->inventory_item_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function order(VillaOrder $order): array
    {
        $booking = $order->booking;

        return [
            'id' => $order->getKey(),
            'status' => $order->status->value,
            'total' => $order->total->jsonSerialize(),
            'delivery_date' => $order->delivery_date?->format('Y-m-d H:i'),
            'notes' => $order->notes,
            'paid_at' => $order->paid_at?->toIso8601String(),
            'created_at' => $order->created_at?->toIso8601String(),
            'booking' => $booking instanceof VillaBooking ? [
                'id' => $booking->getKey(),
                'guest_name' => $booking->guest_name,
                'villa_name' => $booking->villa?->name,
            ] : null,
            'items' => $order->items->map(fn (VillaOrderItem $i): array => [
                'id' => $i->getKey(),
                'name' => $i->name,
                'quantity' => $i->quantity,
                'unit_price' => $i->unit_price->jsonSerialize(),
                'total' => $i->total->jsonSerialize(),
                'notes' => $i->notes,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, list<array{value: string, label: string}>>
     */
    public static function options(): array
    {
        return [
            'booking_statuses' => array_map(fn (VillaBookingStatus $c): array => ['value' => $c->value, 'label' => __($c->label())], VillaBookingStatus::cases()),
            'order_statuses' => array_map(fn (VillaOrderStatus $c): array => ['value' => $c->value, 'label' => __($c->label())], VillaOrderStatus::cases()),
        ];
    }
}
