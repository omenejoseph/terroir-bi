/**
 * Cellar vocabulary: labels (English source strings — translate with `t()` at
 * render), icons and tones shared by every cellar screen.
 */

import { ArrowRightLeft, Droplets, FlaskConical, Grape, PlusCircle, Wrench } from 'lucide-vue-next';

import type { ActivityPayload, AnalysisParam, CellarActivity, CellarActivityType, LotOption, LotRef, So2Status, WineType } from '@/types/cellar';

export const ACTIVITY_TYPES: CellarActivityType[] = ['ADDITION', 'ANALYSIS', 'TRANSFER', 'PROCESS', 'TOPPING', 'VINEYARD'];

export const ACTIVITY_LABELS: Record<CellarActivityType, string> = {
    ADDITION: 'Addition',
    ANALYSIS: 'Readings',
    TRANSFER: 'Transfer',
    PROCESS: 'Process',
    TOPPING: 'Topping',
    VINEYARD: 'Vineyard',
};

export const ACTIVITY_ICONS: Record<CellarActivityType, unknown> = {
    ADDITION: Droplets,
    ANALYSIS: FlaskConical,
    TRANSFER: ArrowRightLeft,
    PROCESS: Wrench,
    TOPPING: PlusCircle,
    VINEYARD: Grape,
};

export const SKIP_REASON_LABELS: Record<string, string> = {
    not_needed: 'Not needed',
    other: 'Other',
};

/** Short labels for the readings the team takes most, in the order the prototype offered them. */
export const METRIC_LABELS: Record<AnalysisParam, string> = {
    free_so2: 'Free SO₂',
    total_so2: 'Total SO₂',
    ph: 'pH',
    temperature: 'Temp °C',
    density: 'Density',
    residual_sugar: 'Sugar g/L',
    total_acidity: 'TA g/L',
    volatile_acidity: 'VA g/L',
    alcohol: 'Alc %',
    brix: 'Brix',
    malic: 'Malic g/L',
    lactic: 'Lactic g/L',
    tpi: 'TPI',
    glucose_fructose: 'Glu+Fru g/L',
};

/** The readings the planner offers as toggles (the prototype's six). */
export const PLANNER_METRICS: AnalysisParam[] = ['free_so2', 'total_so2', 'ph', 'temperature', 'density', 'residual_sugar'];

export const SO2_STATUS_LABELS: Record<So2Status, string> = {
    critical: 'Critical',
    low: 'Low',
    ok: 'OK',
    high: 'High',
    no_data: 'No data',
};

/** Text tone for an SO₂ status — the same thresholds colour every SO₂ figure. */
export const SO2_STATUS_TONES: Record<So2Status, string> = {
    critical: 'text-tag-red-foreground',
    low: 'text-tag-orange-foreground',
    ok: 'text-tag-green-foreground',
    high: 'text-tag-blue-foreground',
    no_data: 'text-muted-foreground',
};

export const SO2_STATUS_BG: Record<So2Status, string> = {
    critical: 'bg-tag-red text-tag-red-foreground',
    low: 'bg-tag-orange text-tag-orange-foreground',
    ok: 'bg-tag-green text-tag-green-foreground',
    high: 'bg-tag-blue text-tag-blue-foreground',
    no_data: 'bg-tag-slate text-tag-slate-foreground',
};

/** Mirrors So2Calculator::status(). */
export function so2Status(free: number | null): So2Status {
    if (free === null) return 'no_data';
    if (free < 15) return 'critical';
    if (free < 25) return 'low';
    if (free <= 45) return 'ok';

    return 'high';
}

/** A picker lot (CellarPickerOptions::lots) in the shape the record panels take. */
export function lotRefFromOption(l: LotOption): LotRef {
    return {
        id: l.id,
        name: l.name,
        vintage: l.vintage,
        wine_type: l.wine_type,
        current_volume: l.current_volume,
        vessels: l.vessels.map((v) => ({ vessel_id: v.id, vessel_name: v.name, volume: v.volume })),
    };
}

/** App\Services\Cellar\So2Calculator::referenceTarget — free SO₂ to aim for, by wine type; +3 in oak. */
export function so2ReferenceTarget(wineType: string | null | undefined, oak = false): number {
    const base = ({ RED: 28, WHITE: 35, ROSE: 32, ORANGE: 25 } as Record<string, number>)[wineType ?? ''] ?? 30;

    return oak ? base + 3 : base;
}

/** App\Services\Cellar\So2Calculator::molecular — the protective share of free SO₂ at a pH. */
export function molecularSo2(free: number | null, ph: number | null): number | null {
    if (free === null || ph === null) return null;

    return Math.round((free / (1 + 10 ** (ph - 1.81))) * 100) / 100;
}

export const WINE_TYPE_LABELS: Record<WineType, string> = {
    RED: 'Red',
    WHITE: 'White',
    ROSE: 'Rosé',
    ORANGE: 'Orange',
    SPARKLING: 'Sparkling',
    DESSERT: 'Dessert',
};

/** Swatch colour per wine type, for vessel tiles and lot chips. */
export const WINE_TYPE_SWATCH: Record<WineType, string> = {
    RED: '#7f1d1d',
    WHITE: '#eab308',
    ROSE: '#f472b6',
    ORANGE: '#ea580c',
    SPARKLING: '#a3e635',
    DESSERT: '#b45309',
};

export const LOT_STATUS_LABELS: Record<string, string> = {
    FERMENTING: 'Fermenting',
    AGING: 'Aging',
    READY: 'Ready',
    BOTTLED: 'Bottled',
    BLENDED: 'Blended',
};

