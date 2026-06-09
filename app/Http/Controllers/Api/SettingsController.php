<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Settings\UpdateSettingsAction;
use App\DataTransferObjects\OrganizationSettingsData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateSettingsRequest;
use App\Models\Tenant;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * Organisation settings. Reading is open to any member (the frontend needs the
 * currency/timezone to format values); writing requires `can:settings.manage`.
 */
class SettingsController extends Controller
{
    public function show(TenantContext $tenant): JsonResponse
    {
        $current = $tenant->current();
        abort_unless($current instanceof Tenant, 404);

        return response()->json([
            'data' => OrganizationSettingsData::fromTenant($current)->toArray(),
        ]);
    }

    public function update(
        UpdateSettingsRequest $request,
        UpdateSettingsAction $action,
        TenantContext $tenant,
    ): JsonResponse {
        $current = $tenant->current();
        abort_unless($current instanceof Tenant, 404);

        /** @var array{name: string, default_locale: string, timezone: string, company_oib?: string|null} $validated */
        $validated = $request->validated();

        $data = $action->execute($current, $validated);

        return response()->json(['data' => $data->toArray()]);
    }
}
