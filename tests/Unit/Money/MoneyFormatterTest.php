<?php

declare(strict_types=1);

namespace Tests\Unit\Money;

use App\Support\Money\Money;
use App\Support\Money\MoneyFormatter;
use Tests\TestCase;

class MoneyFormatterTest extends TestCase
{
    public function test_formatting_is_locale_aware(): void
    {
        $formatter = new MoneyFormatter;
        $money = Money::fromMinor(123456, 'EUR');

        // Croatian: dot thousands separator, comma decimal separator. intl uses
        // a non-breaking space (U+00A0) between the amount and the symbol.
        $this->assertSame("1.234,56\u{00A0}€", $formatter->format($money, 'hr'));

        // Same currency, different locale layout (symbol leads, no NBSP).
        $this->assertSame('€1,234.56', $formatter->format($money, 'en_IE'));
    }

    public function test_formatting_respects_currency_symbol(): void
    {
        $formatter = new MoneyFormatter;

        $this->assertSame('$1,234.56', $formatter->format(Money::fromMinor(123456, 'USD'), 'en_US'));
    }

    public function test_format_defaults_to_active_app_locale(): void
    {
        app()->setLocale('hr');
        $formatter = new MoneyFormatter;

        $this->assertSame("1.234,56\u{00A0}€", $formatter->format(Money::fromMinor(123456, 'EUR')));
    }
}
