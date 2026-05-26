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
            'email'         => ['required', 'email', 'max:255'],
            // Optional. When the referral feature is enabled (and a code is
            // submitted) the package looks it up and stores the relationship
            // at the end of registration. Validation here is just type+length
            // — the "code exists" check happens in ReferralService so we
            // return a localised error envelope instead of a 422 form-error.
            'referral_code' => ['nullable', 'string', 'max:64'],
        ];

        // v2.6 Phone — opt-in via config. When phone.enabled is false the
        // field is ignored regardless of payload. When phone.required is true
        // submission must include a valid phone.
        if ((bool) config('auth_system.phone.enabled', false)) {
            $rule = (bool) config('auth_system.phone.required', false) ? 'required' : 'nullable';
            // Accept formatted input (spaces, dashes, parens) — PhoneVerificationService
            // strips non-digits via normalizePhone() before storage.
            $base['phone'] = [$rule, 'string', 'max:32', 'regex:/^\+?[0-9 \-().]{6,32}$/'];
        }

        /** @var array<string, mixed> $extra */
        $extra = config('auth_system.registration.extra_fields_rules', []);

        return array_merge($base, $extra);
    }

    /**
     * Custom validation messages for extra fields.
     *
     * Host apps configure these via auth_system.registration.extra_fields_messages
     * using standard Laravel "field.rule" → message syntax.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        /** @var array<string, string> $messages */
        $messages = config('auth_system.registration.extra_fields_messages', []);

        return $messages;
    }
}
