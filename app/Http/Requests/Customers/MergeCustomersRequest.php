<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MergeCustomersRequest extends FormRequest
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
        $exists = Rule::exists('customers', 'id')->where('tenant_id', $tenantId);

        return [
            'winner_id' => ['required', 'string', $exists],
            'loser_ids' => ['required', 'array', 'min:1'],
            'loser_ids.*' => ['string', $exists],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $winner = $this->input('winner_id');
            /** @var list<string> $losers */
            $losers = (array) $this->input('loser_ids', []);

            if (in_array($winner, $losers, true)) {
                $validator->errors()->add('loser_ids', 'The winner cannot also be a loser.');
            }
        });
    }
}
