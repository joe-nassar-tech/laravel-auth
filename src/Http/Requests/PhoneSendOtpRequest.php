<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PhoneSendOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'phone'   => ['required', 'string', 'max:32', 'regex:/^\+?[0-9 \-().]{6,32}$/'],
            'channel' => ['nullable', 'string', 'in:sms,voice,whatsapp'],
        ];
    }
}
