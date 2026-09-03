<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The self-service order page's Inertia shell (Web\PublicOrderController).
 * The actual catalog/order logic is fetched client-side against the public
 * JSON API, which tests/Feature/Orders/PublicOrderTest already covers in
 * full — this only asserts the page renders, unauthenticated, for any token
 * shape, and shares nothing that assumes a signed-in member.
 */
class WebPublicOrderTest extends TestCase
{
    public function test_the_page_renders_for_a_guest_with_no_active_tenant(): void
    {
        $this->get('/order/some-token-value')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('PublicOrder')
                ->where('token', 'some-token-value')
                ->where('auth.user', null)
                ->where('tenant', null));
    }

    /** The route never validates the token itself — an unknown one still renders the shell. */
    public function test_the_page_renders_even_for_a_token_that_does_not_exist(): void
    {
        $this->get('/order/not-a-real-token')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('PublicOrder'));
    }
}
