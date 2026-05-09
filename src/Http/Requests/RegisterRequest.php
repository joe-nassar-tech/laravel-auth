<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
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
        $base = [
            'email' => ['required', 'email', 'max:255'],
        ];

        /** @var array<string, mixed> $extra */
        $extra = config('auth_system.registration.extra_fields_rules', []);

        return array_merge($base, $extra);
    }
}
