/**
 * The SO₂ correction list as text, for Copy and WhatsApp. Same layout as the
 * prototype (WhatsApp bold with *…*), but every word goes through `t()` — the
 * prototype hard-coded the Croatian — and the arrow shows the level the wine
 * will reach (current + addition); the prototype printed the addition there.
 */

import type { So2DosePlan } from '@/types/cellar';
import { qty } from '@/lib/cellar';

type T = (key: string, replace?: Record<string, string | number>) => string;

export function so2ShareText(plan: So2DosePlan, t: T, date: string, note: string): string {
    const lines: string[] = [];
    lines.push(`*${t('SO₂ CORRECTION')} — ${date}*`);
    lines.push(`${t('Product')}: ${plan.product.name}`);
    lines.push('');

    plan.lines.forEach((l, i) => {
        lines.push(`${i + 1}. *${l.lot_name}${l.vessel_name ? ` → ${l.vessel_name}` : ''}* — ${qty(l.volume)} L`);
        lines.push(`   SO₂: ${l.current_free_so2 ?? '?'} → ${qty(l.target_mg_l)} mg/L (+${qty(l.add_mg_l)})`);
        lines.push(`   *${t('Add')} ${qty(l.quantity)} ${l.unit}*`);
        lines.push('');
    });

    lines.push(`*${t('Total')}: ${qty(plan.totals.quantity)} ${plan.lines[0]?.unit ?? plan.product.unit} ${plan.product.name} — ${t(':n items', { n: plan.lines.length })}*`);
    if (note.trim() !== '') lines.push(`${t('Note')}: ${note.trim()}`);

    return lines.join('\n');
}

/** Clipboard API, falling back to a hidden textarea for older mobile browsers. */
export async function copyText(text: string): Promise<boolean> {
    try {
        await navigator.clipboard.writeText(text);

        return true;
    } catch {
        const area = document.createElement('textarea');
        area.value = text;
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        const ok = document.execCommand('copy');
        document.body.removeChild(area);

        return ok;
    }
}
