<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Models\InventoryItem;
use App\Support\Measure;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An item's recipe, replaced as a whole. A line is one of three things, told apart by what it carries:
 * `input_id` (a catalog item), `wine_lot_id` (wine from the cellar) or `custom_name` (a free-text
 * ingredient with its own `custom_cost`, in minor units per `custom_unit`). `unit` is the unit
 * `quantity` is entered in, when that is a convertible one (ml, cl, l, g, kg).
 */
class SetRecipeRequest extends FormRequest
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
        $tenantId = app(TenantContext::class)->id();
        $itemId = $this->route('item');
        $itemId = is_object($itemId) && method_exists($itemId, 'getKey') ? $itemId->getKey() : $itemId;

        return [
            'items' => ['present', 'array'],
            'items.*.input_id' => [
                'nullable', 'string',
                // Inputs must belong to the same tenant, and not be the item itself.
                Rule::exists('inventory_items', 'id')->where('tenant_id', $tenantId),
                'not_in:'.$itemId,
            ],
            'items.*.wine_lot_id' => [
                'nullable', 'string',
                Rule::exists('wine_lots', 'id')->where('tenant_id', $tenantId),
            ],
            'items.*.custom_name' => ['nullable', 'string', 'max:255'],
            'items.*.custom_unit' => ['nullable', 'string', 'max:50'],
            'items.*.custom_cost' => ['nullable', 'integer', 'min:0'],
            'items.*.unit' => ['nullable', 'string', Rule::in(['ml', 'cl', 'l', 'g', 'kg'])],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $rows = (array) $this->input('items', []);
            $inputs = InventoryItem::query()->whereIn('id', array_filter(array_column($rows, 'input_id'), 'is_string'))->pluck('unit', 'id');
            $seen = [];

            foreach ($rows as $i => $row) {
                $identity = array_filter([
                    'input_id' => $row['input_id'] ?? null,
                    'wine_lot_id' => $row['wine_lot_id'] ?? null,
                    'custom_name' => filled($row['custom_name'] ?? null) ? $row['custom_name'] : null,
                ], fn (mixed $value): bool => $value !== null && $value !== '');

                if (count($identity) !== 1) {
                    $v->errors()->add("items.{$i}.input_id", __('Each line is one catalog item, one cellar wine or one custom item.'));

                    continue;
                }

                $key = array_key_first($identity);
                $marker = $key === 'custom_name' ? null : $key.':'.$identity[$key];

                if ($marker !== null && isset($seen[$marker])) {
                    $v->errors()->add("items.{$i}.{$key}", __('That is already in the recipe.'));
                }
                $seen[$marker ?? "custom:{$i}"] = true;

                $unit = $row['unit'] ?? null;
                if ($unit !== null && $unit !== '') {
                    $allowed = match ($key) {
                        'input_id' => Measure::options($inputs[$row['input_id']] ?? null),
                        'wine_lot_id' => Measure::options('l'),
                        default => Measure::options($row['custom_unit'] ?? null),
                    };

                    if (! in_array($unit, $allowed, true)) {
                        $v->errors()->add("items.{$i}.unit", __('That unit does not convert for this ingredient.'));
                    }
                }
            }
        });
    }

    /**
     * The validated lines in the shape SetRecipeAction takes, shared by the web and API controllers.
     *
     * @return list<array{input_id: ?string, wine_lot_id: ?string, quantity: string, unit: ?string, custom_name: ?string, custom_unit: ?string, custom_cost: ?int}>
     */
    public function lines(): array
    {
        // validated() builds each row in the order its rules ran, so a later line can come out first; restore the sent order.
        $items = (array) $this->validated('items', []);
        ksort($items);

        return array_values(array_map(
            fn (array $line): array => [
                'input_id' => filled($line['input_id'] ?? null) ? (string) $line['input_id'] : null,
                'wine_lot_id' => filled($line['wine_lot_id'] ?? null) ? (string) $line['wine_lot_id'] : null,
                'quantity' => (string) $line['quantity'],
                'unit' => filled($line['unit'] ?? null) ? (string) $line['unit'] : null,
                'custom_name' => filled($line['custom_name'] ?? null) ? trim((string) $line['custom_name']) : null,
                'custom_unit' => filled($line['custom_unit'] ?? null) ? trim((string) $line['custom_unit']) : null,
                'custom_cost' => isset($line['custom_cost']) && $line['custom_cost'] !== '' ? (int) $line['custom_cost'] : null,
            ],
            $items,
        ));
    }
}
