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
        ph?: string | null;
        alcohol?: string | null;
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
/**
 * What a record panel needs to know about a lot — the lot page's WineLotDetail
 * satisfies it, and so does a picker option mapped by the Record drawer.
 */
export interface LotRef {
    id: string;
    name: string;
    vintage: string | null;
    wine_type?: string | null;
    current_volume: string;
    vessels: { vessel_id: string; vessel_name: string | null; volume: string }[];
}

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
    /** The lot's vessels this event touched (empty = the whole lot). */
    vessel_ids: string[];
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
    mlf: boolean;
    description: string | null;
    estimated_duration: number | null;
    /** Legacy-imported stages use camelCase day keys; the editor writes snake_case. */
    stages: { name: string; day_start?: number; day_end?: number; dayStart?: number; dayEnd?: number; actions?: { type?: string; description?: string }[] }[];
    lots_in_use?: number;
    is_active: boolean;
    additives: { id: string; name: string; enological_product_id: string | null; dose: string; basis: string; stage: string | null; notes: string | null }[];
}

/** App\DataTransferObjects\TastingReportData */
export interface TastingReportWine {
    note_id: string;
    wine_lot: { id: string; lot_number: string; name: string; vintage: string | null } | null;
    vessel: { id: string; name: string } | null;
    score: number | null;
    appearance: string | null;
    nose: string | null;
    palate: string | null;
    overall: string | null;
    note: string | null;
}

export interface TastingReport {
    id: string;
    title: string | null;
    date: string;
    note: string | null;
    author: { id: string; name: string } | null;
    wine_count: number;
    average_score: number | null;
    wines_preview: string[];
    wines?: TastingReportWine[];
}

/** App\DataTransferObjects\EnologicalProductData */
export interface EnologicalProduct {
    id: string;
    name: string;
    category: string | null;
    unit: string;
    current_stock: string;
    min_stock: string | null;
    cost_per_unit: MoneyValue | null;
    manufacturer: string | null;
    packaging_size: string | null;
    so2_uplift_per_unit: string | null;
    supplier_id: string | null;
    is_active: boolean;
    notes: string | null;
}

/** App\Queries\ProtocolNeedsQuery */
export interface ProtocolNeed {
    enological_product_id: string | null;
    product_name: string;
    unit: string;
    current_stock: string;
    cost_per_unit: number | null;
    supplier: { id: string; name: string } | null;
    total_needed: number;
    deficit: number;
    lots: { id?: string; name?: string; needed?: number }[];
}

/** App\Queries\FermentationMonitorQuery */
export interface FermentationAlert {
    type: string;
    severity: string;
    lot_id: string;
    lot_name: string;
    message: string;
    suggestion: string;
}

/** App\Queries\CellarAnalyticsQuery */
export interface CellarAnalytics {
    kpis: {
        wine_in_cellar: number;
        ready_to_bottle: number;
        estimated_bottles: number;
        average_cost_per_liter: number | null;
        overdue_tasks: number;
        needs_so2: number;
        needs_analysis: number;
        vessel_utilisation: number | null;
        empty_vessels: number;
    };
    by_vintage: { label: string; volume: number }[];
    by_type: { label: string; volume: number }[];
    by_grape: { label: string; volume: number }[];
    pipeline: { status: string; count: number; volume: number }[];
    timeline: { month: string; in: number; out: number }[];
    health: {
        wine_lot_id: string;
        lot_number: string;
        name: string;
        volatile_acidity: number | null;
        free_so2: number | null;
        analysis_age_days: number | null;
        so2_age_days: number | null;
        flags: string[];
        level: 'red' | 'amber' | 'green';
    }[];
    alerts: FermentationAlert[];
}

/** App\Queries\CellarCostsQuery (detailed) */
export interface LotCostRow {
    id: string;
    lot_number: string;
    name: string;
    vintage: string | null;
    current_volume: string;
    cost: { total: number; additions: number; grape: number; linked: number; carried: number; per_liter: number; per_bottle_750: number };
    grape_variety?: string;
    status?: string;
    initial_volume?: string;
    selling_price?: number | null;
    bottle_volume_ml?: number | null;
    grapes?: { grape_variety: string; weight_kg: string | null; price_per_kg: number | null }[];
    additions?: { name: string; quantity: string; unit: string; total_cost: number | null }[];
    linked?: { description: string | null; total: number }[];
}

/** App\Queries\CellarBookQuery */
export interface CellarBookRow {
    date: string;
    lot_number: string;
    lot_name: string;
    vintage: string;
    event: string;
    vessel: string | null;
    quantity: string | null;
    unit: string | null;
    detail: string | null;
    by: string | null;
}

/** App\Support\Cellar\CellarPreselect — what a page starts with selected (from the query string). */
export interface CellarPreselect {
    vessels: string[];
    lots: string[];
    type: string | null;
}

/** App\Queries\CellarAttentionQuery — something that needs someone, with the action that deals with it. */
export interface AttentionAction {
    kind: 'plan_reading' | 'plan_so2' | 'process_task' | 'open_lot' | 'open_today' | string;
    label: string;
    href: string | null;
    post: { url: string; data: Record<string, unknown> } | null;
}
export interface CellarAttentionItem {
    key: string;
    lot_id: string;
    lot_name: string;
    severity: 'critical' | 'high' | 'medium';
    message: string;
    suggestion: string | null;
    action: AttentionAction;
}

/* ---- Lot page (tabs, scope, barrels) — App\Queries\LotBarrelsQuery / LotTablesQuery ---- */

export type LotTab = 'overview' | 'barrels' | 'analysis' | 'additions' | 'tasting' | 'transfers' | 'processes' | 'details';

export interface LotScopeInfo {
    /** The barrel in view, or null for the whole lot. */
    vessel_id: string | null;
    tab: LotTab;
    /** The active journey filter chip. */
    kind: string | null;
}

export interface BarrelReading {
    value: number;
    date: string;
    /** false when no reading was taken in this barrel and the lot-level one is shown. */
    in_vessel: boolean;
}

export interface BarrelRow {
    vessel_id: string;
    name: string;
    type: VesselType;
    material: string | null;
    room: string | null;
    location: string | null;
    capacity_liters: string;
    /** Litres of THIS lot in the barrel. */
    volume: string;
    fill_percent: number;
    is_faulty: boolean;
    /** Other wine in the same barrel. */
    others: { lot_id: string; name: string; vintage: string | null; volume: string }[];
    readings: Partial<Record<'free_so2' | 'total_so2' | 'ph' | 'alcohol' | 'volatile_acidity' | 'total_acidity' | 'temperature', BarrelReading | null>>;
}

export interface BarrelsCombined {
    barrels: number;
    volume: string;
    capacity: string;
    parameters: Record<string, { average: number | null; min: number | null; max: number | null; barrels_counted: number }>;
}

export interface LotCounts {
    barrels: number;
    analysis: number;
    additions: number;
    tasting: number;
    transfers: number;
    processes: number;
}

export interface LotTransferRow {
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
}

export interface LotBottlingRow {
    id: string;
    bottle_count: number;
    bottle_volume_ml: number;
    volume_used: string;
    inventory_item_id: string | null;
    inventory_item_name: string | null;
    note: string | null;
    date: string;
}
