<?php

use App\Http\Controllers\Api\TranslationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes (v1)
|--------------------------------------------------------------------------
|
| Tenant-facing routes run inside the 'tenant' middleware group, which
| resolves the tenant (ResolveTenant) and sets the locale (SetLocale) before
| any tenant-scoped query runs.
|
*/

Route::prefix('v1')->middleware('tenant')->group(function () {
    Route::get('translations', [TranslationController::class, 'index']);
    Route::put('translations', [TranslationController::class, 'upsert']);
    Route::delete('translations', [TranslationController::class, 'destroy']);
});
