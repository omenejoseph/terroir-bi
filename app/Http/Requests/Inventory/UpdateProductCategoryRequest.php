<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Models\ProductCategory;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProductCategoryRequest extends FormRequest
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
        /** @var ProductCategory $category */
        $category = $this->route('category');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail) use ($category): void {
                $taken = ProductCategory::query()
                    ->where('type', $category->type->value)
                    ->when($category->parent_id, fn ($q, $parent) => $q->where('parent_id', $parent), fn ($q) => $q->whereNull('parent_id'))
                    ->whereKeyNot($category->getKey())
                    ->get()
                    ->contains(fn (ProductCategory $c): bool => mb_strtolower($c->name) === mb_strtolower(trim((string) $value)));

                if ($taken) {
                    $fail(__('There is already a category with that name here.'));
                }
            }],
            'has_vintage' => ['sometimes', 'boolean'],
        ];
    }
}
