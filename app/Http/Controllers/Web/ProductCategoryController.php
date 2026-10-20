<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Inventory\CreateProductCategoryAction;
use App\Actions\Inventory\DeleteProductCategoryAction;
use App\Actions\Inventory\ReorderProductCategoriesAction;
use App\Actions\Inventory\UpdateProductCategoryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\ReorderProductCategoriesRequest;
use App\Http\Requests\Inventory\StoreProductCategoryRequest;
use App\Http\Requests\Inventory\UpdateProductCategoryRequest;
use App\Models\ProductCategory;
use App\Services\Inventory\ProductCategoryOptions;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Categories tab of Inventory: the categories (and their subcategories) items are filed under,
 * and whether each carries vintages. Reading needs `inventory.view`, every write `inventory.manage`
 * — the same gates as the inventory itself (routes/web.php).
 */
class ProductCategoryController extends Controller
{
    public function index(ProductCategoryOptions $options): Response
    {
        return Inertia::render('Inventory/Categories', ['categories' => $options->tree()]);
    }

    public function store(StoreProductCategoryRequest $request, CreateProductCategoryAction $action): RedirectResponse
    {
        /** @var array{name: string, type?: string, parent_id?: ?string, has_vintage?: bool} $validated */
        $validated = $request->validated();
        $action->execute($validated);

        return back()->with('success', __('Category added.'));
    }

    public function update(UpdateProductCategoryRequest $request, ProductCategory $category, UpdateProductCategoryAction $action): RedirectResponse
    {
        /** @var array{name?: string, has_vintage?: bool} $validated */
        $validated = $request->validated();
        $action->execute($category, $validated);

        return back()->with('success', __('Category updated.'));
    }

    public function destroy(ProductCategory $category, DeleteProductCategoryAction $action): RedirectResponse
    {
        $action->execute($category);

        return back()->with('success', __('Category deleted.'));
    }

    public function reorder(ReorderProductCategoriesRequest $request, ReorderProductCategoriesAction $action): RedirectResponse
    {
        /** @var list<string> $ids */
        $ids = array_values($request->array('ids'));
        $action->execute($ids);

        return back();
    }
}
