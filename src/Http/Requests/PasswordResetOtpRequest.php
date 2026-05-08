<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PasswordResetOtpRequest extends FormRequest
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
        $otpLength = (int) config('auth_system.verification.otp_length', 6);

        return [
            'email'                 => ['required', 'string', 'email', 'max:255'],
            'otp'                   => ['required', 'string', "size:{$otpLength}"],
            'password'              => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ];
    }
}
