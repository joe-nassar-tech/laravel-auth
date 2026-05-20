<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Joe404\LaravelAuth\Models\Referral;

class ReferralAdminUpdateRequest extends FormRequest
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
            'status' => [
                'required',
                'string',
                'in:' . implode(',', [
                    Referral::STATUS_PENDING,
                    Referral::STATUS_VALID,
                    Referral::STATUS_SUSPICIOUS,
                    Referral::STATUS_BLOCKED,
                    Referral::STATUS_EXPIRED,
                ]),
            ],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
