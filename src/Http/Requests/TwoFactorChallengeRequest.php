<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TwoFactorChallengeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'challenge_token' => ['required', 'string', 'size:36'],
            'code'            => ['required', 'string', 'min:4', 'max:32'],
            'method'          => ['nullable', 'string', 'in:totp,email,sms,backup'],
            'trust_device'    => ['nullable', 'boolean'],
        ];
    }
}
