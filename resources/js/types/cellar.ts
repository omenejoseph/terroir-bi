/**
 * Cellar shapes. Each interface names the PHP class it mirrors; quantities
 * cross the wire as decimal strings, money as integer minor units.
 */

export type CellarActivityType = 'ADDITION' | 'ANALYSIS' | 'TRANSFER' | 'PROCESS' | 'TOPPING' | 'VINEYARD';
export type CellarActivityStatus = 'PLANNED' | 'DONE' | 'SKIPPED';
export type SkipReason = 'not_needed' | 'other';
export type WineType = 'RED' | 'WHITE' | 'ROSE' | 'ORANGE' | 'SPARKLING' | 'DESSERT';
export type WineLotStatus = 'FERMENTING' | 'AGING' | 'READY' | 'BOTTLED' | 'BLENDED';
export type VesselType = 'BARREL' | 'BARRIQUE' | 'TANK' | 'VAT' | 'AMPHORA';
export type So2Status = 'critical' | 'low' | 'ok' | 'high' | 'no_data';

/** Analysis parameters, App\Models\CellarAnalysis::PARAMETERS. */
export type AnalysisParam =
    | 'ph'
    | 'total_acidity'
    | 'volatile_acidity'
    | 'alcohol'
    | 'residual_sugar'
    | 'free_so2'
    | 'total_so2'
    | 'brix'
    | 'temperature'
    | 'density'
    | 'malic'
    | 'lactic'
    | 'tpi'
    | 'glucose_fructose';

/** The payload of a planned activity, per type (App\Services\Cellar\ActivityPayload). */
export interface ActivityPayload {
    product_name?: string;
    enological_product_id?: string | null;
    category?: string | null;
    unit?: string;
    quantity?: number | string;
    cost_per_unit?: number | null;
    dose_per_hl?: number | string | null;
    target_note?: string | null;
    metrics?: AnalysisParam[];
    from_vessel_id?: string;
    to_vessel_id?: string;
    liters?: number | string;
    source_vessel_id?: string | null;
    reason?: string | null;
    kind?: string;
    parcel_id?: string | null;
    parcel_name?: string | null;
    note?: string | null;
    applied?: { quantity?: number | string; liters?: number | string; values?: Partial<Record<AnalysisParam, number>> } | null;
}

/** App\DataTransferObjects\CellarActivityData */
export interface CellarActivity {
    id: string;
    type: CellarActivityType;
    status: CellarActivityStatus;
    payload: ActivityPayload;
    planned_for: string;
    overdue: boolean;
    sort_order: number;
    note: string | null;
    skip_reason: SkipReason | null;
    deviated: boolean;
    applied_at: string | null;
    skipped_at: string | null;
    wine_lot: { id: string; lot_number: string; name: string; vintage: string | null; grape_variety: string | null } | null;
    vessel: { id: string; name: string; room: string | null; current_volume: string } | null;
    round: { id: string; name: string } | null;
    assigned_to: { id: string; name: string } | null;
    executed_by: { id: string; name: string } | null;
}

export interface RoundProgress {
    id: string;
    name: string;
    total: number;
    done: number;
    skipped: number;
}

/** App\Queries\CellarTodayQuery */
export interface CellarToday {
    today: string;
    open: { room: string; items: CellarActivity[] }[];
    completed: CellarActivity[];
    rounds: RoundProgress[];
    counts: { open: number; mine: number; done_today: number };
}

export interface PlanRound {
    id: string;
    name: string | null;
    done: number;
    skipped: number;
    deviated: number;
    executed_by: string[];
    items: CellarActivity[];
}

export interface PlanDay {
    date: string;
    rounds: PlanRound[];
    singles: CellarActivity[];
}

/** App\Queries\CellarPlanQuery */
export interface CellarPlan {
    today: string;
    open: PlanDay[];
    executed: PlanDay[];
}

/** App\Queries\CellarPickerOptions::lots() */
export interface LotOption {
    id: string;
    lot_number: string;
    name: string;
    vintage: string | null;
    grape_variety: string | null;
    wine_type: WineType | null;
    status: WineLotStatus;
    current_volume: string;
    vessels: { id: string; name: string; room: string | null; volume: string }[];
}

/** App\DataTransferObjects\VesselData (with lots) */
export interface VesselLotContent {
    vessel_lot_id: string;
    wine_lot_id: string;
    volume: string;
    lot: {
        id: string;
        lot_number: string;
        name: string;
        grape_variety: string | null;
        vintage: string | null;
        wine_type: WineType | null;
        status?: WineLotStatus;
        free_so2: string | null;
        total_so2: string | null;
    } | null;
}

export interface Vessel {
    id: string;
    name: string;
    type: VesselType;
    material: string | null;
    capacity_liters: string;
    current_volume: string;
    location: string | null;
    status: string;
    is_active: boolean;
    is_faulty: boolean;
    fault_note: string | null;
    room: string | null;
    position_x: number | null;
    position_y: number | null;
    map_width: number | null;
    map_height: number | null;
    rotation: number | null;
    notes: string | null;
    lots?: VesselLotContent[];
}

/** App\Queries\CellarPickerOptions::products() */
export interface ProductOption {
    id: string;
    name: string;
    category: string | null;
    unit: string;
    current_stock: string;
    cost_per_unit: number | null;
    so2_uplift_per_unit: string | null;
}

export interface MemberOption {
    value: string;
    label: string;
}

export interface ParcelOption {
    id: string;
    name: string;
    grape_variety: string | null;
    area_hectares: string | null;
}

/** One reading block on the SO₂ board (lot or vessel). */
export interface So2Reading {
    free_so2: number | null;
    total_so2: number | null;
    reading_date: string | null;
    days_since: number | null;
    so2_status: So2Status;
    molecular: number | null;
}

export interface So2Vessel extends So2Reading {
    id: string;
    name: string;
    type: VesselType;
    material: string | null;
    room: string | null;
    oak: boolean;
    volume: string;
    reference_target: number;
    inherited: boolean;
}

export interface So2Lot extends So2Reading {
    id: string;
    lot_number: string;
    name: string;
    grape_variety: string | null;
    vintage: string | null;
    wine_type: WineType | null;
    status: WineLotStatus;
    current_volume: string;
    ph: number | null;
    residual_sugar: number | null;
    reference_target: number;
    compliance: { limit: number; compliant: boolean; margin: number } | null;
    vessels: So2Vessel[];
}

export interface So2Product {
    id: string;
    name: string;
    category: string | null;
    unit: string;
    current_stock: string;
    cost_per_unit: { minor: number; currency: string; formatted: string } | null;
    so2_uplift_per_unit: string | null;
}

/** App\Queries\CellarSo2BoardQuery */
export interface So2Board {
    lots: So2Lot[];
    summary: Record<So2Status, number> & { total: number };
    products: So2Product[];
}

/** App\Services\Cellar\So2DosePlanner::plan() */
export interface So2DoseLine {
    wine_lot_id: string;
    lot_number: string;
    lot_name: string;
    vessel_id: string | null;
    vessel_name: string | null;
    volume: string;
    current_free_so2: number | null;
    add_mg_l: number;
    target_mg_l: number;
    quantity: string;
    unit: string;
    cost: number | null;
    note: string;
}

export interface So2DosePlan {
    product: { id: string; name: string; unit: string; current_stock: string; method: string | null };
    lines: So2DoseLine[];
    totals: { quantity: string; cost: number | null; lines: number; stock_after: string; short_by: string | null };
}
