<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Customers\CreateCustomerCategoryAction;
use App\Actions\Customers\DeleteCustomerCategoryAction;
use App\Actions\Customers\ReorderCustomerCategoriesAction;
use App\Actions\Customers\UpdateCustomerCategoryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\ReorderCustomerCategoriesRequest;
use App\Http\Requests\Customers\StoreCustomerCategoryRequest;
use App\Http\Requests\Customers\UpdateCustomerCategoryRequest;
use App\Models\CustomerCategory;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer categories: the labels an organisation puts on its customers ("Restaurant",
 * "Hotel", …) and the order it likes them in. Reading needs `customers.view`, every write
 * `customers.manage` — the same gates as the customers themselves (routes/web.php).
 */
class CustomerCategoryController extends Controller
{
    public function index(): Response
    {
        $categories = CustomerCategory::query()
            ->withCount('customers')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (CustomerCategory $c): array => [
                'id' => $c->getKey(),
                'name' => $c->name,
                'is_active' => $c->is_active,
                'customers_count' => (int) $c->getAttribute('customers_count'),
            ])
            ->values();

        return Inertia::render('Customers/Categories', ['categories' => $categories]);
    }

    public function store(StoreCustomerCategoryRequest $request, CreateCustomerCategoryAction $action): RedirectResponse
    {
        $action->execute($request->string('name')->value());

        return back()->with('success', __('Category added.'));
    }

    public function update(UpdateCustomerCategoryRequest $request, CustomerCategory $category, UpdateCustomerCategoryAction $action): RedirectResponse
    {
        /** @var array{name?: string, is_active?: bool} $validated */
        $validated = $request->validated();
        $action->execute($category, $validated);

        return back()->with('success', __('Category updated.'));
    }

    public function destroy(CustomerCategory $category, DeleteCustomerCategoryAction $action): RedirectResponse
    {
        $action->execute($category);

        return back()->with('success', __('Category deleted. Its customers are kept, without a category.'));
    }

    public function reorder(ReorderCustomerCategoriesRequest $request, ReorderCustomerCategoriesAction $action): RedirectResponse
    {
        /** @var list<string> $ids */
        $ids = array_values($request->array('ids'));
        $action->execute($ids);

        return back();
    }
}
