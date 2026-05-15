<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Services;

use Illuminate\Support\Str;
use Joe404\LaravelAuth\Contracts\ReferralCodeGeneratorContract;

class DefaultReferralCodeGenerator implements ReferralCodeGeneratorContract
{
    public function generate(): string
    {
        $userModel = (string) config('auth.providers.users.model', \App\Models\User::class);
        $column    = (string) config('auth_system.referral_code.column', 'referral_code');
        $length    = (int) config('auth_system.referral_code.length', 10);
        $uppercase = (bool) config('auth_system.referral_code.uppercase', true);

        // Cap retries to avoid infinite loops if the column is exhausted
        // (effectively impossible for length>=8, but defensive nonetheless).
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = Str::random($length);

            if ($uppercase) {
                $code = strtoupper($code);
            }

            if (! $userModel::where($column, $code)->exists()) {
                return $code;
            }
        }

        throw new \RuntimeException(
            'Could not generate a unique referral code after 10 attempts. '
            . 'Increase auth_system.referral_code.length.',
        );
    }
}
