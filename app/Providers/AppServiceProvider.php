<?php

declare(strict_types=1);

namespace App\Providers;

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
    }

    public function boot(): void
    {
        //
    }
}
