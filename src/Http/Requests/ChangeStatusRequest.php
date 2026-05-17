<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Joe404\LaravelAuth\Support\AccountStatus;

class ChangeStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status'           => ['required', 'string', Rule::in(AccountStatus::allowed())],
            'reason'           => ['sometimes', 'nullable', 'string', 'max:1000'],
            'comment'          => ['sometimes', 'nullable', 'string', 'max:5000'],

            // Timed-ban inputs. Send either one; if both are present, expires_at wins.
            // expires_at      → absolute ISO 8601 (e.g. "2026-07-17T12:00:00Z"), must be in the future
            // duration_minutes → relative integer (e.g. 120 = 2h, 43200 = 30d)
            'expires_at'       => ['sometimes', 'nullable', 'date', 'after:now'],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:5256000'], // ~10 years cap
        ];
    }

    /**
     * Reject `expires_at` / `duration_minutes` for statuses the config does
     * not mark as "temporary ban" capable. Without this, an admin could
     * accidentally schedule auto-unban for a status the host considers
     * manual-action-only (typically `disabled`).
     */
    public function withValidator(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $validator->after(function ($v): void {
            $hasExpiry = $this->filled('expires_at') || $this->filled('duration_minutes');

            if (! $hasExpiry) {
                return;
            }

            $status     = (string) $this->input('status');
            $temporary  = array_map('strval', (array) config(
                'auth_system.account.status.auto_unban.temporary_statuses',
                ['suspended'],
            ));

            if (! in_array($status, $temporary, true)) {
                $msg = sprintf(
                    'Status "%s" does not support timed bans. Remove expires_at / duration_minutes, or use one of: %s.',
                    $status,
                    implode(', ', $temporary) ?: '(none configured)',
                );
                $v->errors()->add('status', $msg);
            }
        });
    }

    /**
     * Resolve the effective expiry the admin wants applied. Null means a
     * permanent ban (or N/A when status is being set back to "active").
     */
    public function resolveExpiresAt(): ?Carbon
    {
        if ($this->filled('expires_at')) {
            return Carbon::parse((string) $this->input('expires_at'));
        }

        if ($this->filled('duration_minutes')) {
            return now()->addMinutes((int) $this->input('duration_minutes'));
        }

        return null;
    }
}
