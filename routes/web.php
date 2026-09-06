<?php

declare(strict_types=1);

use App\Http\Controllers\Web\Auth\AcceptInvitationController;
use App\Http\Controllers\Web\Auth\LoginController;
use App\Http\Controllers\Web\Auth\PasswordResetController;
use App\Http\Controllers\Web\Auth\StopImpersonationController;
use App\Http\Controllers\Web\Auth\TenantSwitchController;
use App\Http\Controllers\Web\BottleAnalysisController;
use App\Http\Controllers\Web\CustomerConsignmentController;
use App\Http\Controllers\Web\CustomerController;
use App\Http\Controllers\Web\CustomerPriceController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\InventoryController;
use App\Http\Controllers\Web\InventoryMediaController;
use App\Http\Controllers\Web\LocaleController;
use App\Http\Controllers\Web\LogController;
use App\Http\Controllers\Web\NotificationController;
use App\Http\Controllers\Web\OrderController;
use App\Http\Controllers\Web\PublicOrderController;
use App\Http\Controllers\Web\SearchController;
use App\Http\Controllers\Web\SettingsController;
use App\Http\Controllers\Web\ShortcutController;
use App\Http\Controllers\Web\TeamController;
use App\Http\Controllers\Web\TeamInvitationController;
use App\Http\Controllers\Web\TeamMembersController;
use App\Http\Controllers\Web\TierPriceController;
use App\Http\Controllers\Web\UploadController;
use App\Http\Controllers\Web\WelcomeController;
use App\Http\Controllers\Web\WorkOrderController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes — the Inertia (Vue 3) application
|--------------------------------------------------------------------------
|
| These are session-authenticated and served from Laravel itself, unlike
| routes/api.php which stays token-authenticated for the public order and
| supplier portals and any non-browser client.
|
| Both call the same Actions/Queries/Services; only the envelope differs. As
| each module is ported from the Next.js app in frontend/, add its routes here
| and move it out of PENDING_MODULES in resources/js/Lib/navigation.ts.
|
*/

// The public marketing home page. Guests see the pitch; a signed-in visitor
// is bounced to their dashboard by the controller itself. See WelcomeController.
Route::get('/', WelcomeController::class)->name('home');

// The self-service order page a customer reaches via their order token
// (Customers · "Generate Order Link"). No auth, no tenant middleware — the
// token itself authenticates and selects the tenant, resolved client-side
// against the same public API endpoints (routes/api.php) the token was
// always meant to authenticate against. See Web\PublicOrderController.
Route::get('order/{token}', [PublicOrderController::class, 'show'])->name('public.order');

// LanguageSwitcher.vue — no auth requirement, since it's mounted on the guest
// login screen as well as the authenticated app shell. See LocaleController.
Route::patch('locale', [LocaleController::class, 'update'])->name('locale.update');

// Guests only — an authenticated visit to /login bounces to the dashboard.
Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store']);

    // The landing page an admin-triggered reset email points to
    // (UserController::sendPasswordReset) — see PasswordResetController.
    Route::get('reset-password/{token}', [PasswordResetController::class, 'create'])->name('password.reset');
    Route::post('reset-password', [PasswordResetController::class, 'store'])->name('password.update');

    // The Team page's "Invite" link (Web\TeamInvitationController generates
    // it, no email sent) — see AcceptInvitationController.
    Route::get('invitations/{token}', [AcceptInvitationController::class, 'show'])->name('invitations.show');
    Route::post('invitations/accept', [AcceptInvitationController::class, 'store'])->name('invitations.accept');
});

// Authenticated, but no active tenant required: a user whose current tenant
// context is unusable must still be able to sign out or switch away from it.
// impersonation/stop belongs here for the same reason as tenant/switch: while
// impersonating, the session's user is the impersonation TARGET (an ordinary
// tenant member, not a platform admin), so this can only require plain auth —
// see StopImpersonationController's docblock.
Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');
    Route::post('tenant/switch', [TenantSwitchController::class, 'store'])->name('tenant.switch');
    Route::post('impersonation/stop', [StopImpersonationController::class, 'store'])->name('impersonation.stop');
});

