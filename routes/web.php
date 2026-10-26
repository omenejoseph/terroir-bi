<?php

declare(strict_types=1);

use App\Http\Controllers\Api\CellarSo2Controller as ApiCellarSo2Controller;
use App\Http\Controllers\Web\Auth\AcceptInvitationController;
use App\Http\Controllers\Web\Auth\LoginController;
use App\Http\Controllers\Web\Auth\PasswordResetController;
use App\Http\Controllers\Web\Auth\StopImpersonationController;
use App\Http\Controllers\Web\Auth\TenantSwitchController;
use App\Http\Controllers\Web\BottleAnalysisController;
use App\Http\Controllers\Web\Cellar\BlendController;
use App\Http\Controllers\Web\Cellar\CellarActivityController;
use App\Http\Controllers\Web\Cellar\CellarController;
use App\Http\Controllers\Web\Cellar\CellarSo2Controller;
use App\Http\Controllers\Web\Cellar\CellarUndoController;
use App\Http\Controllers\Web\Cellar\EnologicalProductController;
use App\Http\Controllers\Web\Cellar\ProtocolController;
use App\Http\Controllers\Web\Cellar\RecordOptionsController;
use App\Http\Controllers\Web\Cellar\TastingReportController;
use App\Http\Controllers\Web\Cellar\VesselController;
use App\Http\Controllers\Web\Cellar\WineLotController;
use App\Http\Controllers\Web\CustomerCategoryController;
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
use App\Http\Controllers\Web\PricingTierController;
use App\Http\Controllers\Web\ProductCategoryController;
use App\Http\Controllers\Web\ProfileController;
use App\Http\Controllers\Web\PublicOrderController;
use App\Http\Controllers\Web\SearchController;
use App\Http\Controllers\Web\SettingsController;
use App\Http\Controllers\Web\SetupController;
use App\Http\Controllers\Web\ShortcutController;
use App\Http\Controllers\Web\TeamController;
use App\Http\Controllers\Web\TeamImpersonationController;
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

    // "My profile": every member manages only their own name and password, so there is no
    // can:* gate. Writes are refused while impersonating (see the requests' authorize()).
    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

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

        // The new-account setup hub (nav's "System · Setup") — see
        // App\Services\Onboarding\OnboardingStatus and docs/21-onboarding-plan.md.
        Route::get('setup', [SetupController::class, 'index'])->name('setup.index');
        Route::patch('setup/steps/{step}', [SetupController::class, 'updateStep'])->name('setup.steps.update');
        Route::post('setup/dismiss', [SetupController::class, 'dismiss'])->name('setup.dismiss');
    });

    // Team (nav's "System · Team") — self-service member management,
    // grouped under Settings rather than a "Team" nav category of its own.
    // Gates mirror routes/api.php's own members/invitations gates exactly.
    Route::middleware('can:members.view')->group(function () {
        Route::get('settings/team', [TeamController::class, 'index'])->name('team.index');
    });
    // Admin-only (no role besides ADMIN's wildcard holds it). Stopping is the plain-auth
    // impersonation/stop route above, since the session user is the target by then.
    Route::post('settings/team/{membership}/impersonate', [TeamImpersonationController::class, 'store'])
        ->middleware('can:members.impersonate')
        ->name('team.impersonate');
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
        Route::get('inventory-check', [InventoryController::class, 'check'])->name('inventory.check');
        // Before inventory/{item}, which would otherwise swallow "categories" as an id.
        Route::get('inventory/categories', [ProductCategoryController::class, 'index'])->name('inventory.categories.index');
        Route::get('inventory/{item}', [InventoryController::class, 'show'])->name('inventory.show');
        Route::get('inventory/{item}/movements/export', [InventoryController::class, 'exportMovements'])
            ->name('inventory.movements.export');
    });

    Route::middleware('can:inventory.manage')->group(function () {
        Route::post('inventory', [InventoryController::class, 'store'])->name('inventory.store');
        // Drag and drop on the list: items within a band, and the order of groups / subcategories.
        Route::post('inventory-order', [InventoryController::class, 'reorder'])->name('inventory.reorder');
        // Drag a product (with its vintages) onto another category.
        Route::post('inventory-move', [InventoryController::class, 'move'])->name('inventory.move');
        Route::post('inventory/categories', [ProductCategoryController::class, 'store'])->name('inventory.categories.store');
        Route::post('inventory/categories/reorder', [ProductCategoryController::class, 'reorder'])->name('inventory.categories.reorder');
        Route::patch('inventory/categories/{category}', [ProductCategoryController::class, 'update'])->name('inventory.categories.update');
        Route::delete('inventory/categories/{category}', [ProductCategoryController::class, 'destroy'])->name('inventory.categories.destroy');
        Route::patch('inventory/{item}', [InventoryController::class, 'update'])->name('inventory.update');

        // Bulk Import — shared by the Inventory list, Analytics and Spend
        // pages' own "Bulk Import" button.
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

    // Stock movements: TEAM and INVENTORY in the old app (addStockMovement).
    Route::middleware('can:inventory.stock')->group(function () {
        Route::post('inventory/{item}/stock', [InventoryController::class, 'adjustStock'])
            ->name('inventory.stock.adjust');
    });

    // Admin-only in the old app: bulk import/update, applying a stock check, duplicating a product.
    Route::middleware('can:inventory.bulk')->group(function () {
        Route::patch('inventory-bulk', [InventoryController::class, 'bulkUpdate'])->name('inventory.bulk-update');
        Route::post('inventory-check', [InventoryController::class, 'applyCheck'])->name('inventory.check.apply');
        Route::post('inventory/bulk-import', [InventoryController::class, 'bulkImport'])
            ->name('inventory.bulk-import');
        Route::get('inventory/bulk-import/template', [InventoryController::class, 'bulkImportTemplate'])
            ->name('inventory.bulk-import.template');
        Route::post('inventory/{item}/duplicate', [InventoryController::class, 'duplicate'])
            ->name('inventory.duplicate');
    });

    // Inventory analytics and spend were admin-only in the old app.
    Route::middleware('can:inventory.analytics')->group(function () {
        Route::get('inventory-analytics', [InventoryController::class, 'analytics'])->name('inventory.analytics');
        Route::get('inventory-spend', [InventoryController::class, 'spend'])
            ->middleware('can:financials.view')
            ->name('inventory.spend');
        Route::get('inventory-spend/export', [InventoryController::class, 'exportSpend'])
            ->middleware('can:financials.view')
            ->name('inventory.spend.export');
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
    // TEAM and ORDERS create customers, see the reorder radar and mark customers contacted in the old app; editing is admin-only.
    Route::middleware('can:customers.create')->group(function () {
        Route::get('customers/reorder-radar', [CustomerController::class, 'reorderRadar'])->name('customers.reorder-radar');
        Route::get('customers/lookup-vat', [CustomerController::class, 'lookupVat'])->name('customers.lookup-vat');
        Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');
        Route::post('customers/{customer}/contacted', [CustomerController::class, 'markContacted'])
            ->name('customers.contacted');
    });

    Route::middleware('can:customers.view')->group(function () {
        Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('customers/export', [CustomerController::class, 'export'])->name('customers.export');
        Route::get('customers-analytics', [CustomerController::class, 'analytics'])
            ->middleware('can:financials.view')
            ->name('customers.analytics');
        // Static segment before the {customer} wildcard so it isn't treated
        // as an id — mirrors routes/api.php's own reorder-radar placement.
        // The Dashboard's Reorder pipeline card "View all" (Figma 208:5921).
        // Before customers/{customer}, which would otherwise swallow "categories" as an id.
        Route::get('customers/categories', [CustomerCategoryController::class, 'index'])->name('customers.categories.index');
        Route::get('customers/pricing-tiers', [PricingTierController::class, 'index'])->name('customers.pricing-tiers.index');
        Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
    });

    Route::middleware('can:customers.manage')->group(function () {
        Route::post('customers/pricing-tiers', [PricingTierController::class, 'store'])->name('customers.pricing-tiers.store');
        Route::patch('customers/pricing-tiers/{pricing_tier}', [PricingTierController::class, 'update'])->name('customers.pricing-tiers.update');
        Route::delete('customers/pricing-tiers/{pricing_tier}', [PricingTierController::class, 'destroy'])->name('customers.pricing-tiers.destroy');
        Route::post('customers/categories', [CustomerCategoryController::class, 'store'])->name('customers.categories.store');
        Route::post('customers/categories/reorder', [CustomerCategoryController::class, 'reorder'])->name('customers.categories.reorder');
        Route::patch('customers/categories/{category}', [CustomerCategoryController::class, 'update'])->name('customers.categories.update');
        Route::delete('customers/categories/{category}', [CustomerCategoryController::class, 'destroy'])->name('customers.categories.destroy');
        Route::patch('customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
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
      Work orders: TEAM, CELLAR and ORDERS in the old app, and never anyone who also holds
      MANAGER (its menu excluded them) — RoleCapabilities::revokedBy(). Static segments
      precede the {workOrder} wildcard.
    */
    Route::middleware('can:work_orders.use')->group(function () {
        Route::get('work-orders', [WorkOrderController::class, 'index'])->name('work-orders.index');
        Route::post('work-orders', [WorkOrderController::class, 'store'])->name('work-orders.store');
        Route::post('work-orders/reorder', [WorkOrderController::class, 'reorder'])->name('work-orders.reorder');
        Route::patch('work-orders/{workOrder}/status', [WorkOrderController::class, 'updateStatus'])
            ->name('work-orders.status.update');
        Route::patch('work-orders/{workOrder}', [WorkOrderController::class, 'update'])->name('work-orders.update');
        Route::delete('work-orders/{workOrder}', [WorkOrderController::class, 'destroy'])
            ->name('work-orders.destroy');
        Route::post('work-order-boards', [WorkOrderController::class, 'storeBoard'])->name('work-order-boards.store');
        Route::patch('work-order-boards/favorite', [WorkOrderController::class, 'setFavoriteBoard'])
            ->name('work-order-boards.favorite');
    });

    // Cellar (parity with the prototype's /dashboard/cellar — see
    // docs/20-cellar-parity-matrix.md). Every page lives under /cellar so the
    // module owns one web prefix. Same Queries and Actions as routes/api.php.
    Route::middleware('can:cellar.view')->group(function () {
        Route::get('cellar', [CellarController::class, 'index'])->name('cellar');
        Route::get('cellar/lots', [WineLotController::class, 'index'])->name('cellar.lots.index');
        Route::get('cellar/lots/{wineLot}', [WineLotController::class, 'show'])->name('cellar.lots.show');
        Route::get('cellar/vessels/{vessel}', [VesselController::class, 'show'])->name('cellar.vessels.show');
        Route::get('cellar/blend', [BlendController::class, 'index'])->name('cellar.blend');
        Route::post('cellar/blend/project', [BlendController::class, 'project'])->name('cellar.blend.project');
        Route::get('cellar/bulk-additions', [CellarController::class, 'bulkAdditions'])->name('cellar.bulk-additions');
        Route::get('cellar/bulk-analyses', [CellarController::class, 'bulkAnalyses'])->name('cellar.bulk-analyses');
        Route::get('cellar/tastings', [TastingReportController::class, 'index'])->name('cellar.tastings.index');
        Route::get('cellar/tastings/{tastingReport}', [TastingReportController::class, 'show'])->name('cellar.tastings.show');
        Route::get('cellar/products', [EnologicalProductController::class, 'index'])->name('cellar.products.index');
        Route::get('cellar/products/{enologicalProduct}/movements', [EnologicalProductController::class, 'movements'])->name('cellar.products.movements');
        Route::get('cellar/protocols', [ProtocolController::class, 'index'])->name('cellar.protocols.index');
        Route::get('cellar/analytics', [CellarController::class, 'analytics'])->name('cellar.analytics');
        Route::get('cellar/book', [CellarController::class, 'book'])->name('cellar.book');
        Route::get('cellar/book.csv', [CellarController::class, 'book'])->name('cellar.book.csv');
        // The enological calculators answer as JSON; same endpoint as the API.
        Route::post('cellar/calculators', [ApiCellarSo2Controller::class, 'calculate'])->name('cellar.calculators');
        Route::get('cellar/today', [CellarActivityController::class, 'today'])->name('cellar.today');
        Route::get('cellar/plan', [CellarActivityController::class, 'plan'])->name('cellar.plan');
        Route::get('cellar/so2', [CellarSo2Controller::class, 'index'])->name('cellar.so2');
        Route::post('cellar/so2/preview', [CellarSo2Controller::class, 'preview'])->name('cellar.so2.preview');
    });

    Route::middleware('can:cellar.manage')->group(function () {
        Route::get('cellar/record/options', RecordOptionsController::class)->name('cellar.record.options');
        Route::post('cellar/activities', [CellarActivityController::class, 'store'])->name('cellar.activities.store');
        Route::post('cellar/activities/{activity}/complete', [CellarActivityController::class, 'complete'])->name('cellar.activities.complete');
        Route::post('cellar/activities/{activity}/skip', [CellarActivityController::class, 'skip'])->name('cellar.activities.skip');
        Route::post('cellar/activities/{activity}/reschedule', [CellarActivityController::class, 'reschedule'])->name('cellar.activities.reschedule');
        Route::delete('cellar/activities/{activity}', [CellarActivityController::class, 'destroy'])->name('cellar.activities.destroy');
        Route::post('cellar/activity-rounds/{round}/reschedule', [CellarActivityController::class, 'rescheduleRound'])->name('cellar.rounds.reschedule');
        Route::delete('cellar/activity-rounds/{round}', [CellarActivityController::class, 'destroyRound'])->name('cellar.rounds.destroy');
        Route::post('cellar/lots/{wineLot}/protocol/plan-additions', [CellarActivityController::class, 'planProtocolAdditions'])->name('cellar.lots.protocol.plan-additions');

        Route::post('cellar/so2/apply', [CellarSo2Controller::class, 'apply'])->name('cellar.so2.apply');
        Route::post('cellar/so2/round', [CellarSo2Controller::class, 'planRound'])->name('cellar.so2.round');
        Route::post('cellar/so2/readings', [CellarSo2Controller::class, 'readings'])->name('cellar.so2.readings');

        Route::post('cellar/vessels', [VesselController::class, 'store'])->name('cellar.vessels.store');
        Route::post('cellar/vessels/bulk', [VesselController::class, 'bulkStore'])->name('cellar.vessels.bulk');
        Route::post('cellar/vessels/duplicate', [VesselController::class, 'duplicate'])->name('cellar.vessels.duplicate');
        Route::patch('cellar/vessels/layout', [VesselController::class, 'layout'])->name('cellar.vessels.layout');
        Route::post('cellar/rooms/rename', [VesselController::class, 'renameRoom'])->name('cellar.rooms.rename');
        Route::patch('cellar/vessels/{vessel}', [VesselController::class, 'update'])->name('cellar.vessels.update');

        Route::post('cellar/lots', [WineLotController::class, 'store'])->name('cellar.lots.store');
        Route::patch('cellar/lots/{wineLot}', [WineLotController::class, 'update'])->name('cellar.lots.update');
        Route::post('cellar/lots/{wineLot}/vessels', [WineLotController::class, 'assignVessel'])->name('cellar.lots.vessels.assign');
        Route::post('cellar/lots/{wineLot}/vessels/bulk', [WineLotController::class, 'bulkAssignVessels'])->name('cellar.lots.vessels.bulk');
        Route::delete('cellar/lots/{wineLot}/vessels/{vesselLot}', [WineLotController::class, 'unassignVessel'])->name('cellar.lots.vessels.unassign');
        Route::post('cellar/lots/{wineLot}/adjust-volume', [WineLotController::class, 'adjustVolume'])->name('cellar.lots.adjust');
        Route::post('cellar/lots/{wineLot}/blend-in', [WineLotController::class, 'blendIn'])->name('cellar.lots.blend-in');
        Route::post('cellar/lots/{wineLot}/analyses', [WineLotController::class, 'addAnalysis'])->name('cellar.lots.analyses.store');
        Route::patch('cellar/lots/{wineLot}/analyses/{analysis}', [WineLotController::class, 'updateAnalysis'])->name('cellar.lots.analyses.update');
        Route::delete('cellar/lots/{wineLot}/analyses/{analysis}', [WineLotController::class, 'deleteAnalysis'])->name('cellar.lots.analyses.destroy');
        Route::post('cellar/lots/{wineLot}/additions', [WineLotController::class, 'addAddition'])->name('cellar.lots.additions.store');
        Route::patch('cellar/lots/{wineLot}/additions/{addition}', [WineLotController::class, 'updateAddition'])->name('cellar.lots.additions.update');
        Route::delete('cellar/lots/{wineLot}/additions/{addition}', [WineLotController::class, 'deleteAddition'])->name('cellar.lots.additions.destroy');
        Route::post('cellar/lots/{wineLot}/processes', [WineLotController::class, 'addProcess'])->name('cellar.lots.processes.store');
        Route::delete('cellar/lots/{wineLot}/processes/{process}', [WineLotController::class, 'deleteProcess'])->name('cellar.lots.processes.destroy');
        Route::post('cellar/lots/{wineLot}/tasting-notes', [WineLotController::class, 'addTasting'])->name('cellar.lots.tastings.store');
        Route::patch('cellar/lots/{wineLot}/tasting-notes/{tastingNote}', [WineLotController::class, 'updateTasting'])->name('cellar.lots.tastings.update');
        Route::delete('cellar/lots/{wineLot}/tasting-notes/{tastingNote}', [WineLotController::class, 'deleteTasting'])->name('cellar.lots.tastings.destroy');
        Route::post('cellar/lots/{wineLot}/transfers', [WineLotController::class, 'addTransfer'])->name('cellar.lots.transfers.store');
        Route::delete('cellar/lots/{wineLot}/transfers/{transfer}', [WineLotController::class, 'deleteTransfer'])->name('cellar.lots.transfers.destroy');
        Route::post('cellar/undo/{token}', CellarUndoController::class)->where('token', '[A-Za-z0-9]{40}')->name('cellar.undo');
        Route::post('cellar/lots/{wineLot}/bottlings', [WineLotController::class, 'addBottling'])->name('cellar.lots.bottlings.store');
        Route::delete('cellar/lots/{wineLot}/bottlings/{bottling}', [WineLotController::class, 'deleteBottling'])->name('cellar.lots.bottlings.destroy');
        Route::post('cellar/lots/{wineLot}/protocol', [WineLotController::class, 'assignProtocol'])->name('cellar.lots.protocol');
        Route::post('cellar/lots/{wineLot}/protocol/generate', [WineLotController::class, 'generateProtocol'])->name('cellar.lots.protocol.generate');

        Route::post('cellar/blend', [BlendController::class, 'execute'])->name('cellar.blend.execute');
        Route::post('cellar/blend/by-ratio', [BlendController::class, 'byRatio'])->name('cellar.blend.by-ratio');
        Route::post('cellar/blend/trials', [BlendController::class, 'storeTrial'])->name('cellar.blend.trials.store');
        Route::delete('cellar/blend/trials/{blendTrial}', [BlendController::class, 'destroyTrial'])->name('cellar.blend.trials.destroy');
        Route::post('cellar/bulk-additions', [CellarController::class, 'storeBulkAdditions'])->name('cellar.bulk-additions.store');
        Route::post('cellar/bulk-analyses', [CellarController::class, 'storeBulkAnalyses'])->name('cellar.bulk-analyses.store');

        Route::post('cellar/tastings', [TastingReportController::class, 'store'])->name('cellar.tastings.store');
        Route::patch('cellar/tastings/{tastingReport}', [TastingReportController::class, 'update'])->name('cellar.tastings.update');
        Route::delete('cellar/tastings/{tastingReport}', [TastingReportController::class, 'destroy'])->name('cellar.tastings.destroy');

        Route::post('cellar/products', [EnologicalProductController::class, 'store'])->name('cellar.products.store');
        Route::post('cellar/products/import', [EnologicalProductController::class, 'import'])->name('cellar.products.import');
        Route::patch('cellar/products/bulk', [EnologicalProductController::class, 'bulkUpdate'])->name('cellar.products.bulk');
        Route::post('cellar/products/draft-orders', [EnologicalProductController::class, 'draftOrders'])->name('cellar.products.draft-orders');
        Route::patch('cellar/products/{enologicalProduct}', [EnologicalProductController::class, 'update'])->name('cellar.products.update');
        Route::post('cellar/products/{enologicalProduct}/adjust-stock', [EnologicalProductController::class, 'adjustStock'])->name('cellar.products.adjust');

        Route::post('cellar/protocols', [ProtocolController::class, 'store'])->name('cellar.protocols.store');
        Route::patch('cellar/protocols/{fermentationTemplate}', [ProtocolController::class, 'update'])->name('cellar.protocols.update');
    });

    Route::middleware(['can:cellar.view', 'can:finance.view'])->group(function () {
        Route::get('cellar/costs', [CellarController::class, 'costs'])->name('cellar.costs');
    });

    Route::middleware('can:cellar.delete')->group(function () {
        Route::delete('cellar/vessels', [VesselController::class, 'bulkDestroy'])->name('cellar.vessels.bulk-destroy');
        Route::delete('cellar/vessels/{vessel}', [VesselController::class, 'destroy'])->name('cellar.vessels.destroy');
        Route::get('cellar/lots/{wineLot}/delete-impact', [WineLotController::class, 'deleteImpact'])->name('cellar.lots.delete-impact');
        Route::delete('cellar/lots/{wineLot}', [WineLotController::class, 'destroy'])->name('cellar.lots.destroy');
        Route::delete('cellar/products/{enologicalProduct}', [EnologicalProductController::class, 'destroy'])->name('cellar.products.destroy');
        Route::delete('cellar/protocols/{fermentationTemplate}', [ProtocolController::class, 'destroy'])->name('cellar.protocols.destroy');
    });
});
