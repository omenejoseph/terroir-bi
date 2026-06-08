<?php

declare(strict_types=1);

namespace App\Providers;

use App\Authorization\Contracts\Authorizer;
use App\Authorization\MembershipContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MembershipContext::class);
        $this->app->bind(Authorizer::class, MembershipContext::class);
    }

    public function boot(): void
    {
        // Capability-style abilities (dotted, e.g. "members.manage") are resolved
        // from the active membership's roles. Other abilities fall through to any
        // registered policies.
        Gate::before(function ($user, string $ability) {
            if (str_contains($ability, '.')) {
                return app(Authorizer::class)->can($ability);
            }

            return null;
        });
    }
}
