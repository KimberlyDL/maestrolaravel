<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 'first_name' => ['nullable', 'string', 'max:100'],
            // 'last_name'  => ['nullable', 'string', 'max:100'],
            'name'  => ['nullable', 'string', 'max:100'],
            'phone'      => ['nullable', 'string', 'max:50'],
            'address'    => ['nullable', 'string', 'max:255'],
            'city'       => ['nullable', 'string', 'max:100'],
            'country'    => ['nullable', 'string', 'max:100'],
            'bio'        => ['nullable', 'string', 'max:1000'],
            // no 'email' here — immutable
        ];
    }
}
