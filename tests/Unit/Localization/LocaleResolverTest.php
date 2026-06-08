<?php

declare(strict_types=1);

namespace Tests\Unit\Localization;

use App\Models\Tenant;
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
}
