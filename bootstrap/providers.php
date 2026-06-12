<?php

use App\Providers\AiServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    AiServiceProvider::class,
    AuthServiceProvider::class,
    AdminPanelProvider::class,
    TenancyServiceProvider::class,
];
