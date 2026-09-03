<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Customers\CreateCustomerAction;
use App\Actions\Customers\DeleteCustomerAction;
use App\Actions\Customers\OrderTokenAction;
use App\Actions\Customers\UpdateCustomerAction;
use App\Authorization\MembershipContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\MergeCustomersRequest;
use App\Http\Requests\Customers\StoreCustomerRequest;
use App\Http\Requests\Customers\UpdateCustomerRequest;
use App\Models\Customer;
use App\Models\CustomerPrice;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Queries\CustomerAnalyticsQuery;
use App\Queries\CustomerAttentionQuery;
use App\Queries\CustomerInsightsQuery;
use App\Queries\CustomerOrderAnalyticsQuery;
use App\Queries\CustomerProductsQuery;
use App\Queries\CustomerRhythmQuery;
use App\Queries\ListCustomersQuery;
use App\Queries\ListOrdersQuery;
use App\Queries\OrderPipelineQuery;
use App\Queries\OrderStatusCountsQuery;
use App\Services\Customers\CustomerMergeService;
use App\Services\Customers\CustomerPresenter;
use App\Services\Customers\PricingTierOptions;
use App\Services\Orders\CustomerConsignmentService;
use App\Services\Orders\OrderFormOptions;
use App\Services\Orders\OrderPresenter;
use App\Support\CustomerFilters;
use App\Support\Money\CurrencyRegistry;
use App\Support\Money\Money;
use App\Support\Period;
use App\Support\PerPage;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inertia counterpart of Api\CustomerController.
 *
 * Every read goes through the same Query + CustomerPresenter and every write
 * through the same Action as the API, so the two transports share behaviour by
 * construction rather than by discipline.
 *
 * The detail page's four tabs (Figma 231:9336) are `Inertia::optional` props
 * keyed off `?tab=`, so opening a customer costs the Overview and nothing else
 * — Order History and Komisija are only queried when someone looks at them.
 */
class CustomerController extends Controller
{
    public function __construct(private readonly MembershipContext $membership) {}

    /** Customers list (Figma 230:2395). */
    public function index(
        Request $request,
        ListCustomersQuery $query,
        CustomerPresenter $presenter,
        PricingTierOptions $tiers,
    ): Response {
        $filters = CustomerFilters::fromRequest($request);
        $perPage = PerPage::fromRequest($request);

        return Inertia::render('Customers/Index', [
            'customers' => $presenter->page($query->paginate($filters, $perPage)),
            'filters' => $filters,
            // Feeds the Tier filter; a small table, but only the filter row
            // needs it, so it is not paid for on every visit.
            'tiers' => Inertia::optional(fn (): array => $tiers->list()),
        ]);
    }

    /**
     * Customers · Analytics (Figma 230:4717) — the same CustomerAnalyticsQuery
     * the API endpoint uses, so the two cannot rank customers differently.
     */
    public function analytics(CustomerAnalyticsQuery $query): Response
    {
        return Inertia::render('Customers/Analytics', ['analytics' => $query->get()]);
    }

