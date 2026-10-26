<?php

declare(strict_types=1);

namespace App\Http\Requests\Accommodation;

use Illuminate\Foundation\Http\FormRequest;

/** A villa's details and the texts its guest portal shows. */
class VillaRequest extends FormRequest
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
        $text = ['nullable', 'string', 'max:20000'];
        $short = ['nullable', 'string', 'max:255'];

        return [
            'name' => ['required', 'string', 'max:255'],
            'address' => $short,
            'coordinates' => $short,
            'wifi_name' => $short,
            'wifi_password' => $short,
            'check_in_time' => ['nullable', 'date_format:H:i'],
            'check_out_time' => ['nullable', 'date_format:H:i'],
            'instructions' => $text,
            'rules' => $text,
            'welcome' => $text,
            'transport' => $text,
            'amenities' => $text,
            'emergency_phone' => $short,
            'contact_phone' => $short,
            'contact_email' => ['nullable', 'email', 'max:255'],
            'logo' => ['nullable', 'string', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
