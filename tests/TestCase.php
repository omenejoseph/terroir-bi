<?php

namespace Tests;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;

abstract class TestCase extends BaseTestCase
{
    /**
     * Headers for an Inertia PARTIAL reload — the request a page makes when it
     * opens a drawer or switches a tab and wants one prop back.
     *
     * The version has to match or Inertia answers 409 and asks the browser to
     * do a full page load. Resolving it through the middleware (rather than
     * `Inertia::getVersion()`, which is only populated once a request has been
     * through it) means a test can make a partial request first, without having
     * to perform a full visit to prime it.
     *
     * @param  list<string>|string  $only  the prop names being asked for
     * @return array<string, string>
     */
    protected function inertiaPartial(string $component, array|string $only): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(
                app(Request::class),
            ),
            'X-Inertia-Partial-Component' => $component,
            'X-Inertia-Partial-Data' => is_array($only) ? implode(',', $only) : $only,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Inertia responses render a Blade root view containing @vite, which
        // throws unless `npm run build` has produced public/build/manifest.json.
        // Stub the directive so the suite does not depend on a built frontend.
        $this->withoutVite();
    }
}
