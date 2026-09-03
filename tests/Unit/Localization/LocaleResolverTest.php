<?php

declare(strict_types=1);

namespace Tests\Unit\Localization;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Localization\LocaleResolver;
use Illuminate\Http\Request;
use Tests\TestCase;

class LocaleResolverTest extends TestCase
{
    private function tenant(string $locale = 'hr'): Tenant
    {
        // Unsaved model is enough for the resolver (reads default_locale).
        return new Tenant(['default_locale' => $locale]);
    }

    private function user(?string $locale): User
    {
        // Unsaved model is enough for the resolver (reads locale).
        return new User(['locale' => $locale]);
    }

    public function test_explicit_lang_query_wins(): void
    {
        $request = Request::create('/', 'GET', ['lang' => 'en']);

        $this->assertSame('en', (new LocaleResolver)->resolve($request, $this->tenant('hr')));
    }

    public function test_unsupported_lang_is_ignored_and_falls_back_to_tenant(): void
    {
        $request = Request::create('/', 'GET', ['lang' => 'de']);

        $this->assertSame('hr', (new LocaleResolver)->resolve($request, $this->tenant('hr')));
    }

    public function test_falls_back_to_tenant_default_when_no_request_signal(): void
    {
        $request = Request::create('/', 'GET');

        $this->assertSame('hr', (new LocaleResolver)->resolve($request, $this->tenant('hr')));
    }

    public function test_falls_back_to_app_locale_without_a_tenant(): void
    {
        config(['app.locale' => 'hr']);
        $request = Request::create('/', 'GET');

        $this->assertSame('hr', (new LocaleResolver)->resolve($request, null));
    }

    public function test_the_signed_in_users_personal_locale_wins_over_the_tenant_default(): void
    {
        $request = Request::create('/', 'GET');
        $request->setUserResolver(fn () => $this->user('en'));

        $this->assertSame('en', (new LocaleResolver)->resolve($request, $this->tenant('hr')));
    }

    public function test_a_user_with_no_personal_locale_falls_back_to_the_tenant_default(): void
    {
        $request = Request::create('/', 'GET');
        $request->setUserResolver(fn () => $this->user(null));

        $this->assertSame('hr', (new LocaleResolver)->resolve($request, $this->tenant('hr')));
    }

    public function test_query_or_header_still_wins_over_the_users_personal_locale(): void
    {
        $request = Request::create('/', 'GET', ['lang' => 'en']);
        $request->setUserResolver(fn () => $this->user('hr'));

        $this->assertSame('en', (new LocaleResolver)->resolve($request, $this->tenant('hr')));
    }

    public function test_a_guests_cookie_wins_over_the_tenant_default(): void
    {
        $request = Request::create('/', 'GET', cookies: ['terroir_locale' => 'en']);

        $this->assertSame('en', (new LocaleResolver)->resolve($request, $this->tenant('hr')));
    }

    public function test_the_signed_in_users_personal_locale_wins_over_a_stale_cookie(): void
    {
        $request = Request::create('/', 'GET', cookies: ['terroir_locale' => 'en']);
        $request->setUserResolver(fn () => $this->user('hr'));

        $this->assertSame('hr', (new LocaleResolver)->resolve($request, $this->tenant('en')));
    }

    public function test_an_unsupported_cookie_is_ignored(): void
    {
        $request = Request::create('/', 'GET', cookies: ['terroir_locale' => 'de']);

        $this->assertSame('hr', (new LocaleResolver)->resolve($request, $this->tenant('hr')));
    }
}
