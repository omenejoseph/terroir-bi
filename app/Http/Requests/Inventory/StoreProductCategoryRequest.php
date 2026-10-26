<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Enums\InventoryCategory;
use App\Models\ProductCategory;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', $this->uniqueAmongSiblings()],
            // A subcategory takes its parent's type; only a top-level category names one.
            'type' => [Rule::requiredIf(fn (): bool => ! $this->filled('parent_id')), 'nullable', Rule::enum(InventoryCategory::class)],
            'parent_id' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                $parent = ProductCategory::query()->whereKey($value)->first();

                if ($parent === null || $parent->isSubcategory()) {
                    $fail(__('Pick a top-level category to put this under.'));
                }
            }],
            'has_vintage' => ['sometimes', 'boolean'],
        ];
    }

    /** No two siblings (same type and parent) share a name, whatever the case. */
    protected function uniqueAmongSiblings(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $parentId = $this->input('parent_id');
            $parent = $parentId ? ProductCategory::query()->whereKey($parentId)->first() : null;
            $type = $parent?->type->value ?? $this->input('type');

            $exists = ProductCategory::query()
                ->where('type', $type)
                ->when($parentId, fn ($q) => $q->where('parent_id', $parentId), fn ($q) => $q->whereNull('parent_id'))
                ->when($this->route('category'), fn ($q, $own) => $q->whereKeyNot($own instanceof ProductCategory ? $own->getKey() : $own))
                ->get()
                ->contains(fn (ProductCategory $c): bool => mb_strtolower($c->name) === mb_strtolower(trim((string) $value)));

            if ($exists) {
                $fail(__('There is already a category with that name here.'));
            }
        };
    }
}
