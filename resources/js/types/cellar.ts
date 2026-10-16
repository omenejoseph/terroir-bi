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
    unallocated: string;
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

/** A vessel's content on the map, with the SO₂ the tile shows (App\Queries\CellarMapQuery). */
export interface MapLot extends VesselLotContent {
    so2?: {
        free: number | null;
        total: number | null;
        date: string | null;
        in_vessel: boolean;
        projected: number | null;
        latest_addition: { name: string; quantity: string; unit: string } | null;
    };
}

/** App\Queries\CellarMapQuery */
export interface MapVessel extends Omit<Vessel, 'lots'> {
    lots?: MapLot[];
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

/** The fields LotFormPanel edits — a subset of WineLotData. */
export interface LotFormValue {
    id: string;
    name: string;
    vintage: string | null;
    vineyard: string | null;
    wine_type: string | null;
    status: string;
    harvest_weight_kg: string | null;
    grape_price_per_kg: { minor: number } | null;
    notes: string | null;
    grapes?: { grape_variety: string; percentage: string | null; weight_kg: string | null; price_per_kg: { minor: number } | null }[];
    grape_variety: string;
}


export interface MoneyValue {
    minor: number;
    currency: string;
    formatted: string;
}

/** App\DataTransferObjects\WineLotData (list form). Money fields are null without finance.view. */
export interface WineLotSummary {
    id: string;
    lot_number: string;
    name: string;
    grape_variety: string;
    vintage: string | null;
    vineyard: string | null;
    wine_type: WineType | null;
    initial_volume: string;
    current_volume: string;
    status: WineLotStatus;
    fermentation_template_id: string | null;
    grape_cost: MoneyValue | null;
    grape_price_per_kg: MoneyValue | null;
    harvest_weight_kg: string | null;
    notes: string | null;
    latest_analysis: { date: string; ph: string | null; alcohol: string | null; free_so2: string | null; total_so2: string | null } | null;
    latest_addition: { name: string; quantity: string; unit: string; date: string | null } | null;
    carried_cost: number | null;
    counts?: { additions: number; analyses: number; bottlings: number };
    latest_bottling?: { date: string; bottle_count: number; bottle_volume_ml: number; inventory_item: { id: string; name: string } | null } | null;
}

export type AnalysisRow = { id: string; vessel_id: string | null; vessel_name?: string | null; date: string; note: string | null } & Partial<Record<AnalysisParam, string | null>>;

/** App\DataTransferObjects\WineLotData (detail form). */
export interface WineLotDetail extends WineLotSummary {
    cost_breakdown: { total: number; additions: number; grape: number; linked: number; carried: number; per_liter: number; per_bottle_750: number } | null;
    grapes: { id: string; grape_variety: string; percentage: string | null; price_per_kg: MoneyValue | null; weight_kg: string | null }[];
    vessels: { vessel_lot_id: string; vessel_id: string; vessel_name: string | null; volume: string }[];
    analyses: AnalysisRow[];
    additions: {
        id: string;
        name: string;
        category: string | null;
        enological_product_id: string | null;
        vessel_id: string | null;
        vessel: { id: string; name: string } | null;
        quantity: string;
        unit: string;
        total_cost: MoneyValue | null;
        note: string | null;
        date: string | null;
    }[];
    processes: { id: string; kind: string; vessel_id: string | null; vessel_name: string | null; volume: string | null; note: string | null; date: string }[];
    tasting_notes: {
        id: string;
        vessel_id: string | null;
        date: string;
        appearance: string | null;
        nose: string | null;
        palate: string | null;
        overall: string | null;
        score: number | null;
        note: string | null;
    }[];
    transfers: {
        id: string;
        type: string;
        direction: 'in' | 'out';
        from_lot_id: string;
        to_lot_id: string;
        from_lot_name: string | null;
        to_lot_name: string | null;
        from_vessel_name: string | null;
        to_vessel_name: string | null;
        volume_liters: string;
        note: string | null;
        date: string | null;
    }[];
    planned_additions: { id: string; planned_for: string; product_name: string | null; quantity: string | null; unit: string | null; vessel: { id: string; name: string } | null; round_id: string | null }[];
    bottlings: { id: string; bottle_count: number; bottle_volume_ml: number; volume_used: string; inventory_item_id: string | null; inventory_item_name: string | null; note: string | null; date: string }[];
}

/** App\Queries\LotTimelineQuery */
export interface TimelineEvent {
    type: string;
    date: string | null;
    title: string;
    detail: string | null;
    ref_id: string | null;
}

/** App\Queries\CellarHistoryQuery */
export interface HistoryEntry {
    id: string;
    action: string;
    actor_name: string | null;
    subject_type: string;
    subject_id: string | null;
    metadata: Record<string, unknown> | null;
    created_at: string | null;
}

/** App\Queries\TraceabilityQuery */
export interface TraceNode {
    id: string;
    lot_number: string;
    name: string;
    vintage: string | null;
    harvest: { id: string; date: string | null; grape_variety: string | null; parcel: { id: string; name: string } | null; grower: string | null; kg: string | null }[];
    blended_from: { volume: string; lot: TraceNode | null }[];
    vessels: { id: string; name: string }[];
    bottlings: { id: string; date: string; bottles: number; bottle_volume_ml: number; item: { id: string; name: string; sku: string | null } | null }[];
}

/** App\DataTransferObjects\FermentationTemplateData */
export interface FermentationTemplate {
    id: string;
    name: string;
    wine_type: string | null;
    yeast_strain: string | null;
    target_temp_min: string | null;
    target_temp_max: string | null;
    punchdown_schedule: string | null;
    maceration: string | null;
    nutrients: string | null;
    mlf: string | null;
    description: string | null;
    estimated_duration: number | null;
    stages: { name: string; day_start?: number; day_end?: number; actions?: string[] }[];
    is_active: boolean;
    additives: { id: string; name: string; enological_product_id: string | null; dose: string; basis: string; stage: string | null; notes: string | null }[];
}
