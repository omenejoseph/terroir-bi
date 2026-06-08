<?php

declare(strict_types=1);

namespace Tests\Unit\Money;

use App\Support\Money\CurrencyRegistry;
use App\Support\Money\Exceptions\CurrencyMismatchException;
use App\Support\Money\Exceptions\UnsupportedCurrencyException;
use App\Support\Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MoneyTest extends TestCase
{
    #[DataProvider('majorToMinorProvider')]
    public function test_from_major_converts_and_rounds(string $major, int $expectedMinor): void
    {
        $this->assertSame($expectedMinor, Money::fromMajor($major, 'EUR')->getMinorAmount());
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function majorToMinorProvider(): array
    {
        return [
            'whole' => ['12', 1200],
            'two dp' => ['12.34', 1234],
            'round half up' => ['12.345', 1235],
            'round down' => ['12.344', 1234],
            'one dp' => ['0.1', 10],
            'sub-cent rounds up' => ['0.005', 1],
            'sub-cent rounds down' => ['0.004', 0],
            'negative half up' => ['-12.345', -1235],
        ];
    }

    public function test_to_major_renders_canonical_decimal_string(): void
    {
        $this->assertSame('12.34', Money::fromMinor(1234, 'EUR')->toMajor());
        $this->assertSame('-0.50', Money::fromMinor(-50, 'EUR')->toMajor());
        $this->assertSame('0.00', Money::zero('EUR')->toMajor());
    }

    public function test_round_trip_is_stable(): void
    {
        $money = Money::fromMajor('1234.56', 'EUR');

        $this->assertSame(123456, $money->getMinorAmount());
        $this->assertSame('1234.56', $money->toMajor());
    }

    public function test_addition_and_subtraction_stay_in_minor_units(): void
    {
        $sum = Money::fromMinor(1000, 'EUR')->plus(Money::fromMinor(234, 'EUR'));
        $this->assertSame(1234, $sum->getMinorAmount());

        $diff = Money::fromMinor(1000, 'EUR')->minus(Money::fromMinor(250, 'EUR'));
        $this->assertSame(750, $diff->getMinorAmount());
    }

    public function test_cross_currency_arithmetic_throws(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        Money::fromMinor(100, 'EUR')->plus(Money::fromMinor(100, 'USD'));
    }

    public function test_unsupported_currency_throws(): void
    {
        $this->expectException(UnsupportedCurrencyException::class);

        Money::fromMinor(100, 'XXX');
    }

    public function test_default_currency_resolves_from_config(): void
    {
        $this->assertSame('EUR', CurrencyRegistry::default()->code);
    }

    public function test_json_serialization_shape(): void
    {
        $this->assertSame(
            ['minor' => 1234, 'currency' => 'EUR', 'formatted' => '12.34'],
            Money::fromMinor(1234, 'EUR')->jsonSerialize(),
        );
    }
}
