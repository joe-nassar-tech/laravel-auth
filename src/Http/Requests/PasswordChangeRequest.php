<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PasswordChangeRequest extends FormRequest
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
            'current_password'          => ['required', 'string'],
            'new_password'              => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
            'new_password_confirmation' => ['required', 'string'],
            'logout_all'                => ['sometimes', 'boolean'],
        ];
    }
}