// Authenticated + active tenant + verified membership + plan/subscription checks.
Route::middleware('tenant.web')->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // The header's global search. Gated per-category inside the controller
    // (capability + plan module), not by middleware — see SearchController.
    Route::get('search', SearchController::class)->name('search');

    // The header's notification bell — every member manages only their own feed.
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read', [NotificationController::class, 'read'])->name('notifications.read');
    Route::post('notifications/clear', [NotificationController::class, 'clear'])->name('notifications.clear');
    Route::delete('notifications/{notification}', [NotificationController::class, 'destroy'])->name('notifications.destroy');

    // The @-mention picker on order comments (and anywhere else that adds one
    // later). Deliberately as permissive as orders.comments.store itself —
    // any tenant member may look up a teammate to tag, not just members.view
    // holders (that capability is for member *management*, not this).
    Route::get('team-members', [TeamMembersController::class, 'index'])->name('team-members.index');

    // Manage Shortcuts (Figma 143:4179). No can:* gate: pinning is a personal
    // preference over nav items the member can already see, not a capability
    // of its own.
    Route::patch('shortcuts', [ShortcutController::class, 'update'])->name('shortcuts.update');
    Route::delete('shortcuts/recent', [ShortcutController::class, 'clearRecent'])->name('shortcuts.clear-recent');

    // General presigned upload URL (any member; the attach step is gated) —
    // mirrors routes/api.php's own uploads/presign.
    Route::post('uploads/presign', [UploadController::class, 'presign'])->name('uploads.presign');

    // A tenant's own audit trail — scoped to this tenant only, unlike the
    // platform-admin /admin/audit-logs view. See Web\LogController.
    Route::middleware('can:logs.view')->group(function () {
        Route::get('logs', [LogController::class, 'index'])->name('logs.index');
    });

    // Organisation settings (nav's "System · Settings") — the Inertia
    // counterpart of Api\SettingsController's own settings.manage gate.
    Route::middleware('can:settings.manage')->group(function () {
        Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::patch('settings', [SettingsController::class, 'update'])->name('settings.update');
    });

    // Team (nav's "System · Team") — self-service member management,
    // grouped under Settings rather than a "Team" nav category of its own.
    // Gates mirror routes/api.php's own members/invitations gates exactly.
    Route::middleware('can:members.view')->group(function () {
        Route::get('settings/team', [TeamController::class, 'index'])->name('team.index');
    });
    Route::middleware('can:members.manage')->group(function () {
        Route::patch('settings/team/{membership}', [TeamController::class, 'update'])->name('team.update');
        Route::patch('settings/team/{membership}/password', [TeamController::class, 'setPassword'])->name('team.set-password');
        Route::delete('settings/team/{membership}', [TeamController::class, 'destroy'])->name('team.destroy');
        Route::post('settings/team/{membership}/restore', [TeamController::class, 'restore'])
            ->withTrashed()
            ->name('team.restore');
    });
    Route::middleware('can:invitations.manage')->group(function () {
        Route::post('settings/team/invitations', [TeamInvitationController::class, 'store'])->name('team.invitations.store');
        Route::delete('settings/team/invitations/{invitation}', [TeamInvitationController::class, 'destroy'])->name('team.invitations.destroy');
    });

    Route::middleware('can:inventory.view')->group(function () {
        Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
        Route::get('inventory-analytics', [InventoryController::class, 'analytics'])->name('inventory.analytics');
        Route::get('inventory-check', [InventoryController::class, 'check'])->name('inventory.check');
        Route::get('inventory-spend', [InventoryController::class, 'spend'])
            ->middleware('can:financials.view')
            ->name('inventory.spend');
        Route::get('inventory-spend/export', [InventoryController::class, 'exportSpend'])
            ->middleware('can:financials.view')
            ->name('inventory.spend.export');
        Route::get('inventory/{item}', [InventoryController::class, 'show'])->name('inventory.show');
        Route::get('inventory/{item}/movements/export', [InventoryController::class, 'exportMovements'])
            ->name('inventory.movements.export');
    });

    Route::middleware('can:inventory.manage')->group(function () {
        Route::post('inventory', [InventoryController::class, 'store'])->name('inventory.store');
        Route::patch('inventory/{item}', [InventoryController::class, 'update'])->name('inventory.update');
        Route::post('inventory/{item}/stock', [InventoryController::class, 'adjustStock'])
            ->name('inventory.stock.adjust');
        Route::patch('inventory-bulk', [InventoryController::class, 'bulkUpdate'])->name('inventory.bulk-update');
        Route::post('inventory-check', [InventoryController::class, 'applyCheck'])->name('inventory.check.apply');

        // Bulk Import — shared by the Inventory list, Analytics and Spend
        // pages' own "Bulk Import" button.
        Route::post('inventory/bulk-import', [InventoryController::class, 'bulkImport'])
            ->name('inventory.bulk-import');
        Route::get('inventory/bulk-import/template', [InventoryController::class, 'bulkImportTemplate'])
            ->name('inventory.bulk-import.template');
        Route::post('inventory/{item}/duplicate', [InventoryController::class, 'duplicate'])
            ->name('inventory.duplicate');
        Route::put('inventory/{item}/recipe', [InventoryController::class, 'updateRecipe'])
            ->name('inventory.recipe.update');
        Route::post('inventory/{item}/produce', [InventoryController::class, 'produce'])
            ->name('inventory.produce');

        // Images and Docs tabs — attach/delete only; InventoryController::show()
        // sends the current lists alongside everything else on the page.
        Route::post('inventory/{item}/images', [InventoryMediaController::class, 'attachImage'])
            ->name('inventory.images.attach');
        Route::delete('inventory/{item}/images/{image}', [InventoryMediaController::class, 'deleteImage'])
            ->name('inventory.images.destroy');
        Route::post('inventory/{item}/tech-sheets', [InventoryMediaController::class, 'attachTechSheet'])
            ->name('inventory.tech-sheets.attach');
        Route::delete('inventory/{item}/tech-sheets/{techSheet}', [InventoryMediaController::class, 'deleteTechSheet'])
            ->name('inventory.tech-sheets.destroy');
        Route::post('inventory/{item}/documents', [InventoryMediaController::class, 'attachDocument'])
            ->name('inventory.documents.attach');
        Route::delete('inventory/{item}/documents/{document}', [InventoryMediaController::class, 'deleteDocument'])
            ->name('inventory.documents.destroy');

        // Analysis tab.
        Route::post('inventory/{item}/bottle-analyses', [BottleAnalysisController::class, 'store'])
            ->name('inventory.bottle-analyses.store');
        Route::delete('inventory/{item}/bottle-analyses/{analysis}', [BottleAnalysisController::class, 'destroy'])
            ->name('inventory.bottle-analyses.destroy');
    });

    Route::delete('inventory/{item}', [InventoryController::class, 'destroy'])
        ->middleware('can:inventory.delete')
        ->name('inventory.destroy');

    /*
      Orders. The gates mirror routes/api.php exactly: viewing and commenting
      need orders.view, every write needs orders.manage, deletion is admin-only
      via orders.delete. The unauthenticated public token order page stays on
      the API — it resolves its tenant from the token, not from the session.
    */
    Route::middleware('can:orders.view')->group(function () {
        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('orders/export', [OrderController::class, 'export'])->name('orders.export');
        // The overflow menu's "Print" — read-only, like export().
        Route::get('orders/{order}/pdf', [OrderController::class, 'downloadPdf'])->name('orders.pdf');
        // Participation, not management: any order viewer may comment.
        Route::post('orders/{order}/comments', [OrderController::class, 'storeComment'])
            ->name('orders.comments.store');
        // Reactions: same participation gate — a toggle only ever touches
        // the caller's own reaction.
        Route::post('order-comments/{orderNote}/reactions', [OrderController::class, 'toggleCommentReaction'])
            ->name('orders.comments.reactions.toggle');
    });

    Route::middleware('can:orders.manage')->group(function () {
        Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
        Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus'])
            ->name('orders.status.update');
        Route::patch('orders-bulk/status', [OrderController::class, 'bulkUpdateStatus'])
            ->name('orders.bulk-status.update');
        Route::patch('orders/{order}/notes', [OrderController::class, 'updateNotes'])
            ->name('orders.notes.update');
        Route::post('orders/{order}/items', [OrderController::class, 'addItems'])
            ->name('orders.items.store');
        Route::patch('order-items/{orderItem}', [OrderController::class, 'updateItem'])
            ->name('order-items.update');
        Route::delete('order-items/{orderItem}', [OrderController::class, 'deleteItem'])
            ->name('order-items.destroy');
        Route::post('orders/{order}/duplicate', [OrderController::class, 'duplicate'])
            ->name('orders.duplicate');
        // The overflow menu's "Mark paid" and "Resend".
        Route::post('orders/{order}/mark-paid', [OrderController::class, 'markPaid'])
            ->name('orders.mark-paid');
        Route::post('orders/{order}/resend-confirmation', [OrderController::class, 'resendConfirmation'])
            ->name('orders.confirmation.resend');
    });

    Route::delete('orders/{order}', [OrderController::class, 'destroy'])
        ->middleware('can:orders.delete')
        ->name('orders.destroy');

    /*
      Customers. Gates mirror routes/api.php: reading needs customers.view,
      the analytics tab additionally needs financials.view (it is entirely
      money), writes need customers.manage, and deletion is admin-only via
      customers.delete.
    */
    Route::middleware('can:customers.view')->group(function () {
        Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('customers/export', [CustomerController::class, 'export'])->name('customers.export');
        Route::get('customers-analytics', [CustomerController::class, 'analytics'])
            ->middleware('can:financials.view')
            ->name('customers.analytics');
        // Static segment before the {customer} wildcard so it isn't treated
        // as an id — mirrors routes/api.php's own reorder-radar placement.
        // The Dashboard's Reorder pipeline card "View all" (Figma 208:5921).
        Route::get('customers/reorder-radar', [CustomerController::class, 'reorderRadar'])->name('customers.reorder-radar');
        Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
    });

    Route::middleware('can:customers.manage')->group(function () {
        Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');
        Route::patch('customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
        Route::post('customers/{customer}/contacted', [CustomerController::class, 'markContacted'])
            ->name('customers.contacted');
    });

    // Merging destroys records, so it carries the same admin-only gate as
    // deletion — matching routes/api.php.
    Route::post('customers/merge', [CustomerController::class, 'merge'])
        ->middleware('can:customers.delete')
        ->name('customers.merge');

    Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])
        ->middleware('can:customers.delete')
        ->name('customers.destroy');

    // The self-service order link (Figma 231:9336's "Generate Order Link").
    // Admin-only, matching routes/api.php's customers.tokens gate.
    Route::middleware('can:customers.tokens')->group(function () {
        Route::post('customers/{customer}/order-token', [CustomerController::class, 'generateToken'])
            ->name('customers.order-token.generate');
        Route::delete('customers/{customer}/order-token', [CustomerController::class, 'revokeToken'])
            ->name('customers.order-token.revoke');
    });

    // A customer's own negotiated prices (Customer — Show · Pricing tab and
    // Product Detail · Pricing tab both write through these, one row at a
    // time by (customer, item)). Gates mirror routes/api.php's pricing.manage
    // on the same endpoint shape.
    Route::middleware('can:pricing.manage')->group(function () {
        Route::patch('customers/{customer}/prices/{item}', [CustomerPriceController::class, 'update'])
            ->name('customers.prices.update');
        Route::delete('customers/{customer}/prices/{item}', [CustomerPriceController::class, 'destroy'])
            ->name('customers.prices.destroy');

        // A pricing tier's absolute price for one item (Product Detail ·
        // Pricing tab's other table).
        Route::patch('inventory/{item}/tier-prices/{tier}', [TierPriceController::class, 'update'])
            ->name('inventory.tier-prices.update');
        Route::delete('inventory/{item}/tier-prices/{tier}', [TierPriceController::class, 'destroy'])
            ->name('inventory.tier-prices.destroy');
    });

    // Customer-level consignment (Consignment tab): place new goods, and
    // record sales/returns FIFO across the customer's open placements. Reads
    // ride along with the tab's own Inertia::optional prop, gated like the
    // rest of Customer — Show by customers.view; only the writes need their
    // own gate, matching routes/api.php's orders.manage on the same shape.
    Route::middleware('can:orders.manage')->group(function () {
        Route::post('customers/{customer}/consignment/place', [CustomerConsignmentController::class, 'place'])
            ->name('customers.consignment.place');
        Route::post('customers/{customer}/consignment/sale', [CustomerConsignmentController::class, 'sale'])
            ->name('customers.consignment.sale');
        Route::post('customers/{customer}/consignment/return', [CustomerConsignmentController::class, 'recordReturn'])
            ->name('customers.consignment.return');
    });

    /*
      Work orders. Deliberately ungated, matching routes/api.php: team task
      planning is open to any member of the tenant. Static segments precede the
      {workOrder} wildcard.
    */
    Route::get('work-orders', [WorkOrderController::class, 'index'])->name('work-orders.index');
    Route::post('work-orders', [WorkOrderController::class, 'store'])->name('work-orders.store');
    Route::post('work-orders/reorder', [WorkOrderController::class, 'reorder'])->name('work-orders.reorder');
    Route::patch('work-orders/{workOrder}/status', [WorkOrderController::class, 'updateStatus'])
        ->name('work-orders.status.update');
    Route::patch('work-orders/{workOrder}', [WorkOrderController::class, 'update'])->name('work-orders.update');
    Route::delete('work-orders/{workOrder}', [WorkOrderController::class, 'destroy'])
        ->name('work-orders.destroy');

    // Boards — same ungated stance as work orders themselves.
    Route::post('work-order-boards', [WorkOrderController::class, 'storeBoard'])->name('work-order-boards.store');
    Route::patch('work-order-boards/favorite', [WorkOrderController::class, 'setFavoriteBoard'])
        ->name('work-order-boards.favorite');
});
