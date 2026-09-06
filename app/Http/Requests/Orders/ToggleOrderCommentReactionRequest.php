<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Models\OrderNoteReaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ToggleOrderCommentReactionRequest extends FormRequest
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
            'emoji' => ['required', 'string', Rule::in(OrderNoteReaction::EMOJI)],
        ];
    }
}
