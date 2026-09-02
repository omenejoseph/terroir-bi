<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Authorization\MembershipContext;
use App\DataTransferObjects\CustomerData;
use App\Models\Customer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Turns customers into the shape the clients render.
 *
 * Both transports use this: the API returns it as JSON, the Inertia web
 * controller passes it straight through as a page prop. Revenue visibility is
 * decided once here, so the JSON API and the Vue table cannot disagree about
 * what a viewer without `financials.view` is allowed to see.
 */
class CustomerPresenter
{
    public function __construct(private readonly MembershipContext $membership) {}

    /**
     * @param  LengthAwarePaginator<int, Customer>  $paginator
     * @return array{data: list<array<string, mixed>>, meta: array{current_page:int, last_page:int, per_page:int, total:int}}
     */
    public function page(LengthAwarePaginator $paginator): array
    {
        /** @var list<Customer> $customers */
        $customers = $paginator->items();

        // effectiveRebatePercent() reads the tier; loading it here keeps the
        // page at one query rather than one per row.
        EloquentCollection::make($customers)->loadMissing('pricingTier');

        return [
            'data' => array_map(fn (Customer $customer): array => $this->row($customer), $customers),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function row(Customer $customer): array
    {
        return CustomerData::fromModel($customer, $this->membership->canSeeFinancials())->toArray();
    }

    /**
     * One customer for the detail page. Same DTO as a row — the extra material
     * the page shows (insights, order analytics, consignment) belongs to its
     * own queries rather than being folded into the customer record.
     *
     * @return array<string, mixed>
     */
    public function detail(Customer $customer): array
    {
        $customer->loadMissing('pricingTier');

        return $this->row($customer);
    }
}
