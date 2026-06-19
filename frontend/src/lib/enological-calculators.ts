/**
 * Enological calculators — pure functions, no imports or side effects.
 * Ported from the order-mgmt prototype (`src/lib/enological-calculators.ts`)
 * so the cellar screens share the same dosing/yield math.
 */

/**
 * SO₂ correction via K₂S₂O₅ (potassium metabisulfite).
 *   molecular SO₂ = freeSO₂ / (1 + 10^(pH − 1.81))
 *   grams K₂S₂O₅  = (targetFree − currentFree) × volume / 1000 × 1.5
 * The 1.5 factor converts K₂S₂O₅ mass to its SO₂ yield.
 */
export function calculateSO2Addition(params: {
  volumeLiters: number;
  currentFreeSO2: number; // mg/L
  targetFreeSO2: number; // mg/L
  pH: number;
}): { gramsK2S2O5: number; molecularSO2: number; adjustedMolecular: number } {
  const molecularSO2 = params.currentFreeSO2 / (1 + Math.pow(10, params.pH - 1.81));
  const deficitMgL = Math.max(0, params.targetFreeSO2 - params.currentFreeSO2);
  const gramsK2S2O5 = ((deficitMgL * params.volumeLiters) / 1000) * 1.5;
  const adjustedMolecular = params.targetFreeSO2 / (1 + Math.pow(10, params.pH - 1.81));
  return {
    gramsK2S2O5: Math.round(gramsK2S2O5 * 10) / 10,
    molecularSO2: Math.round(molecularSO2 * 100) / 100,
    adjustedMolecular: Math.round(adjustedMolecular * 100) / 100,
  };
}

/**
 * Generic SO₂ dose for a product whose `so2UpliftPerUnit` coefficient is known
 * (mg/L of free SO₂ added per 1 unit of product per 1 L of wine, in the
 * product's native unit).
 *
 *   dose = (targetFree − currentFree) × volume / so2UpliftPerUnit
 *
 * Returns null when no coefficient is set — the caller should fall back to the
 * K₂S₂O₅ path (`calculateSO2Addition`).
 */
export function calculateSO2DoseByUplift(params: {
  volumeLiters: number;
  currentFreeSO2: number;
  targetFreeSO2: number;
  so2UpliftPerUnit: number | null | undefined;
}): { dose: number } | null {
  const uplift = params.so2UpliftPerUnit;
  if (!uplift || uplift <= 0) return null;
  const deficitMgL = Math.max(0, params.targetFreeSO2 - params.currentFreeSO2);
  const dose = (deficitMgL * params.volumeLiters) / uplift;
  return { dose: Math.round(dose * 100) / 100 };
}

/**
 * Tartaric acid addition to raise titratable acidity.
 *   grams = (targetTA − currentTA) × volume × 0.67
 * The 0.67 factor accounts for buffering — not all added acid ends up as TA.
 */
export function calculateAcidAddition(params: {
  volumeLiters: number;
  currentTA: number; // g/L
  targetTA: number; // g/L
}): { gramsTartaricAcid: number } {
  const deficitGL = Math.max(0, params.targetTA - params.currentTA);
  const grams = deficitGL * params.volumeLiters * 0.67;
  return { gramsTartaricAcid: Math.round(grams * 10) / 10 };
}

/**
 * General additive dose.
 *   grams = dose(mg/L) × volume / 1000, divided by product concentration.
 */
export function calculateAddition(params: {
  volumeLiters: number;
  targetDoseMgL: number; // mg/L
  productConcentration?: number; // 0–100%, default 100
}): { gramsNeeded: number; mlNeeded?: number } {
  const concentration = (params.productConcentration || 100) / 100;
  const gramsNeeded = (params.targetDoseMgL * params.volumeLiters) / 1000 / concentration;
  return {
    gramsNeeded: Math.round(gramsNeeded * 10) / 10,
    mlNeeded: Math.round(gramsNeeded * 10) / 10, // approximation for liquid products
  };
}

/**
 * Chaptalization: sugar needed to raise Brix (~0.06 Brix per g/L sugar) and the
 * rough potential-alcohol increase (~0.55% per Brix).
 */
export function calculateChaptalization(params: {
  volumeLiters: number;
  currentBrix: number;
  targetBrix: number;
}): { kgSugar: number; potentialAlcoholIncrease: number } {
  const brixDiff = Math.max(0, params.targetBrix - params.currentBrix);
  const sugarGL = brixDiff / 0.06; // g/L
  const kgSugar = (sugarGL * params.volumeLiters) / 1000;
  const alcoholIncrease = brixDiff * 0.55;
  return {
    kgSugar: Math.round(kgSugar * 100) / 100,
    potentialAlcoholIncrease: Math.round(alcoholIncrease * 10) / 10,
  };
}

/**
 * Press yield analysis from total grape weight, an expected yield ratio, and
 * the measured fraction volumes.
 */
export function calculatePressYield(params: {
  totalGrapeKg: number;
  yieldRatio: number; // e.g. 0.65
  fractions: { type: string; volumeLiters: number }[];
}): {
  totalJuiceLiters: number;
  expectedLiters: number;
  overallYield: number;
  fractionYields: { type: string; volumeLiters: number; percent: number }[];
} {
  const expectedLiters = params.totalGrapeKg * params.yieldRatio;
  const totalJuiceLiters = params.fractions.reduce((s, f) => s + f.volumeLiters, 0);
  const overallYield = params.totalGrapeKg > 0 ? (totalJuiceLiters / params.totalGrapeKg) * 100 : 0;
  const fractionYields = params.fractions.map((f) => ({
    type: f.type,
    volumeLiters: f.volumeLiters,
    percent: totalJuiceLiters > 0 ? Math.round((f.volumeLiters / totalJuiceLiters) * 1000) / 10 : 0,
  }));
  return {
    totalJuiceLiters: Math.round(totalJuiceLiters * 10) / 10,
    expectedLiters: Math.round(expectedLiters * 10) / 10,
    overallYield: Math.round(overallYield * 10) / 10,
    fractionYields,
  };
}

/**
 * EU total-SO₂ legal limit check (mg/L) by wine type and residual sugar.
 */
export function checkSO2Compliance(params: {
  totalSO2: number; // mg/L
  wineType: string; // RED, WHITE, ROSE/ROSÉ, ORANGE, SPARKLING
  residualSugar: number; // g/L
}): { limit: number; isCompliant: boolean; margin: number } {
  let limit = 200; // default for dry white/rosé
  if (params.wineType === "RED") {
    limit = params.residualSugar > 5 ? 200 : 150; // dry red = 150, sweet red = 200
  } else if (["WHITE", "ROSÉ", "ROSE", "ORANGE"].includes(params.wineType)) {
    limit = params.residualSugar > 5 ? 250 : 200; // dry white = 200, sweet white = 250
  } else if (params.wineType === "SPARKLING") {
    limit = 185;
  }
  return {
    limit,
    isCompliant: params.totalSO2 <= limit,
    margin: Math.round((limit - params.totalSO2) * 10) / 10,
  };
}
