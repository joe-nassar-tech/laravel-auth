<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PasswordForgotRequest extends FormRequest
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
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }
}
