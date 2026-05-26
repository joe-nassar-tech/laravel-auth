<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the profile-completion payload submitted to
 * POST /auth/social/complete after a social (OAuth) sign-in for a brand-new
 * user. Reuses the host's configured registration field rules so the social
 * path enforces exactly the same requirements as the 3-step email flow —
 * required fields block, optional fields are validated only if present.
 */
class SocialRegisterCompleteRequest extends FormRequest
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
            'completion_token' => ['required', 'string', 'size:36'],
            'referral_code'    => ['nullable', 'string', 'max:64'],
        ];

        // Phone — same conditional rule the email RegisterRequest applies.
        if ((bool) config('auth_system.phone.enabled', false)) {
            $rule = (bool) config('auth_system.phone.required', false) ? 'required' : 'nullable';
            $base['phone'] = [$rule, 'string', 'max:32', 'regex:/^\+?[0-9 \-().]{6,32}$/'];
        }

        /** @var array<string, mixed> $extra */
        $extra = config('auth_system.registration.extra_fields_rules', []);

        // Applying the rules verbatim means `required` fields block and
        // `nullable`/`sometimes` fields are validated only when submitted —
        // i.e. the onboarding form must supply the required fields, optional
        // ones can be filled later.
        return array_merge($base, $extra);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        /** @var array<string, string> $messages */
        $messages = config('auth_system.registration.extra_fields_messages', []);

        return $messages;
    }
}
