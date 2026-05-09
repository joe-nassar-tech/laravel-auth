<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApiTokenUpdateRequest extends FormRequest
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
            'abilities'       => ['sometimes', 'array'],
            'abilities.*'     => ['string', 'max:100'],
            'expires_in_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
        ];
    }
}
