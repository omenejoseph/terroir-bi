<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Billing\TenantAccessResolver;
use App\Services\Uploads\Contracts\ObjectStore;
use App\Services\Uploads\S3ObjectStore;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ObjectStore::class, fn () => new S3ObjectStore(
            (string) config('uploads.disk', 'r2'),
        ));

        // Per-request singleton so the computed access state is shared by the
        // EnforceTenantAccess middleware and the SessionBuilder.
        $this->app->singleton(TenantAccessResolver::class);
    }

    public function boot(): void
    {
        //
    }
}
