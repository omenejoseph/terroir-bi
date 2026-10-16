import type { MoneyValue } from '@/types/inventory';
import type { StockAnalytics } from '@/types/stock';

/**
 * The per-bottle and whole-lot figures the product page shows in several cards (stock position,
 * Per bottle, Whole lot). Derived in one place so those cards cannot disagree.
 *
 * Everything is per bottle in integer minor units; a figure that needs an input the item does not
 * have (no cost, nothing sold yet) is null rather than a misleading zero.
 */
export interface StockEconomics {
    currency: string;
    stockBottles: number;
    listPerBottle: number | null;
    realisedPerBottle: number | null;
    costPerBottle: number | null;
    /** Stock at cost. */
    valueAtCost: number | null;
    /** Stock at the realised (mean) price. */
    salesValue: number | null;
    /** Sales value − value at cost. */
    potentialProfit: number | null;
    /** Potential profit as % of sales value. */
    potentialProfitPct: number | null;
    /** Realised margin per bottle, and as % of the realised price. */
    realisedMargin: number | null;
    realisedMarginPct: number | null;
    /** Mean rebate per bottle, and as % off list. */
    rebate: number | null;
    rebatePct: number | null;
    /** Margin if sold at list, per bottle and as % of list. */
    listMargin: number | null;
    listMarginPct: number | null;
    /** Cost as % of list price. */
    costPctOfList: number | null;
}

const pct = (part: number, whole: number): number | null => (whole > 0 ? (part / whole) * 100 : null);

export function stockEconomics(analytics: StockAnalytics): StockEconomics {
    const { current, realized } = analytics;

    const list = current.selling_per_bottle?.minor ?? null;
    const cost = current.cost_per_bottle?.minor ?? null;
    const realised = realized.mean_price?.minor ?? null;
    const stock = current.stock_bottles;

    const valueAtCost = cost !== null ? Math.round(cost * stock) : null;
    const salesValue = realised !== null ? Math.round(realised * stock) : null;
    const potentialProfit = salesValue !== null && valueAtCost !== null ? salesValue - valueAtCost : null;

    const realisedMargin = realised !== null && cost !== null ? realised - cost : null;
    const rebate = list !== null && realised !== null ? list - realised : null;
    const listMargin = list !== null && cost !== null ? list - cost : null;

    return {
        currency: current.cost_per_bottle?.currency ?? current.selling_per_bottle?.currency ?? realized.mean_price?.currency ?? 'EUR',
        stockBottles: stock,
        listPerBottle: list,
        realisedPerBottle: realised,
        costPerBottle: cost,
        valueAtCost,
        salesValue,
        potentialProfit,
        potentialProfitPct: potentialProfit !== null && salesValue !== null ? pct(potentialProfit, salesValue) : null,
        realisedMargin,
        realisedMarginPct: realisedMargin !== null && realised !== null ? pct(realisedMargin, realised) : null,
        rebate,
        rebatePct: rebate !== null && list !== null ? pct(rebate, list) : null,
        listMargin,
        listMarginPct: listMargin !== null && list !== null ? pct(listMargin, list) : null,
        costPctOfList: cost !== null && list !== null ? pct(cost, list) : null,
    };
}

/** A money figure from minor units, or null. */
export function moneyOf(minor: number | null, currency: string): MoneyValue | null {
    return minor === null ? null : { minor, currency, formatted: '' };
}
