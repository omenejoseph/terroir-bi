import type { MoneyValue } from './inventory';

/** App\Enums\ReservationStatus values. */
export type ReservationStatus = 'PENDING' | 'CONFIRMED' | 'INVOICE_SENT' | 'PAID' | 'COMPLETED' | 'CANCELLED';

export interface StatusOption {
    value: ReservationStatus;
    label: string;
}

/** One row of the reservation list (ReservationPresenter::row). `date` is wall-clock `YYYY-MM-DD HH:mm`. */
export interface ReservationRow {
    id: string;
    date: string;
    pax: number;
    status: ReservationStatus;
    price_per_pax: MoneyValue;
    total_amount: MoneyValue;
    guest_name: string | null;
    program: { id: string; name: string } | null;
    customer: { id: string; name: string } | null;
    created_by: string | null;
}

export interface ReservationHistoryLine {
    id: string;
    status: ReservationStatus;
    note: string | null;
    by: string | null;
    at: string | null;
}

/** The reservation page (ReservationPresenter::detail). */
export interface ReservationDetail extends Omit<ReservationRow, 'program' | 'customer'> {
    guest_email: string | null;
    guest_phone: string | null;
    allergies: string | null;
    notes: string | null;
    cancel_reason: string | null;
    program: Program | null;
    customer: { id: string; name: string; is_agency: boolean } | null;
    profitability: { list_total: { minor: number; currency: string } | null; rebate_percent: number | null };
    next_status: ReservationStatus | null;
    deletable: boolean;
    history: ReservationHistoryLine[];
}

export interface Program {
    id: string;
    name: string;
    description: string | null;
    duration_mins: number | null;
    base_price: MoneyValue;
    max_pax: number | null;
    is_active: boolean;
}

/** A program as the Programs tab and the pickers list it (HospitalityOptions::programs). */
export interface ProgramRow extends Program {
    agency_price_count: number;
    reservation_count: number;
}

export interface AgencyOption {
    id: string;
    name: string;
    is_active: boolean;
}

/** One line of a program's price book (HospitalityOptions::agencyPrices). */
export interface AgencyPriceRow {
    id: string;
    customer_id: string;
    customer_name: string | null;
    price_per_pax: MoneyValue;
    /** Set when the price was entered as a rebate; it then follows the base price. */
    rebate_percent: number | null;
    effective_rebate_percent: number | null;
}

export interface ReservationFilters {
    search: string | null;
    status: ReservationStatus | null;
    program_id: string | null;
    /** An agency id, or 'walk_in'. */
    customer_id: string | null;
    period: 'today' | 'month' | 'year' | 'upcoming' | 'all';
    from: string | null;
    to: string | null;
}

export interface StatTile {
    count: number;
    pax: number;
    revenue: MoneyValue;
    previous_revenue: MoneyValue;
    /** Change against the same window last year; null when last year had nothing to compare. */
    delta_percent: number | null;
}

export interface HospitalityStats {
    today: StatTile;
    month: StatTile;
    year: StatTile;
    avg_pax: number;
    confirmed: number;
    booked: number;
}

/** GET /hospitality/reservations/quote. */
export interface ReservationQuote {
    base_price: { minor: number; currency: string };
    price_per_pax: { minor: number; currency: string };
    total: { minor: number; currency: string };
    rebate_percent: number | null;
    source: 'agency_price' | 'customer_rebate' | 'base_price';
    max_pax: number | null;
}

/** One program on the customer page's Hospitality tab: the price this customer pays, if any. */
export interface CustomerProgramPrice {
    program_id: string;
    program_name: string;
    is_active: boolean;
    base_price: MoneyValue;
    /** Null when this customer has no agency price and pays base (or their own rebate). */
    price_per_pax: MoneyValue | null;
    rebate_percent: number | null;
    effective_rebate_percent: number | null;
}

/** The customer page's Hospitality tab (CustomerHospitalityQuery). */
export interface CustomerHospitality {
    prices: CustomerProgramPrice[];
    reservations: ReservationRow[];
    reservation_count: number;
    revenue: MoneyValue;
}

/** GET /hospitality?tab=analytics — HospitalityAnalyticsQuery. */
export interface HospitalityAnalytics {
    year: number;
    years: number[];
    kpis: {
        reservations: number;
        revenue: MoneyValue;
        guests: number;
        revenue_per_guest: MoneyValue;
        upcoming: number;
        pipeline: MoneyValue;
        conversion_rate: number | null;
    };
    months: { month: number; revenue: MoneyValue; previous_revenue: MoneyValue; reservations: number; guests: number }[];
    programs: { program_id: string; name: string; reservations: number; guests: number; revenue: MoneyValue; revenue_per_guest: MoneyValue; share: number }[];
    top_agencies: { customer_id: string; name: string; reservations: number; guests: number; revenue: MoneyValue }[];
}

export interface AgencyPerformanceRow {
    customer_id: string;
    name: string;
    reservations: number;
    guests: number;
    revenue: MoneyValue;
    revenue_per_guest: MoneyValue;
    list_value: MoneyValue;
    discount: MoneyValue;
    discount_percent: number | null;
    previous_revenue: MoneyValue | null;
    previous_reservations: number | null;
    change_percent: number | null;
}

/** AgencyPerformanceQuery. */
export interface AgencyPerformance {
    from: string;
    to: string;
    previous_from: string | null;
    previous_to: string | null;
    rows: AgencyPerformanceRow[];
}
