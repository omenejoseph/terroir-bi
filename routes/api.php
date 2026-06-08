<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\TenantSessionController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\TranslationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes (v1)
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {
    // Public — no authentication.
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/invitations/accept', [InvitationController::class, 'accept']);

    // Authenticated, but no active tenant required (e.g. to switch tenants).
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/tenants', [TenantSessionController::class, 'index']);
        Route::post('auth/switch-tenant', [TenantSessionController::class, 'switch']);
    });

    // Authenticated + active tenant + verified membership.
    Route::middleware('tenant')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);

        // Localization — read for any member; writes require admin.
        Route::get('translations', [TranslationController::class, 'index']);
        Route::middleware('can:translations.manage')->group(function () {
            Route::put('translations', [TranslationController::class, 'upsert']);
            Route::delete('translations', [TranslationController::class, 'destroy']);
        });

        // Member management (admin).
        Route::get('members', [MemberController::class, 'index'])->middleware('can:members.view');
        Route::middleware('can:members.manage')->group(function () {
            Route::patch('members/{user}', [MemberController::class, 'update']);
            Route::delete('members/{user}', [MemberController::class, 'destroy']);
        });

        // Invitations (admin).
        Route::middleware('can:invitations.manage')->group(function () {
            Route::get('invitations', [InvitationController::class, 'index']);
            Route::post('invitations', [InvitationController::class, 'store']);
            Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy']);
        });
    });
});
