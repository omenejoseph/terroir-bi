<?php

declare(strict_types=1);

use App\Http\Controllers\Web\Admin\AiSettingsController;
use App\Http\Controllers\Web\Admin\AiSpendController;
use App\Http\Controllers\Web\Admin\BddAccessController;
use App\Http\Controllers\Web\Admin\BddScenarioController;
use App\Http\Controllers\Web\Admin\BroadcastController;
use App\Http\Controllers\Web\Admin\DashboardController;
use App\Http\Controllers\Web\Admin\PlanController;
use App\Http\Controllers\Web\Admin\PlatformAdminController;
use App\Http\Controllers\Web\Admin\StripeSettingsController;
use App\Http\Controllers\Web\Admin\TenantController;
use App\Http\Controllers\Web\Admin\TenantMemberController;
use App\Http\Controllers\Web\Admin\TranslationOverrideController;
use App\Http\Controllers\Web\Admin\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Platform-admin routes — the Inertia "/admin" back office
|--------------------------------------------------------------------------
|
| Replaces the Filament panel that used to be mounted at /admin
| (app/Filament/**, App\Providers\Filament\AdminPanelProvider — both
| retired). Gated by the platform.admin middleware group (bootstrap/app.php):
| auth + is_platform_admin only. This is intentionally NOT routes/web.php's
| tenant.web chain — a platform admin need not be a member of any tenant, and
| ResolveTenant would 400/403 one who isn't.
*/

Route::middleware('platform.admin')->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('translation-overrides', [TranslationOverrideController::class, 'index'])
        ->name('translation-overrides.index');
    Route::post('translation-overrides', [TranslationOverrideController::class, 'store'])
        ->name('translation-overrides.store');
    Route::patch('translation-overrides/{translationOverride}', [TranslationOverrideController::class, 'update'])
        ->name('translation-overrides.update');
    Route::delete('translation-overrides/{translationOverride}', [TranslationOverrideController::class, 'destroy'])
        ->name('translation-overrides.destroy');

    Route::get('platform-admins', [PlatformAdminController::class, 'index'])->name('platform-admins.index');
    Route::post('platform-admins', [PlatformAdminController::class, 'store'])->name('platform-admins.store');
    Route::post('platform-admins/promote', [PlatformAdminController::class, 'promote'])
        ->name('platform-admins.promote');
    Route::delete('platform-admins/{user}', [PlatformAdminController::class, 'revoke'])
        ->name('platform-admins.revoke');

    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');

    Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
    Route::post('plans', [PlanController::class, 'store'])->name('plans.store');
    Route::get('plans/{plan}', [PlanController::class, 'show'])->name('plans.show');
    Route::patch('plans/{plan}', [PlanController::class, 'update'])->name('plans.update');
    Route::delete('plans/{plan}', [PlanController::class, 'destroy'])->name('plans.destroy');
    Route::post('plans/{plan}/create-stripe-price', [PlanController::class, 'createStripePrice'])
        ->name('plans.create-stripe-price');

    Route::get('tenants', [TenantController::class, 'index'])->name('tenants.index');
    Route::post('tenants', [TenantController::class, 'store'])->name('tenants.store');
    Route::get('tenants/{tenant}', [TenantController::class, 'show'])->name('tenants.show');
    Route::patch('tenants/{tenant}/status', [TenantController::class, 'updateStatus'])->name('tenants.update-status');
    Route::patch('tenants/{tenant}/plan', [TenantController::class, 'assignPlan'])->name('tenants.assign-plan');
    Route::post('tenants/{tenant}/onboarding-link', [TenantController::class, 'generateOnboardingLink'])
        ->name('tenants.generate-onboarding-link');
    Route::post('tenants/{tenant}/email-billing-link', [TenantController::class, 'emailBillingLink'])
        ->name('tenants.email-billing-link');

    Route::post('tenants/{tenant}/members', [TenantMemberController::class, 'store'])->name('tenant-members.store');
    Route::patch('tenants/{tenant}/members/{member}', [TenantMemberController::class, 'update'])
        ->name('tenant-members.update');
    Route::delete('tenants/{tenant}/members/{member}', [TenantMemberController::class, 'destroy'])
        ->name('tenant-members.destroy');

    Route::get('bdd-scenarios', [BddScenarioController::class, 'index'])->name('bdd-scenarios.index');
    Route::post('bdd-scenarios', [BddScenarioController::class, 'store'])->name('bdd-scenarios.store');
    Route::post('bdd-scenarios/run-all', [BddScenarioController::class, 'runAll'])->name('bdd-scenarios.run-all');
    Route::get('bdd-scenarios/{bddScenario}', [BddScenarioController::class, 'show'])->name('bdd-scenarios.show');
    Route::get('bdd-scenarios/{bddScenario}/status', [BddScenarioController::class, 'status'])
        ->name('bdd-scenarios.status');
    Route::patch('bdd-scenarios/{bddScenario}', [BddScenarioController::class, 'update'])->name('bdd-scenarios.update');
    Route::delete('bdd-scenarios/{bddScenario}', [BddScenarioController::class, 'destroy'])
        ->name('bdd-scenarios.destroy');
    Route::post('bdd-scenarios/{bddScenario}/run', [BddScenarioController::class, 'run'])->name('bdd-scenarios.run');
    Route::post('bdd-scenarios/{bddScenario}/grant-access', [BddScenarioController::class, 'grantAccess'])
        ->name('bdd-scenarios.grant-access');

    Route::get('bdd-access', [BddAccessController::class, 'index'])->name('bdd-access.index');
    Route::post('bdd-access/grant', [BddAccessController::class, 'grant'])->name('bdd-access.grant');
    Route::post('bdd-access/revoke', [BddAccessController::class, 'revoke'])->name('bdd-access.revoke');

    Route::get('ai-settings', [AiSettingsController::class, 'index'])->name('ai-settings.index');
    Route::post('ai-settings/configure', [AiSettingsController::class, 'configure'])->name('ai-settings.configure');
    Route::post('ai-settings/test-all', [AiSettingsController::class, 'testAll'])->name('ai-settings.test-all');
    Route::post('ai-settings/{capability}/test', [AiSettingsController::class, 'testCapability'])
        ->name('ai-settings.test-capability');
    Route::post('ai-settings/{capability}/enable', [AiSettingsController::class, 'enableCapability'])
        ->name('ai-settings.enable-capability');
    Route::post('ai-settings/{capability}/disable', [AiSettingsController::class, 'disableCapability'])
        ->name('ai-settings.disable-capability');

    Route::get('ai-spend', [AiSpendController::class, 'index'])->name('ai-spend.index');
    Route::get('ai-spend/cloudflare-cost', [AiSpendController::class, 'loadCloudflareCost'])
        ->name('ai-spend.cloudflare-cost');

    Route::get('stripe-settings', [StripeSettingsController::class, 'index'])->name('stripe-settings.index');
    Route::post('stripe-settings/test-connection', [StripeSettingsController::class, 'testConnection'])
        ->name('stripe-settings.test-connection');

    Route::get('broadcast', [BroadcastController::class, 'index'])->name('broadcast.index');
    Route::post('broadcast', [BroadcastController::class, 'store'])->name('broadcast.store');

    // Tier 3 (remaining): the platform dashboard's widgets — see the plan.
});
