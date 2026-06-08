<?php

declare(strict_types=1);

namespace App\Support\Money;

use NumberFormatter;
use RuntimeException;

/**
 * Locale-aware money formatting via ext-intl.
 *
 * Formatting (decimal/grouping separators, symbol placement) is driven by the
 * LOCALE, not just the currency. The same EUR amount renders differently for a
 * Croatian user ("1.234,56 €") than an Irish one ("€1,234.56"). The currency's
 * minorUnit determines the number of fraction digits.
 *
 * This is deliberately separate from Money::toMajor(), which returns the
 * canonical machine string ("1234.56") used for storage, APIs, and arithmetic.
 */
class MoneyFormatter
{
    /**
     * Format money for display in the given locale (defaults to the active app
     * locale, which the SetLocale middleware sets per tenant/request).
     *
     * e.g. format(Money::fromMinor(123456, 'EUR'), 'hr') => "1.234,56 €"
     */
    public function format(Money $money, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);
        $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, $money->getCurrency()->minorUnit);

        $formatted = $formatter->formatCurrency(
            (float) $money->toMajor(),
            $money->getCurrencyCode(),
        );

        if ($formatted === false) {
            throw new RuntimeException("Failed to format money for locale [{$locale}].");
        }

        return $formatted;
    }

    /** Format just the number (no currency symbol) for the given locale. */
    public function formatDecimal(Money $money, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        $formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $money->getCurrency()->minorUnit);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $money->getCurrency()->minorUnit);

        $formatted = $formatter->format((float) $money->toMajor());

        if ($formatted === false) {
            throw new RuntimeException("Failed to format money for locale [{$locale}].");
        }

        return $formatted;
    }
}
