<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterCompleteRequest extends FormRequest
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
            'completion_token'      => ['required', 'string', 'uuid'],
            'password'              => ['required', 'confirmed', $this->passwordRule()],
            'password_confirmation' => ['required', 'string'],
        ];
    }

    private function passwordRule(): Password
    {
        $rule = Password::min((int) config('auth_system.password.min_length', 8));

        if ((bool) config('auth_system.password.require_uppercase', false)) {
            $rule = $rule->mixedCase();
        }

        if ((bool) config('auth_system.password.require_number', false)) {
            $rule = $rule->numbers();
        }

        if ((bool) config('auth_system.password.require_special', false)) {
            $rule = $rule->symbols();
        }

        return $rule;
    }
}