    /**
     * One customer (Figma 231:9336). The Overview tab's material always loads;
     * the other three arrive on demand.
     */
    public function show(
        Request $request,
        Customer $customer,
        CustomerPresenter $presenter,
        CustomerInsightsQuery $insights,
        CustomerOrderAnalyticsQuery $orderAnalytics,
        CustomerRhythmQuery $rhythm,
        CustomerProductsQuery $products,
        CustomerAttentionQuery $attention,
    ): Response {
        $financials = $this->membership->canSeeFinancials();

        // "Products bought" has its own range (Figma 231:9336: Lifetime / This
        // year / This month / Custom), independent of anything else on the page.
        [$productsFrom, $productsTo] = $this->productWindow($request);

        return Inertia::render('Customers/Show', [
            'customer' => $presenter->detail($customer),
            'tab' => $this->tab($request),
            'rhythm' => $rhythm->get($customer),
            // The band is usually empty; each card either fires with its
            // numbers or is absent, never a zero.
            'attention' => $attention->get($customer),
            // Money-denominated, so withheld rather than zeroed for a viewer
            // without financial visibility.
            'insights' => $financials ? $insights->get($customer) : null,
            'orderAnalytics' => $financials ? $orderAnalytics->get($customer) : null,
            'products' => $products->get($customer, $productsFrom, $productsTo),
            'productRange' => [
                'preset' => $this->productPreset($request),
                'from' => $productsFrom?->toDateString(),
                'to' => $productsTo?->toDateString(),
            ],

            // Pricing tab: this customer's own negotiated prices only — not
            // the whole catalogue's list/tier prices, which say nothing about
            // this customer specifically. "Add price" (pricingCatalog) offers
            // the full sellable catalogue to pick from.
            'pricing' => Inertia::optional(fn (): array => $this->pricing($customer)),
            'pricingCatalog' => Inertia::optional(fn (): array => app(OrderFormOptions::class)->products()),

            'orderHistory' => Inertia::optional(
                fn (): array => app(OrderPresenter::class)->page(
                    app(ListOrdersQuery::class)->paginate($this->orderHistoryFilters($request, $customer), PerPage::fromRequest($request)),
                ),
            ),
            // The order-to-cash pipeline (Figma 361:2157), narrowed to this
            // customer's own orders in the chosen window — same card, same
            // query, as the main Orders list.
            'orderPipeline' => Inertia::optional(function () use ($customer, $request): ?array {
                if (! $this->membership->canSeeFinancials()) {
                    return null;
                }

                [$from, $to] = $this->orderHistoryWindow($request);

                // OrderPipelineQuery wants concrete bounds; "lifetime" (no
                // lower bound) widens to the customer's very first order
                // instead, rather than every OTHER tenant's orders too.
                if ($from === null) {
                    $earliest = Order::query()->where('customer_id', $customer->getKey())->min('created_at');
                    $from = is_string($earliest) ? Carbon::parse($earliest) : Carbon::now();
                }

                return app(OrderPipelineQuery::class)->get($from, $to ?? Carbon::now(), $customer->getKey());
            }),
            // The status chip row above the table — counts ignore the status
            // filter itself so switching chips doesn't zero the others out.
            'orderStatusCounts' => Inertia::optional(
                fn (): array => app(OrderStatusCountsQuery::class)->get($this->orderHistoryFilters($request, $customer)),
            ),
            'orderHistoryRange' => $this->orderHistoryPreset($request),
            // Hydrates the toolbar on load/refresh and lets reloads carry the
            // current search/status forward the same way Orders/Index does.
            'orderHistoryFilters' => [
                'search' => $request->query('order_search'),
                'status' => $request->query('order_status'),
            ],
            // The footer's "Total" (Figma 361:2157) — the whole filtered set's
            // sum, not just the visible page, so paging never changes what it
            // claims. Withheld like every other money figure on this page.
            'orderHistoryTotal' => Inertia::optional(function () use ($request, $customer): ?array {
                if (! $this->membership->canSeeFinancials()) {
                    return null;
                }

                $minor = (int) app(ListOrdersQuery::class)
                    ->build($this->orderHistoryFilters($request, $customer))
                    ->sum('total_amount');

                return Money::fromMinor($minor, $this->currency())->jsonSerialize();
            }),

            'consignment' => Inertia::optional(
                fn (): array => app(CustomerConsignmentService::class)->summary($customer),
            ),

            // The self-service order link (231:9336's "Generate Order Link").
            // Only fetched once that dialog opens, and only for someone who
            // could actually generate one — the button itself is hidden
            // otherwise, so a request for it here would just be wasted.
            'orderToken' => Inertia::optional(
                fn (): ?string => $this->membership->can('customers.tokens') ? $customer->order_token : null,
            ),
        ]);
    }

    public function store(StoreCustomerRequest $request, CreateCustomerAction $action): RedirectResponse
    {
        $customer = $action->execute($request->validated());

        return redirect('/customers/'.$customer->id)->with('success', __('Customer created.'));
    }

    public function update(
        UpdateCustomerRequest $request,
        Customer $customer,
        UpdateCustomerAction $action,
    ): RedirectResponse {
        $action->execute($customer, $request->validated());

        return back()->with('success', __('Customer updated.'));
    }

    /**
     * Mute a customer on the reorder radar until their next order — the design's
     * "Call · repeat order" acknowledgement (231:9336).
     */
    public function markContacted(Request $request, Customer $customer): RedirectResponse
    {
        $customer->reorder_contacted_at = $request->boolean('contacted', true) ? now() : null;
        $customer->save();

        return back()->with('success', $customer->reorder_contacted_at !== null
            ? __('Marked as contacted.')
            : __('Contact flag cleared.'));
    }

    /**
     * Issues a self-service order link, replacing any existing one — the same
     * OrderTokenAction the API's customers.tokens endpoints use. The token
     * itself travels back only via the `orderToken` optional prop, refetched
     * with `only` right after this redirect, not embedded in the flash message.
     */
    public function generateToken(Customer $customer, OrderTokenAction $action): RedirectResponse
    {
        $action->generate($customer);

        return back();
    }

    public function revokeToken(Customer $customer, OrderTokenAction $action): RedirectResponse
    {
        $action->revoke($customer);

        return back()->with('success', __('Order link revoked.'));
    }

    /**
     * Merge duplicates into one customer (the design's selection bar, 230:2395).
     * CustomerMergeService moves the orders, prices and tokens; it is the same
     * service the API endpoint calls, and it is gated behind customers.delete
     * because a merge destroys records.
     */
    public function merge(MergeCustomersRequest $request, CustomerMergeService $service): RedirectResponse
    {
        $winner = Customer::query()->whereKey((string) $request->validated('winner_id'))->firstOrFail();
        /** @var list<string> $losers */
        $losers = array_values((array) $request->validated('loser_ids'));

        $service->merge($winner, $losers);

        return redirect('/customers/'.$winner->getKey())->with('success', trans_choice(
            ':count customer merged in.|:count customers merged in.',
            count($losers),
            ['count' => count($losers)],
        ));
    }

