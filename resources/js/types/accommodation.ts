import type { MoneyValue } from './inventory';

export interface Option {
    value: string;
    label: string;
}

export interface AccommodationOptions {
    booking_statuses: Option[];
    order_statuses: Option[];
}

export interface VillaRow {
    id: string;
    name: string;
    slug: string;
    address: string | null;
    is_active: boolean;
    portal_path: string;
}

export interface VillaRate {
    id: string;
    label: string;
    start_date: string;
    end_date: string;
    nightly_rate: MoneyValue;
    min_nights: number | null;
    notes: string | null;
}

export interface VillaSection {
    id: string;
    title: string;
    icon: string | null;
    content: string | null;
    sort_order: number;
    is_active: boolean;
}

export interface VillaDetail extends VillaRow {
    coordinates: string | null;
    wifi_name: string | null;
    wifi_password: string | null;
    check_in_time: string | null;
    check_out_time: string | null;
    instructions: string | null;
    rules: string | null;
    welcome: string | null;
    transport: string | null;
    amenities: string | null;
    emergency_phone: string | null;
    contact_phone: string | null;
    contact_email: string | null;
    logo: string | null;
    rates: VillaRate[];
    sections: VillaSection[];
}

export type BookingStatus = 'UPCOMING' | 'CHECKED_IN' | 'CHECKED_OUT' | 'CANCELLED';

export interface VillaOrderRow {
    id: string;
    status: string;
    total: MoneyValue;
    delivery_date: string | null;
    notes: string | null;
    paid_at: string | null;
    created_at: string | null;
    booking: { id: string; guest_name: string; villa_name: string | null } | null;
    items: { id: string; name: string; quantity: number; unit_price: MoneyValue; total: MoneyValue; notes: string | null }[];
}

export interface BookingRow {
    id: string;
    villa: { id: string; name: string } | null;
    villa_id: string;
    guest_name: string;
    guest_email: string | null;
    guest_phone: string | null;
    check_in: string;
    check_out: string;
    nights: number;
    guests: number;
    notes: string | null;
    status: BookingStatus;
    total_amount: MoneyValue | null;
    auto_calculated_amount: MoneyValue | null;
    rebate_percent: number;
    deposit_amount: MoneyValue | null;
    deposit_paid: boolean;
    portal_path: string;
    orders?: VillaOrderRow[];
}

export interface MenuItemRow {
    id: string;
    villa_id: string;
    villa_name: string | null;
    category: string;
    name: string;
    description: string | null;
    price: MoneyValue;
    image: string | null;
    is_active: boolean;
    vat_included: boolean;
    inventory_item_id: string | null;
}

export interface AccommodationOverview {
    today: string;
    window_end: string;
    deposits_owed: MoneyValue;
    arrivals_today: BookingRow[];
    departures_today: BookingRow[];
    arrivals_week: BookingRow[];
    departures_week: BookingRow[];
    occupancy: { villa_id: string; name: string; booked_nights: number; percent: number }[];
    timeline: BookingRow[];
}

/** GET /accommodation/bookings/quote — BookingPricing::price (minor units). */
export interface BookingQuote {
    nights: number;
    subtotal: number;
    rebate: number;
    total: number;
    missing_nights: string[];
    breakdown: { date: string; rate: string | null; amount: number }[];
}
