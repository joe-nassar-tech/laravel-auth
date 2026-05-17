<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeactivateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];

        if ((bool) config('auth_system.account.deactivation.require_password', true)) {
            $rules['password'] = ['required', 'string'];
        } else {
            $rules['password'] = ['sometimes', 'string'];
        }

        return $rules;
    }
}
