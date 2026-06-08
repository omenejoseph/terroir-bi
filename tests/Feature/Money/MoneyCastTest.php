<?php

declare(strict_types=1);

namespace Tests\Feature\Money;

use App\Models\Plan;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MoneyCastTest extends TestCase
{
    use RefreshDatabase;

    public function test_money_cast_round_trips_through_the_database(): void
    {
        $plan = Plan::create([
            'name' => 'Pro',
            'slug' => 'pro',
            'price_minor' => Money::fromMajor('19.99', 'USD'),
        ]);

        $fresh = $plan->fresh();

        $this->assertInstanceOf(Money::class, $fresh->price_minor);
        $this->assertSame(1999, $fresh->price_minor->getMinorAmount());
        $this->assertSame('USD', $fresh->price_minor->getCurrencyCode());
    }

    public function test_money_cast_persists_the_currency_column(): void
    {
        $plan = Plan::create([
            'name' => 'Basic',
            'slug' => 'basic',
            'price_minor' => Money::fromMajor('9.50', 'EUR'),
        ]);

        $this->assertDatabaseHas('plans', [
            'slug' => 'basic',
            'price_minor' => 950,
            'currency' => 'EUR',
        ]);
    }

    public function test_null_money_is_supported(): void
    {
        $plan = Plan::create(['name' => 'Free', 'slug' => 'free']);

        $this->assertNull($plan->fresh()->price_minor);
    }
}
