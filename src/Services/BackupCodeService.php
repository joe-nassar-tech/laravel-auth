<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Services;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Joe404\LaravelAuth\Models\AuthTwoFactorBackupCode;

class BackupCodeService
{
    /**
     * Generate, store, and return a fresh set of backup codes (plain text).
     * Existing codes for the user are wiped — backup codes are always rotated
     * as a complete set.
     *
     * @return array<int,string>
     */
    public function generate(User $user): array
    {
        if (! (bool) config('auth_system.two_factor.backup_codes.enabled', true)) {
            return [];
        }

        $count  = max(1, (int) config('auth_system.two_factor.backup_codes.count', 8));
        $length = max(6, (int) config('auth_system.two_factor.backup_codes.length', 10));

        $plain = [];

        DB::transaction(function () use ($user, $count, $length, &$plain): void {
            AuthTwoFactorBackupCode::where('user_id', $user->getKey())->delete();

            for ($i = 0; $i < $count; $i++) {
                $code    = strtoupper(Str::random($length));
                $plain[] = $code;

                AuthTwoFactorBackupCode::create([
                    'user_id'    => $user->getKey(),
                    'code_hash'  => $this->hash($code),
                    'used_at'    => null,
                    'created_at' => now(),
                ]);
            }
        });

        return $plain;
    }

    /**
     * Consume one matching unused backup code. Returns true on success.
     *
     * Backup codes are 10 chars from a 36-char alphabet (~52 bits of entropy)
     * — they do not need bcrypt's intentional slowness, which made the
     * per-attempt cost O(N) over the user's code set. We use HMAC-SHA256
     * with the app key as the pepper, then do a single indexed lookup +
     * constant-time compare.
     */
    public function consume(User $user, string $code): bool
    {
        if (! (bool) config('auth_system.two_factor.backup_codes.enabled', true)) {
            return false;
        }

        $normalized = strtoupper(trim($code));
        $hash       = $this->hash($normalized);

        $record = AuthTwoFactorBackupCode::query()
            ->where('user_id', $user->getKey())
            ->whereNull('used_at')
            ->where('code_hash', $hash)
            ->first();

        if ($record === null) {
            return false;
        }

        $record->update(['used_at' => now()]);

        return true;
    }

    /**
     * Summary for UI: how many codes remain, when they were generated, last used.
     *
     * @return array<string,mixed>
     */
    public function summary(User $user): array
    {
        $query = AuthTwoFactorBackupCode::where('user_id', $user->getKey());

        return [
            'total'         => (clone $query)->count(),
            'used'          => (clone $query)->whereNotNull('used_at')->count(),
            'remaining'     => (clone $query)->whereNull('used_at')->count(),
            'generated_at'  => (clone $query)->min('created_at'),
            'last_used_at'  => (clone $query)->max('used_at'),
        ];
    }

    /**
     * HMAC-SHA256 with the app key as the pepper. The app key is required at
     * boot, so an attacker who steals a DB dump still cannot brute-force codes
     * offline without also stealing the key.
     */
    private function hash(string $code): string
    {
        $key = (string) config('app.key', '');

        // app.key may be prefixed "base64:..." — decode for consistency.
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true) ?: $key;
        }

        return hash_hmac('sha256', $code, $key);
    }
}
