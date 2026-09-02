<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Inertia responses render a Blade root view containing @vite, which
        // throws unless `npm run build` has produced public/build/manifest.json.
        // Stub the directive so the suite does not depend on a built frontend.
        $this->withoutVite();
    }
}