export const LOT_STATUS_TONES: Record<string, string> = {
    FERMENTING: 'bg-tag-orange text-tag-orange-foreground',
    AGING: 'bg-tag-violet text-tag-violet-foreground',
    READY: 'bg-tag-green text-tag-green-foreground',
    BOTTLED: 'bg-tag-blue text-tag-blue-foreground',
    BLENDED: 'bg-tag-slate text-tag-slate-foreground',
};

/** A number as the cellar reads it: whole, or one decimal when it isn't. */
export function qty(value: number | string | null | undefined): string {
    if (value === null || value === undefined || value === '') return '—';
    const n = typeof value === 'number' ? value : Number.parseFloat(value);
    if (!Number.isFinite(n)) return String(value);

    return Number.isInteger(n) ? String(n) : (Math.round(n * 100) / 100).toString();
}

/**
 * Parse a number the way the cellar types it — "12,5" as well as "12.5".
 * Null when blank or not a number.
 */
export function parseDecimal(value: string | number | null | undefined): number | null {
    if (value === null || value === undefined) return null;
    const s = String(value).trim().replace(',', '.');
    if (s === '') return null;
    const n = Number(s);

    return Number.isFinite(n) ? n : null;
}

type T = (key: string, replace?: Record<string, string | number>) => string;

/** One line saying what a planned item asks for — the Today card and the plan lists. */
export function describeActivity(a: Pick<CellarActivity, 'type' | 'payload'>, t: T, vesselName?: (id: string) => string | undefined): string {
    const p: ActivityPayload = a.payload ?? {};

    switch (a.type) {
        case 'ADDITION': {
            const dose = p.dose_per_hl ? ` (${qty(p.dose_per_hl)} ${p.unit ?? 'g'}/hL)` : '';
            const target = p.target_note ? ` · ${p.target_note}` : '';

            return `${p.product_name ?? '—'} · ${qty(p.quantity)} ${p.unit ?? 'g'}${dose}${target}`;
        }
        case 'ANALYSIS':
            return (p.metrics ?? ['free_so2']).map((m) => t(METRIC_LABELS[m] ?? m)).join(' + ');
        case 'TRANSFER': {
            const to = p.to_vessel_id ? vesselName?.(p.to_vessel_id) : undefined;

            return `${qty(p.liters)} L${to ? ` → ${to}` : ''}`;
        }
        case 'PROCESS':
            return p.kind ?? '—';
        case 'TOPPING':
            return `+${qty(p.liters)} L`;
        case 'VINEYARD':
            return [p.kind, p.parcel_name].filter(Boolean).join(' · ');
    }
}

/** What the card is titled by: the vessel, else the parcel, else the lot. */
export function activityTitle(a: CellarActivity): string {
    return a.vessel?.name ?? a.payload?.parcel_name ?? a.wine_lot?.name ?? '—';
}

/** `YYYY-MM-DD` plus `days`, on the local calendar. */
export function addDays(iso: string, days: number): string {
    const [y, m, d] = iso.split('-').map(Number);
    const date = new Date(y!, (m ?? 1) - 1, d ?? 1);
    date.setDate(date.getDate() + days);
    const mm = `${date.getMonth() + 1}`.padStart(2, '0');
    const dd = `${date.getDate()}`.padStart(2, '0');

    return `${date.getFullYear()}-${mm}-${dd}`;
}

/** A day heading — "Thu 16 Oct" — in the viewer's locale. */
export function dayLabel(iso: string, locale: string): string {
    const [y, m, d] = iso.split('-').map(Number);

    return new Date(y!, (m ?? 1) - 1, d ?? 1).toLocaleDateString(locale, { weekday: 'short', day: 'numeric', month: 'short' });
}

/** Initials for an avatar chip. */
export function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0]!.toUpperCase())
        .join('');
}

export const VESSEL_TYPE_LABELS: Record<string, string> = {
    TANK: 'Tank',
    BARREL: 'Barrel',
    BARRIQUE: 'Barrique',
    VAT: 'Vat',
    AMPHORA: 'Amphora',
};

export const VESSEL_TYPE_OPTIONS = Object.entries(VESSEL_TYPE_LABELS).map(([value, label]) => ({ value, label }));

export const WINE_TYPE_OPTIONS = Object.entries(WINE_TYPE_LABELS).map(([value, label]) => ({ value, label }));

/** The analysis form's fields, in the prototype's order. */
export const ANALYSIS_FIELDS: { key: AnalysisParam; label: string }[] = [
    { key: 'free_so2', label: 'Free SO₂ (mg/L)' },
    { key: 'total_so2', label: 'Total SO₂ (mg/L)' },
    { key: 'ph', label: 'pH' },
    { key: 'alcohol', label: 'Alcohol (%)' },
    { key: 'total_acidity', label: 'Total acidity (g/L)' },
    { key: 'volatile_acidity', label: 'Volatile acidity (g/L)' },
    { key: 'residual_sugar', label: 'Residual sugar (g/L)' },
    { key: 'glucose_fructose', label: 'Glucose + fructose (g/L)' },
    { key: 'malic', label: 'Malic acid (g/L)' },
    { key: 'lactic', label: 'Lactic acid (g/L)' },
    { key: 'tpi', label: 'TPI' },
    { key: 'brix', label: 'Brix' },
    { key: 'temperature', label: 'Temperature (°C)' },
    { key: 'density', label: 'Density' },
];
