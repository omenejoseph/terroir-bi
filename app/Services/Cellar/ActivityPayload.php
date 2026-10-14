<?php

declare(strict_types=1);

namespace App\Services\Cellar;

use App\Enums\CellarActivityType;
use App\Models\CellarAnalysis;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The per-type shape of a planned activity's `payload`, validated on the
 * server (the prototype accepted any JSON and only failed at execution time):
 *
 *  ADDITION  {product_name, unit, quantity, enological_product_id?, category?, cost_per_unit?, dose_per_hl?, target_note?}
 *  ANALYSIS  {metrics: list of CellarAnalysis::PARAMETERS}
 *  TRANSFER  {from_vessel_id, to_vessel_id, liters}
 *  PROCESS   {kind, note?}
 *  TOPPING   {liters, source_vessel_id?, reason?}
 *  VINEYARD  {kind, parcel_id?, parcel_name?}
 *
 * Quantities are per activity (per vessel), in the product's own unit; money
 * is minor units. Once executed, the actions add `applied` with what was done.
 */
class ActivityPayload
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * Validate and normalise a payload. Errors are keyed under `$path`
     * (e.g. "activities.2.payload.quantity").
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function validate(CellarActivityType $type, array $payload, string $path = 'payload'): array
    {
        $rules = $this->rules($type);

        $validator = Validator::make($payload, $rules);
        if ($validator->fails()) {
            $messages = [];
            foreach ($validator->errors()->messages() as $key => $errors) {
                $messages["{$path}.{$key}"] = $errors;
            }
            throw ValidationException::withMessages($messages);
        }

        /** @var array<string, mixed> $clean */
        $clean = $validator->validated();

        return $clean;
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(CellarActivityType $type): array
    {
        $tenantId = $this->tenants->id();
        $vessel = Rule::exists('vessels', 'id')->where('tenant_id', $tenantId);

        return match ($type) {
            CellarActivityType::Addition => [
                'product_name' => ['required', 'string', 'max:255'],
                'unit' => ['required', 'string', 'max:50'],
                'quantity' => ['required', 'numeric', 'gt:0'],
                'enological_product_id' => ['sometimes', 'nullable', 'string', Rule::exists('enological_products', 'id')->where('tenant_id', $tenantId)],
                'category' => ['sometimes', 'nullable', 'string', 'max:255'],
                'cost_per_unit' => ['sometimes', 'nullable', 'integer', 'min:0'],
                'dose_per_hl' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
                'target_note' => ['sometimes', 'nullable', 'string', 'max:255'],
            ],
            CellarActivityType::Analysis => [
                'metrics' => ['required', 'array', 'min:1'],
                'metrics.*' => ['string', Rule::in(CellarAnalysis::PARAMETERS)],
            ],
            CellarActivityType::Transfer => [
                'from_vessel_id' => ['required', 'string', $vessel],
                'to_vessel_id' => ['required', 'string', 'different:from_vessel_id', $vessel],
                'liters' => ['required', 'numeric', 'gt:0'],
            ],
            CellarActivityType::Process => [
                'kind' => ['required', 'string', 'max:255'],
                'note' => ['sometimes', 'nullable', 'string'],
            ],
            CellarActivityType::Topping => [
                'liters' => ['required', 'numeric', 'gt:0'],
                'source_vessel_id' => ['sometimes', 'nullable', 'string', $vessel],
                'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            ],
            CellarActivityType::Vineyard => [
                'kind' => ['required', 'string', 'max:255'],
                'parcel_id' => ['sometimes', 'nullable', 'string', Rule::exists('vineyard_parcels', 'id')->where('tenant_id', $tenantId)],
                'parcel_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            ],
        };
    }
}