    public function destroy(Customer $customer, DeleteCustomerAction $action): RedirectResponse
    {
        $deactivated = $action->execute($customer);

        return redirect('/customers')->with(
            'success',
            $deactivated
                ? __('Customer has orders and was deactivated instead of deleted.')
                : __('Customer deleted.'),
        );
    }

    /**
     * This customer's own negotiated prices — an absolute override per item,
     * set via "Add price" (Web\CustomerPriceController) — not the whole
     * catalogue's list or tier prices, which are not decisions about this
     * customer at all. Every row here has `source: 'customer'` by
     * construction; the tab's "Pricing (N)" count is just `count($rows)`.
     *
     * @return array{rows: list<array<string, mixed>>, override_count: int}
     */
    private function pricing(Customer $customer): array
    {
        $rows = CustomerPrice::query()
            ->where('customer_id', $customer->getKey())
            ->with('inventoryItem')
            ->get()
            ->filter(fn (CustomerPrice $override): bool => $override->inventoryItem instanceof InventoryItem)
            ->sortBy(fn (CustomerPrice $override): string => $override->inventoryItem->name)
            ->map(fn (CustomerPrice $override): array => [
                'inventory_item_id' => $override->inventory_item_id,
                'name' => $override->inventoryItem->name,
                'sku' => $override->inventoryItem->sku,
                'vintage' => $override->inventoryItem->vintage,
                'unit_size' => $override->inventoryItem->unit_size,
                'list_price' => $override->inventoryItem->default_price?->jsonSerialize(),
                'price' => $override->price->jsonSerialize(),
                'source' => 'customer',
            ])
            ->values()
            ->all();

        return [
            'rows' => $rows,
            'override_count' => count($rows),
        ];
    }

    /**
     * Filters shared by the Order History table and its status-chip counts,
     * so the two can never disagree about what set they're both describing.
     *
     * @return array<string, mixed>
     */
    private function orderHistoryFilters(Request $request, Customer $customer): array
    {
        [$from, $to] = $this->orderHistoryWindow($request);

        return [
            'customer_id' => $customer->getKey(),
            'hide_shipped' => ! $this->membership->canSeeShippedOrders(),
            'search' => $request->query('order_search'),
            'status' => $request->query('order_status'),
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
        ];
    }

    /**
     * The Order History tab's own window (Figma 361:2157: "Last 3 months ▾"),
     * independent of "Products bought"'s. `lifetime` means no bounds.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function orderHistoryWindow(Request $request): array
    {
        $preset = $this->orderHistoryPreset($request);
        $to = Carbon::now();

        return match ($preset) {
            '6m' => [$to->copy()->subMonths(6)->startOfDay(), null],
            'ytd' => [$to->copy()->startOfYear(), null],
            'lifetime' => [null, null],
            default => [$to->copy()->subMonths(3)->startOfDay(), null],
        };
    }

    private function orderHistoryPreset(Request $request): string
    {
        $preset = $request->query('order_period');
        $allowed = ['3m', '6m', 'ytd', 'lifetime'];

        return is_string($preset) && in_array($preset, $allowed, true) ? $preset : '3m';
    }

    private function currency(): string
    {
        return app(TenantContext::class)->current()?->settings()->first()?->default_currency
            ?? CurrencyRegistry::default()->code;
    }

    /**
     * The window for "Products bought". `lifetime` is the default and means no
     * bounds at all — not a very long range, which would still exclude a first
     * order older than it.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function productWindow(Request $request): array
    {
        $preset = $this->productPreset($request);

        if ($preset === 'custom') {
            $from = $request->query('products_from');
            $to = $request->query('products_to');

            return [
                is_string($from) && $from !== '' ? Carbon::parse($from)->startOfDay() : null,
                is_string($to) && $to !== '' ? Carbon::parse($to)->endOfDay() : null,
            ];
        }

        if ($preset === 'lifetime') {
            return [null, null];
        }

        [$from, $to] = Period::resolve($preset === 'year' ? 'ytd' : 'this_month');

        return [$from, $to];
    }

    private function productPreset(Request $request): string
    {
        $preset = $request->query('products_range');
        $allowed = ['lifetime', 'year', 'month', 'custom'];

        return is_string($preset) && in_array($preset, $allowed, true) ? $preset : 'lifetime';
    }

    /** The four tabs the design gives a customer. Anything else is Overview. */
    private function tab(Request $request): string
    {
        $tab = $request->query('tab');
        $allowed = ['overview', 'pricing', 'orders', 'consignment'];

        return is_string($tab) && in_array($tab, $allowed, true) ? $tab : 'overview';
    }
}
