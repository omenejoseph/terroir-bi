<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Tenancy abstraction config
    |--------------------------------------------------------------------------
    |
    | This is the ONLY tenancy config application code should read. It sits in
    | front of the underlying tenancy driver (currently stancl/tenancy, see
    | config/tenancy.php). Swapping drivers later should only require changing
    | the 'driver' binding and the adapter in app/Tenancy/Adapters.
    |
    */

    // Which underlying tenancy implementation backs the abstraction.
    'driver' => env('TENANT_DRIVER', 'stancl'),

    /*
    | Default isolation mode for new tenants.
    |
    |  - shared_row    : shared database, row-level isolation via tenant_id (current default)
    |  - dedicated_db  : tenant gets its own database (FUTURE — not yet enabled)
    */
    'default_isolation_mode' => env('TENANT_DEFAULT_ISOLATION', 'shared_row'),

    // Domains that host the central (non-tenant) app; subdomains below these resolve tenants.
    'central_domains' => explode(',', env('TENANT_CENTRAL_DOMAINS', '127.0.0.1,localhost')),

    /*
    | Order in which ResolveTenant middleware attempts to identify the tenant.
    | Earlier entries win. See app/Http/Middleware/ResolveTenant.php.
    */
    'resolution_order' => ['token', 'subdomain', 'header'],

    // Dev/test header used to bind a tenant before auth exists. Disabled in production.
    'dev_header' => env('TENANT_DEV_HEADER', 'X-Tenant'),
    'dev_header_enabled' => env('TENANT_DEV_HEADER_ENABLED', env('APP_ENV') !== 'production'),

];
