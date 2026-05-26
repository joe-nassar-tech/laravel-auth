<?php

declare(strict_types=1);

/**
 * Programmatically appends a "v2.6 — Phone, 2FA & Trusted Devices" folder
 * to the joe-404/laravel-auth Postman collection. Idempotent: re-running
 * removes any prior v2.6 folder before appending the fresh one.
 *
 * Run: php scripts/postman-v260.php
 */

$path = __DIR__ . '/../joe-404-laravel-auth.postman_collection.json';

if (! is_file($path)) {
    fwrite(STDERR, "Collection file not found: {$path}\n");
    exit(1);
}

$collection = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

$folderName = 'v2.6 — Phone, 2FA & Trusted Devices';

// Drop any previous v2.6 folder so reruns produce a clean diff.
$collection['item'] = array_values(array_filter(
    $collection['item'] ?? [],
    fn (array $folder) => ($folder['name'] ?? '') !== $folderName,
));

// ----- helpers ---------------------------------------------------------------

function jsonBody(array $payload): string
{
    return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function bearerHeaders(): array
{
    return [
        ['key' => 'Content-Type',  'value' => 'application/json'],
        ['key' => 'Accept',        'value' => 'application/json'],
        ['key' => 'Authorization', 'value' => 'Bearer {{auth_token}}'],
    ];
}

function noAuthHeaders(): array
{
    return [
        ['key' => 'Content-Type', 'value' => 'application/json'],
        ['key' => 'Accept',       'value' => 'application/json'],
    ];
}

function url(string $path): array
{
    $clean = ltrim($path, '/');
    $parts = explode('/', $clean);

    return [
        'raw'  => '{{base_url}}/' . $clean,
        'host' => ['{{base_url}}'],
        'path' => $parts,
    ];
}

function response(string $name, int $code, string $status, string $body, array $originalRequest): array
{
    return [
        'name'                     => $name,
        'originalRequest'          => $originalRequest,
        'status'                   => $status,
        'code'                     => $code,
        '_postman_previewlanguage' => 'json',
        'header'                   => [
            ['key' => 'Content-Type', 'value' => 'application/json'],
        ],
        'cookie' => [],
        'body'   => $body,
    ];
}

/**
 * Build a single endpoint item. $cases is array of:
 *   ['name'=>..., 'code'=>..., 'status'=>..., 'body'=>jsonString,
 *    'requestBody'=>jsonString|null  // optional override (for showing the failing payload)
 *   ]
 */
function endpoint(string $name, string $method, string $urlPath, ?string $requestBody, string $description, array $cases, bool $auth = true, array $events = []): array
{
    $headers = $auth ? bearerHeaders() : noAuthHeaders();

    $request = [
        'method'      => $method,
        'header'      => $headers,
        'url'         => url($urlPath),
        'description' => $description,
    ];

    if ($requestBody !== null) {
        $request['body'] = ['mode' => 'raw', 'raw' => $requestBody];
    }

    $responses = [];

    foreach ($cases as $case) {
        $originalRequest = [
            'method' => $method,
            'header' => $headers,
            'url'    => url($urlPath),
        ];

        $body = $case['requestBody'] ?? $requestBody;

        if ($body !== null) {
            $originalRequest['body'] = ['mode' => 'raw', 'raw' => $body];
        }

        $responses[] = response(
            $case['name'],
            $case['code'],
            $case['status'],
            $case['body'],
            $originalRequest,
        );
    }

    $item = [
        'name'     => $name,
        'request'  => $request,
        'response' => $responses,
    ];

    if ($events !== []) {
        $item['event'] = $events;
    }

    return $item;
}

// ----- folder content --------------------------------------------------------

$folder = [
    'name'        => $folderName,
    'description' => "v2.6 endpoints — phone capture + verification, two-factor authentication (TOTP / Email / SMS), backup codes, login challenge flow, password sudo mode, and trusted devices.\n\nAll requests in this folder assume Bearer Token auth (`{{auth_token}}`). For SPA/cookie mode add the `X-XSRF-TOKEN` header and remove `Authorization`.\n\n**Trusted-device 2FA bypass (security model):** a device only skips the 2FA challenge when the login request carries BOTH `X-Browser-Fingerprint` (the device fingerprint) AND `X-Trusted-Device-Token` (a one-time secret issued when the device was trusted). Fingerprint alone never grants bypass. The token is returned once by `POST /auth/2fa/challenge` when `trust_device=true` and is auto-saved to `{{trusted_device_token}}`.\n\n**Test variables added by these endpoints:**\n- `challenge_token` — saved by Login when 2FA is enrolled\n- `totp_secret` — saved by TOTP enrollment start (for use in QR/manual entry)\n- `two_factor_method_id` — saved by 2FA methods list\n- `trusted_device_id` — saved by Trusted Devices list\n- `trusted_device_token` — saved by 2FA challenge when trust_device=true; send as X-Trusted-Device-Token on future logins",
    'item'        => [],
];

// ============================================================================
// PHONE
// ============================================================================

$folder['item'][] = [
    'name' => 'Phone Verification',
    'item' => [
        endpoint(
            name: 'Send Phone OTP',
            method: 'POST',
            urlPath: 'auth/phone/send-otp',
            requestBody: jsonBody(['phone' => '+14155550101', 'channel' => 'sms']),
            description: "Sends a one-time verification code to the supplied phone number via the configured channel (sms | voice | whatsapp). The default channel comes from `auth_system.phone.verification.default_channel`.\n\n**Feature-gated:** returns 404 when `phone.enabled` is false.\n\n**Errors:**\n- 404 phone feature disabled\n- 422 invalid phone format or driver misconfigured\n- 429 rate limited",
            cases: [
                [
                    'name'   => '200 — OTP sent',
                    'code'   => 200,
                    'status' => 'OK',
                    'body'   => jsonBody([
                        'success' => true,
                        'message' => 'Phone verification code sent.',
                        'data'    => [
                            'channel'    => 'sms',
                            'expires_at' => '2026-05-23T12:05:00+00:00',
                        ],
                    ]),
                ],
                [
                    'name'        => '422 — Invalid phone format',
                    'code'        => 422,
                    'status'      => 'Unprocessable Entity',
                    'requestBody' => jsonBody(['phone' => 'not-a-phone']),
                    'body'        => jsonBody([
                        'success' => false,
                        'message' => 'The phone field format is invalid.',
                        'errors'  => [
                            'phone' => ['The phone field format is invalid.'],
                        ],
                    ]),
                ],
                [
                    'name'        => '422 — Provider misconfigured',
                    'code'        => 422,
                    'status'      => 'Unprocessable Entity',
                    'body'        => jsonBody([
                        'success' => false,
                        'message' => 'Infobip api_key is not configured.',
                        'errors'  => [],
                    ]),
                ],
                [
                    'name'   => '404 — Phone feature disabled',
                    'code'   => 404,
                    'status' => 'Not Found',
                    'body'   => jsonBody([
                        'success' => false,
                        'message' => 'Phone feature is disabled.',
                        'errors'  => [],
                    ]),
                ],
                [
                    'name'   => '429 — Rate limited',
                    'code'   => 429,
                    'status' => 'Too Many Requests',
                    'body'   => jsonBody([
                        'success' => false,
                        'message' => 'Too many requests. Please try again later.',
                        'errors'  => [],
                    ]),
                ],
            ],
        ),
        endpoint(
            name: 'Verify Phone Code',
            method: 'POST',
            urlPath: 'auth/phone/verify',
            requestBody: jsonBody(['phone' => '+14155550101', 'code' => '482910']),
            description: "Verifies the OTP code sent to the phone. On success, stamps `phone_verified_at` on the authenticated user. Codes are single-use — even a valid code is rejected on the second attempt.\n\n**Errors:**\n- 422 phone_otp_invalid — wrong code (attempt counter bumps)\n- 422 phone_otp_expired — past expiry window\n- 422 phone_otp_locked — too many failed attempts\n- 422 phone_otp_not_found — no active code for this phone",
            cases: [
                [
                    'name'   => '200 — Phone verified',
                    'code'   => 200,
                    'status' => 'OK',
                    'body'   => jsonBody([
                        'success' => true,
                        'message' => 'Phone verified successfully.',
                        'data'    => [
                            'phone'             => '+14155550101',
                            'phone_verified_at' => '2026-05-23T12:01:30+00:00',
                        ],
                    ]),
                ],
                [
                    'name'        => '422 — Invalid code',
                    'code'        => 422,
                    'status'      => 'Unprocessable Entity',
                    'requestBody' => jsonBody(['phone' => '+14155550101', 'code' => '000000']),
                    'body'        => jsonBody([
                        'success' => false,
                        'message' => 'Invalid phone code.',
                        'errors'  => [],
                    ]),
                ],
                [
                    'name'   => '422 — Code expired',
                    'code'   => 422,
                    'status' => 'Unprocessable Entity',
                    'body'   => jsonBody([
                        'success' => false,
                        'message' => 'Phone code has expired.',
                        'errors'  => [],
                    ]),
                ],
                [
                    'name'   => '422 — Too many attempts',
                    'code'   => 422,
                    'status' => 'Unprocessable Entity',
                    'body'   => jsonBody([
                        'success' => false,
                        'message' => 'Too many attempts; request a new code.',
                        'errors'  => [],
                    ]),
                ],
                [
                    'name'   => '422 — No active code',
                    'code'   => 422,
                    'status' => 'Unprocessable Entity',
                    'body'   => jsonBody([
                        'success' => false,
                        'message' => 'No active phone code for this number.',
                        'errors'  => [],
                    ]),
                ],
            ],
        ),
    ],
];

// ============================================================================
// 2FA METHOD MANAGEMENT
// ============================================================================

$folder['item'][] = [
    'name' => '2FA — Method Management',
    'item' => [
        endpoint(
            name: 'List 2FA Methods',
            method: 'GET',
            urlPath: 'auth/2fa/methods',
            requestBody: null,
            description: "Lists the authenticated user's enrolled 2FA methods plus a summary of remaining backup codes. The first script auto-saves the first method id to `two_factor_method_id`.",
            events: [[
                'listen' => 'test',
                'script' => [
                    'type' => 'text/javascript',
                    'exec' => [
                        "if (pm.response.code === 200) {",
                        "    const methods = pm.response.json().data && pm.response.json().data.methods;",
                        "    if (methods && methods.length > 0) {",
                        "        pm.collectionVariables.set('two_factor_method_id', methods[0].id);",
                        "        console.log('two_factor_method_id saved:', methods[0].id);",
                        "    }",
                        "}",
                    ],
                ],
            ]],
            cases: [
                [
                    'name'   => '200 — Methods retrieved',
                    'code'   => 200,
                    'status' => 'OK',
                    'body'   => jsonBody([
                        'success' => true,
                        'message' => '2FA methods retrieved.',
                        'data'    => [
                            'methods' => [
                                [
                                    'id'           => 14,
                                    'type'         => 'totp',
                                    'is_default'   => true,
                                    'verified_at'  => '2026-05-22T10:14:00.000000Z',
                                    'last_used_at' => '2026-05-23T11:00:00.000000Z',
                                    'created_at'   => '2026-05-22T10:13:00.000000Z',
                                ],
                                [
                                    'id'           => 15,
                                    'type'         => 'email',
                                    'is_default'   => false,
                                    'verified_at'  => '2026-05-22T10:20:00.000000Z',
                                    'last_used_at' => null,
                                    'created_at'   => '2026-05-22T10:19:30.000000Z',
                                ],
                            ],
                            'backup_codes' => [
                                'total'        => 8,
                                'used'         => 1,
                                'remaining'    => 7,
                                'generated_at' => '2026-05-22T10:14:00.000000Z',
                                'last_used_at' => '2026-05-23T09:00:00.000000Z',
                            ],
                        ],
                    ]),
                ],
                [
                    'name'   => '401 — Unauthenticated',
                    'code'   => 401,
                    'status' => 'Unauthorized',
                    'body'   => jsonBody([
                        'success' => false,
                        'message' => 'Unauthenticated.',
                        'errors'  => [],
                    ]),
                ],
                [
                    'name'   => '404 — 2FA feature disabled',
                    'code'   => 404,
                    'status' => 'Not Found',
                    'body'   => jsonBody([
                        'success' => false,
                        'message' => 'Not Found',
                        'errors'  => [],
                    ]),
                ],
            ],
        ),
        endpoint(
            name: 'Start TOTP Enrollment',
            method: 'POST',
            urlPath: 'auth/2fa/enroll/totp/start',
            requestBody: '{}',
            description: "Generates a fresh TOTP secret + otpauth URI + inline SVG QR code. Saves the secret in the AuthTwoFactorMethod row as `verified_at = null`. The user scans the QR with Google Authenticator / Authy / 1Password / Bitwarden etc. and submits the first code via **Verify TOTP Enrollment** to complete enrollment.\n\nIf the user has no other verified 2FA method when verification succeeds, this is also the call where backup codes are generated (returned ONCE on the verify response).",
            events: [[
                'listen' => 'test',
                'script' => [
                    'type' => 'text/javascript',
                    'exec' => [
                        "if (pm.response.code === 200) {",
                        "    const secret = pm.response.json().data && pm.response.json().data.secret;",
                        "    if (secret) {",
                        "        pm.collectionVariables.set('totp_secret', secret);",
                        "        console.log('totp_secret saved:', secret);",
                        "    }",
                        "}",
                    ],
                ],
            ]],
            cases: [
                [
                    'name'   => '200 — Enrollment started',
                    'code'   => 200,
                    'status' => 'OK',
                    'body'   => jsonBody([
                        'success' => true,
                        'message' => 'Enrollment started.',
                        'data'    => [
                            'type'           => 'totp',
                            'secret'         => 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP',
                            'otpauth_uri'    => 'otpauth://totp/MyApp:user@example.com?secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP&issuer=MyApp&algorithm=SHA1&digits=6&period=30',
                            'qr_svg'         => '<svg xmlns="http://www.w3.org/2000/svg" ...></svg>',
                            'digits'         => 6,
                            'period_seconds' => 30,
                        ],
                    ]),
                ],
                [
                    'name'   => '422 — Method disabled in config',
                    'code'   => 422,
                    'status' => 'Unprocessable Entity',
                    'body'   => jsonBody([
                        'success' => false,
                        'message' => "2FA method 'totp' is not enabled.",
                        'errors'  => [],
                    ]),
                ],
            ],
        ),
        endpoint(
            name: 'Verify TOTP Enrollment',
            method: 'POST',
            urlPath: 'auth/2fa/enroll/totp/verify',
            requestBody: jsonBody(['code' => '482910']),
            description: "Verifies the first TOTP code from the authenticator app. On success the method becomes active and (if this is the user's first verified 2FA method) backup codes are generated and returned ONCE in the response.\n\n**Errors:**\n- 422 two_factor_method_not_enrolled — call /start first\n- 422 two_factor_code_invalid — wrong code",
            cases: [
                [
                    'name'   => '200 — Enrolled (with backup codes on first method)',
                    'code'   => 200,
                    'status' => 'OK',
                    'body'   => jsonBody([
                        'success' => true,
                        'message' => '2FA method enrolled.',
                        'data'    => [
                            'id'           => 14,
                            'type'         => 'totp',
                            'is_default'   => true,
                            'backup_codes' => [
                                '8K3M7P2QXR', 'DZ9F4L0HWA', '3X1NJ8VYBC', 'M2P7T4QKLR',
                                'A9D6E1NZHF', 'V0R8B3KSPQ', 'H7Y5C2GMLT', 'W4UQ1JX9NB',
                            ],
                        ],
                    ]),
                ],
                [
                    'name'        => '422 — Invalid code',
                    'code'        => 422,
                    'status'      => 'Unprocessable Entity',
                    'requestBody' => jsonBody(['code' => '000000']),
                    'body'        => jsonBody([
                        'success' => false,
                        'message' => 'Invalid 2FA code.',
                        'errors'  => [],
                    ]),
                ],
                [
                    'name'   => '422 — Not yet started',
                    'code'   => 422,
                    'status' => 'Unprocessable Entity',
                    'body'   => jsonBody([
                        'success' => false,
                        'message' => 'Start enrollment first.',
                        'errors'  => [],
                    ]),
                ],
            ],
        ),
        endpoint(
            name: 'Start Email 2FA Enrollment',
            method: 'POST',
            urlPath: 'auth/2fa/enroll/email/start',
            requestBody: '{}',
            description: "Sends a 6-digit code to the user's verified email address. Mask in the response is for UI display.",
            cases: [
                [
                    'name'   => '200 — Code sent',
                    'code'   => 200,
                    'status' => 'OK',
                    'body'   => jsonBody([
                        'success' => true,
                        'message' => 'Enrollment started.',
                        'data'    => [
                            'type'       => 'email',
                            'sent_to'    => 'jo***@example.com',
                            'expires_in' => 600,
                        ],
                    ]),
                ],
            ],
        ),
        endpoint(
            name: 'Verify Email 2FA Enrollment',
            method: 'POST',
            urlPath: 'auth/2fa/enroll/email/verify',
            requestBody: jsonBody(['code' => '482910']),
            description: 'Verifies the code emailed during email 2FA enrollment.',
            cases: [
                [
                    'name'   => '200 — Enrolled',
                    'code'   => 200,
                    'status' => 'OK',
                    'body'   => jsonBody([
                        'success' => true,
                        'message' => '2FA method enrolled.',
                        'data'    => [
                            'id'         => 15,
                            'type'       => 'email',
                            'is_default' => false,
                        ],
                    ]),
                ],
                [
                    'name'        => '422 — Invalid code',
                    'code'        => 422,
                    'status'      => 'Unprocessable Entity',
                    'requestBody' => jsonBody(['code' => '000000']),
                    'body'        => jsonBody([
                        'success' => false,
                        'message' => 'Invalid 2FA code.',
                        'errors'  => [],
                    ]),
                ],
            ],
        ),
        endpoint(
            name: 'Start SMS 2FA Enrollment',
            method: 'POST',
            urlPath: 'auth/2fa/enroll/sms/start',
            requestBody: '{}',
            description: "Sends a 6-digit code to the user's verified phone via the configured SMS channel. Requires `phone_verified_at` to be set — otherwise returns 422.",
            cases: [
                [
                    'name'   => '200 — Code sent',
                    'code'   => 200,
                    'status' => 'OK',
                    'body'   => jsonBody([
                        'success' => true,
                        'message' => 'Enrollment started.',
                        'data'    => [
                            'type'       => 'sms',
                            'sent_to'    => '*******0101',
                            'expires_in' => 300,
                        ],
                    ]),
                ],
                [
                    'name'   => '422 — No verified phone on file',
                    'code'   => 422,
                    'status' => 'Unprocessable Entity',
                    'body'   => jsonBody([
                        'success' => false,
                        'message' => 'A verified phone is required before enrolling in SMS 2FA.',
                        'errors'  => [],
                    ]),
                ],
            ],
        ),
        endpoint(
            name: 'Verify SMS 2FA Enrollment',
            method: 'POST',
            urlPath: 'auth/2fa/enroll/sms/verify',
            requestBody: jsonBody(['code' => '482910']),
            description: 'Verifies the code received via SMS during SMS 2FA enrollment.',
            cases: [
                [
                    'name'   => '200 — Enrolled',
                    'code'   => 200,
                    'status' => 'OK',
                    'body'   => jsonBody([
                        'success' => true,
                        'message' => '2FA method enrolled.',
                        'data'    => [
                            'id'         => 16,
                            'type'       => 'sms',
                            'is_default' => false,
                        ],
                    ]),
                ],
            ],
        ),
        endpoint(
            name: 'Set Default Method',
            method: 'POST',
            urlPath: 'auth/2fa/methods/{{two_factor_method_id}}/default',
            requestBody: null,
            description: "Marks the given method as the default one used at challenge time. Only one method can be the default at any time.",
            cases: [
                [
                    'name'   => '200 — Default updated',
                    'code'   => 200,
                    'status' => 'OK',
                    'body'   => jsonBody([
                        'success' => true,
                        'message' => 'Default 2FA method updated.',
                        'data'    => ['id' => 15, 'type' => 'email'],
                    ]),
                ],
                [
                    'name'   => '422 — Method not found or unverified',
                    'code'   => 422,
                    'status' => 'Unprocessable Entity',
                    'body'   => jsonBody([
                        'success' => false,
                        'message' => 'Method not found or not verified.',
                        'errors'  => [],
                    ]),
                ],
            ],
        ),
        endpoint(
            name: 'Remove 2FA Method',
            method: 'DELETE',
            urlPath: 'auth/2fa/methods/{{two_factor_method_id}}',
            requestBody: null,
            description: "Disables and deletes a 2FA method. If the user has no other enrolled methods after removal, the backup codes are deleted as well.",
            cases: [
                [
                    'name'   => '200 — Removed',
                    'code'   => 200,
                    'status' => 'OK',
                    'body'   => jsonBody([
                        'success' => true,
                        'message' => '2FA method removed.',
                        'data'    => [],
                    ]),
                ],
                [
                    'name'   => '422 — Method not found',
                    'code'   => 422,
                    'status' => 'Unprocessable Entity',
                    'body'   => jsonBody([
                        'success' => false,
                        'message' => 'Method not found.',
                        'errors'  => [],
                    ]),
                ],
            ],
        ),
        endpoint(
            name: 'Backup Codes Summary',
            method: 'GET',
            urlPath: 'auth/2fa/backup-codes',
            requestBody: null,
            description: "Returns metadata about the user's backup codes — total, used, remaining, last used. Never returns the plaintext codes (those are shown only once at generation time).",
            cases: [
                [
                    'name'   => '200 — Summary',
                    'code'   => 200,
                    'status' => 'OK',
                    'body'   => jsonBody([
                        'success' => true,
                        'message' => 'Backup codes summary.',
                        'data'    => [
                            'total'        => 8,
                            'used'         => 2,
                            'remaining'    => 6,
                            'generated_at' => '2026-05-22T10:14:00.000000Z',
                            'last_used_at' => '2026-05-23T11:00:00.000000Z',
                        ],
                    ]),
                ],
            ],
        ),
        endpoint(
            name: 'Regenerate Backup Codes',
            method: 'POST',
            urlPath: 'auth/2fa/backup-codes/regenerate',
            requestBody: '{}',
            description: "Wipes the existing backup code set and returns a fresh 8-code set. The plaintext codes are shown ONCE — store them on the client immediately.",
            cases: [
                [
                    'name'   => '200 — New set issued',
                    'code'   => 200,
                    'status' => 'OK',
                    'body'   => jsonBody([
                        'success' => true,
                        'message' => 'Backup codes regenerated.',
                        'data'    => [
                            'backup_codes' => [
                                'NEW1QXR7K2', 'NEW2L9F4DH', 'NEW3J8VYBC', 'NEW4P7T4QM',
                                'NEW5D6E1NZ', 'NEW6R8B3KS', 'NEW7Y5C2GM', 'NEW8UQ1JX9',
                            ],
                        ],
                    ]),
                ],
            ],
        ),
    ],
];

// ============================================================================
// 2FA LOGIN CHALLENGE
// ============================================================================

$folder['item'][] = [
    'name' => '2FA — Login Challenge Flow',
    'item' => [
        endpoint(
            name: '1. Login (returns challenge_token when 2FA enrolled)',
            method: 'POST',
            urlPath: 'auth/login',
            requestBody: jsonBody(['email' => 'user@example.com', 'password' => 'Password123!']),
            description: "When the authenticated user has ≥1 verified 2FA method AND the device is not trusted at the bypass level, login returns a `challenge_token` instead of a real Sanctum token. The client then calls **Verify Challenge** with the challenge_token + the 2FA code to obtain the real token.\n\n**Trusted-device bypass (v2.6 security model):** to skip the 2FA challenge, the client must send BOTH the `X-Browser-Fingerprint` header AND the `X-Trusted-Device-Token` header obtained when the device was first trusted. Fingerprint alone is NOT enough — it is client-controlled and could be forged. When both match a non-revoked device at `>= bypass_2fa_min_level`, login issues a real token directly (no `requires_2fa`).\n\nThe test script auto-saves the `challenge_token` for the next request and persists the `auth_token` when no challenge is needed.",
            auth: false,
            events: [[
                'listen' => 'test',
                'script' => [
                    'type' => 'text/javascript',
                    'exec' => [
                        "if (pm.response.code === 200) {",
                        "    const data = pm.response.json().data || {};",
                        "    if (data.challenge_token) {",
                        "        pm.collectionVariables.set('challenge_token', data.challenge_token);",
                        "        console.log('challenge_token saved:', data.challenge_token);",
                        "    } else if (data.token) {",
                        "        pm.collectionVariables.set('auth_token', data.token);",
                        "        if (data.refresh_token) pm.collectionVariables.set('refresh_token', data.refresh_token);",
                        "        console.log('auth_token saved (trusted-device bypass or no 2FA):', data.token);",
                        "    }",
                        "}",
                    ],
                ],
            ]],
            cases: [
                [
                    'name'   => '200 — 2FA required (challenge token)',
                    'code'   => 200,
                    'status' => 'OK',
                    'body'   => jsonBody([
                        'success' => true,
                        'message' => '2FA verification required.',
                        'data'    => [
                            'requires_2fa'      => true,
                            'challenge_token'   => 'a4d8f2c1-9b3e-4f7a-8d2c-1e5b6a7c8d9e',
                            'method'            => 'totp',
                            'available_methods' => ['totp', 'email'],
                            'masked_target'    => null,
                            'expires_in'        => 300,
                        ],
                    ]),
                ],
                [
                    'name'   => '200 — Trusted device bypass (fingerprint + X-Trusted-Device-Token)',
                    'code'   => 200,
                    'status' => 'OK',
                    'requestBody' => jsonBody(['email' => 'user@example.com', 'password' => 'Password123!']),
                    'body'   => jsonBody([
                        'success' => true,
                        'message' => 'Logged in successfully.',
                        'data'    => [
                            'user'          => ['id' => 1, 'email' => 'user@example.com', 'name' => 'user'],
                            'token'         => '3|a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6',
                            'refresh_token' => 'rt_3|trusteddevicebypass012345',
                        ],
                    ]),
                ],
                [
                    'name'   => '200 — No 2FA (real token issued)',
                    'code'   => 200,
                    'status' => 'OK',
                    'body'   => jsonBody([
                        'success' => true,
                        'message' => 'Logged in successfully.',
                        'data'    => [
                            'user'          => ['id' => 1, 'email' => 'user@example.com', 'name' => 'user'],
                            'token'         => '1|x9c0d8a2b6e4f3a1c5d7e9b0a2c4d6e8f0',
                            'refresh_token' => 'rt_1|9z8y7x6w5v4u3t2s1r0q',
                        ],
                    ]),
                ],
                [
                    'name'        => '401 — Wrong credentials',
                    'code'        => 401,
                    'status'      => 'Unauthorized',
                    'requestBody' => jsonBody(['email' => 'user@example.com', 'password' => 'wrong']),
                    'body'        => jsonBody([
                        'success' => false,
                        'message' => 'Invalid credentials.',
                        'errors'  => [],
                    ]),
                ],
            ],
        ),
        endpoint(
            name: '2. Verify Challenge',
            method: 'POST',
            urlPath: 'auth/2fa/challenge',
            requestBody: jsonBody([
                'challenge_token' => '{{challenge_token}}',
                'code'            => '482910',
                'method'          => 'totp',
                'trust_device'    => true,
            ]),
            description: "Completes the 2FA challenge. Pass the `code` from the user's TOTP app / email / SMS, the `method` they used, and `trust_device: true` to mark the device trusted so future logins skip 2FA.\n\n**When `trust_device: true`**, the response includes a one-time `trusted_device_token`. STORE IT — the client must send it back as the `X-Trusted-Device-Token` header (alongside `X-Browser-Fingerprint`) on future logins to bypass 2FA. It is returned exactly once and cannot be recovered. The test script auto-saves it to the `trusted_device_token` collection variable.\n\nIf the code is wrong, the challenge's `attempts` counter increments — after 5 failures the challenge is invalidated and the user must log in again.\n\nIf the supplied code is a backup code, set `method: \"backup\"` (or omit it — backup codes are tried as fallback automatically).",
            auth: false,
            events: [[
                'listen' => 'test',
                'script' => [
                    'type' => 'text/javascript',
                    'exec' => [
                        "if (pm.response.code === 200) {",
                        "    const data = pm.response.json().data || {};",
                        "    if (data.token) {",
                        "        pm.collectionVariables.set('auth_token', data.token);",
                        "        if (data.refresh_token) pm.collectionVariables.set('refresh_token', data.refresh_token);",
                        "        console.log('auth_token saved:', data.token);",
                        "    }",
                        "    if (data.trusted_device_token) {",
                        "        pm.collectionVariables.set('trusted_device_token', data.trusted_device_token);",
                        "        console.log('trusted_device_token saved — send as X-Trusted-Device-Token on future logins');",
                        "    }",
                        "}",
                    ],
                ],
            ]],
            cases: [
                [
                    'name'   => '200 — Verified, real token + trusted_device_token',
                    'code'   => 200,
                    'status' => 'OK',
                    'body'   => jsonBody([
                        'success' => true,
                        'message' => '2FA verified. Login complete.',
                        'data'    => [
                            'user'                 => ['id' => 1, 'email' => 'user@example.com'],
                            'token'                => '2|f8e7d6c5b4a3928171605f4e3d2c1b0a',
                            'refresh_token'        => 'rt_2|0a1b2c3d4e5f6g7h8i9j',
                            'trusted_device_token' => 'f4a8c2e0b6d4a1f3e5c7b9d0a2f4e6c8b0d2f4a6e8c0b2d4f6a8c0e2b4d6f8a0',
                        ],
                    ]),
                ],
                [
                    'name'   => '200 — Verified without trust (no trusted_device_token)',
                    'code'   => 200,
                    'status' => 'OK',
                    'requestBody' => jsonBody([
                        'challenge_token' => '{{challenge_token}}',
                        'code'            => '482910',
                        'method'          => 'totp',
                    ]),
                    'body'   => jsonBody([
                        'success' => true,
                        'message' => '2FA verified. Login complete.',
                        'data'    => [
                            'user'          => ['id' => 1, 'email' => 'user@example.com'],
                            'token'         => '2|f8e7d6c5b4a3928171605f4e3d2c1b0a',
                            'refresh_token' => 'rt_2|0a1b2c3d4e5f6g7h8i9j',
                        ],
                    ]),
                ],
                [
                    'name'        => '401 — Wrong code (attempt counter bumps)',
                    'code'        => 401,
                    'status'      => 'Unauthorized',
                    'requestBody' => jsonBody([
                        'challenge_token' => '{{challenge_token}}',
                        'code'            => '000000',
                        'method'          => 'totp',
                    ]),
                    'body'        => jsonBody([
                        'success' => false,
                        'message' => 'Invalid 2FA code.',
                        'errors'  => [],
                    ]),
                ],
                [
                    'name'   => '401 — Challenge expired',
                    'code'   => 401,
                    'status' => 'Unauthorized',
                    'body'   => jsonBody([
                        'success' => false,
                        'message' => 'Challenge expired.',
                        'errors'  => [],
                    ]),
                ],
                [
                    'name'   => '401 — Too many failed attempts (challenge invalidated)',
                    'code'   => 401,
                    'status' => 'Unauthorized',
                    'body'   => jsonBody([
                        'success' => false,
                        'message' => 'Too many failed attempts. Please log in again.',
                        'errors'  => [],
                    ]),
                ],
                [
                    'name'   => '401 — Invalid challenge token',
                    'code'   => 401,
                    'status' => 'Unauthorized',
                    'body'   => jsonBody([
                        'success' => false,
                        'message' => 'Invalid challenge.',
                        'errors'  => [],
                    ]),
                ],
            ],
        ),
        endpoint(
            name: 'Switch Method Mid-Challenge',
            method: 'POST',
            urlPath: 'auth/2fa/challenge/switch',
            requestBody: jsonBody(['challenge_token' => '{{challenge_token}}', 'method' => 'email']),
            description: 'User chose a different enrolled method (e.g. "my phone is dead, send to email instead"). Issues a fresh code on the new method and re-uses the same challenge_token.',
            auth: false,
            cases: [
                [
                    'name'   => '200 — Switched, new code sent',
                    'code'   => 200,
                    'status' => 'OK',
                    'body'   => jsonBody([
                        'success' => true,
                        'message' => '2FA method switched. New code sent.',
                        'data'    => [
                            'challenge_token' => '{{challenge_token}}',
                            'method'          => 'email',
                            'expires_in'      => 270,
                            'masked_target'   => 'us***@example.com',
                        ],
                    ]),
                ],
                [
                    'name'        => '422 — Method not enrolled',
                    'code'        => 422,
                    'status'      => 'Unprocessable Entity',
                    'requestBody' => jsonBody(['challenge_token' => '{{challenge_token}}', 'method' => 'sms']),
                    'body'        => jsonBody([
                        'success' => false,
                        'message' => "Method 'sms' is not enrolled.",
                        'errors'  => [],
                    ]),
                ],
            ],
        ),
        endpoint(
            name: 'Resend Challenge Code',
            method: 'POST',
            urlPath: 'auth/2fa/challenge/resend',
            requestBody: jsonBody(['challenge_token' => '{{challenge_token}}']),
            description: 'Re-sends the code for the currently-selected method (no-op for TOTP).',
            auth: false,
            cases: [
                [
                    'name'   => '200 — Code resent',
                    'code'   => 200,
                    'status' => 'OK',
                    'body'   => jsonBody([
                        'success' => true,
                        'message' => '2FA code resent.',
                        'data'    => [
                            'challenge_token' => '{{challenge_token}}',
                            'method'          => 'email',
                            'expires_in'      => 240,
                            'masked_target'   => 'us***@example.com',
                        ],
                    ]),
                ],
            ],
        ),
    ],
];

// ============================================================================
// PASSWORD CONFIRM (SUDO)
// ============================================================================

$folder['item'][] = [
    'name' => 'Password Confirm (Sudo Mode)',
    'item' => [
        endpoint(
            name: 'Confirm Password',
            method: 'POST',
            urlPath: 'auth/password/confirm',
            requestBody: jsonBody(['password' => 'Password123!']),
            description: "GitHub-style sudo mode. When `auth.2fa` middleware is set to `password_confirm` mode and the user has no 2FA enrolled, calling this endpoint grants a short-lived (15 min by default) step-up window. Used as a fallback for `Require2FA` middleware.\n\n**Errors:**\n- 422 wrong password",
            cases: [
                [
                    'name'   => '200 — Confirmed',
                    'code'   => 200,
                    'status' => 'OK',
                    'body'   => jsonBody([
                        'success' => true,
                        'message' => 'Password confirmed.',
                        'data'    => ['expires_in_minutes' => 15],
                    ]),
                ],
                [
                    'name'        => '422 — Wrong password',
                    'code'        => 422,
                    'status'      => 'Unprocessable Entity',
                    'requestBody' => jsonBody(['password' => 'wrong']),
                    'body'        => jsonBody([
                        'success' => false,
                        'message' => 'Current password is incorrect.',
                        'errors'  => [],
                    ]),
                ],
            ],
        ),
    ],
];

// ============================================================================
// TRUSTED DEVICES
// ============================================================================

$folder['item'][] = [
    'name' => 'Trusted Devices',
    'item' => [
        endpoint(
            name: 'List Trusted Devices',
            method: 'GET',
            urlPath: 'auth/trusted-devices',
            requestBody: null,
            description: "Returns every trusted device for the authenticated user with its effective trust level (derived from the configured `level_assignment` mode) and an `is_current` flag for the device making this request. Saves the first non-current trusted device id to `trusted_device_id`.",
            events: [[
                'listen' => 'test',
                'script' => [
                    'type' => 'text/javascript',
                    'exec' => [
                        "if (pm.response.code === 200) {",
                        "    const devices = (pm.response.json().data || {}).devices || [];",
                        "    const non = devices.find(d => !d.is_current);",
                        "    if (non) {",
                        "        pm.collectionVariables.set('trusted_device_id', non.id);",
                        "        console.log('trusted_device_id saved:', non.id);",
                        "    }",
                        "}",
                    ],
                ],
            ]],
            cases: [
                [
                    'name'   => '200 — Devices listed',
                    'code'   => 200,
                    'status' => 'OK',
                    'body'   => jsonBody([
                        'success' => true,
                        'message' => 'Trusted devices retrieved.',
                        'data'    => [
                            'devices' => [
                                [
                                    'id'            => 21,
                                    'device_name'   => 'iPhone 15 Pro',
                                    'platform'      => 'mobile',
                                    'browser'       => null,
                                    'os'            => 'iOS',
                                    'level'         => 'high',
                                    'stored_level'  => 'high',
                                    'admin_granted' => false,
                                    'trusted_at'    => '2025-12-10T08:00:00.000000Z',
                                    'last_seen_at'  => '2026-05-23T11:50:00.000000Z',
                                    'is_current'    => true,
                                ],
                                [
                                    'id'            => 22,
                                    'device_name'   => 'MacBook Pro — Chrome',
                                    'platform'      => 'web',
                                    'browser'       => 'Chrome',
                                    'os'            => 'macOS',
                                    'level'         => 'medium',
                                    'stored_level'  => 'low',
                                    'admin_granted' => false,
                                    'trusted_at'    => '2026-03-15T09:30:00.000000Z',
                                    'last_seen_at'  => '2026-05-22T18:00:00.000000Z',
                                    'is_current'    => false,
                                ],
                                [
                                    'id'            => 23,
                                    'device_name'   => 'Galaxy S21 — Chrome',
                                    'platform'      => 'mobile',
                                    'browser'       => 'Chrome',
                                    'os'            => 'Android',
                                    'level'         => 'low',
                                    'stored_level'  => 'low',
                                    'admin_granted' => false,
                                    'trusted_at'    => '2026-05-05T11:00:00.000000Z',
                                    'last_seen_at'  => '2026-05-20T10:00:00.000000Z',
                                    'is_current'    => false,
                                ],
                            ],
                        ],
                    ]),
                ],
                [
                    'name'   => '404 — Trusted-devices feature disabled',
                    'code'   => 404,
                    'status' => 'Not Found',
                    'body'   => jsonBody([
                        'success' => false,
                        'message' => 'Not Found',
                        'errors'  => [],
                    ]),
                ],
            ],
        ),
        endpoint(
            name: 'Revoke Trusted Device',
            method: 'DELETE',
            urlPath: 'auth/trusted-devices/{{trusted_device_id}}',
            requestBody: jsonBody(['password' => 'Password123!']),
            description: "Revokes a single trusted device. The revocation matrix applies:\n- `low` cannot revoke anything\n- `medium` can revoke `low` only\n- `high` can revoke `low` + `medium` + `high`\n- A device can always revoke itself (if trusted)\n\nPassword is required for step-up. The route is also wrapped in `auth.2fa` middleware so users with 2FA enrolled must additionally complete a fresh challenge first.\n\n**Errors:**\n- 403 actor's level cannot revoke target's level\n- 422 password missing or wrong\n- 404 trusted device not found",
            cases: [
                [
                    'name'   => '200 — Revoked',
                    'code'   => 200,
                    'status' => 'OK',
                    'body'   => jsonBody([
                        'success' => true,
                        'message' => 'Trusted device revoked.',
                        'data'    => [],
                    ]),
                ],
                [
                    'name'   => '403 — Trust level too low to revoke this device',
                    'code'   => 403,
                    'status' => 'Forbidden',
                    'body'   => jsonBody([
                        'success' => false,
                        'message' => 'Your device trust level does not permit revoking this device.',
                        'errors'  => ['actor_level' => 'low'],
                    ]),
                ],
                [
                    'name'        => '422 — Password missing',
                    'code'        => 422,
                    'status'      => 'Unprocessable Entity',
                    'requestBody' => '{}',
                    'body'        => jsonBody([
                        'success' => false,
                        'message' => 'Password confirmation required.',
                        'errors'  => [],
                    ]),
                ],
                [
                    'name'   => '404 — Not found',
                    'code'   => 404,
                    'status' => 'Not Found',
                    'body'   => jsonBody([
                        'success' => false,
                        'message' => 'Trusted device not found.',
                        'errors'  => [],
                    ]),
                ],
            ],
        ),
        endpoint(
            name: 'Revoke All Trusted Devices',
            method: 'DELETE',
            urlPath: 'auth/trusted-devices',
            requestBody: jsonBody(['password' => 'Password123!']),
            description: "Nuclear option — revokes EVERY trusted device the user has, including the device making this request. Available to any trusted device (low/medium/high). Requires password confirmation; if the user has 2FA enrolled the `auth.2fa` middleware requires a fresh 2FA challenge too.\n\nUse this after suspected account compromise.",
            cases: [
                [
                    'name'   => '200 — All revoked',
                    'code'   => 200,
                    'status' => 'OK',
                    'body'   => jsonBody([
                        'success' => true,
                        'message' => 'All trusted devices revoked.',
                        'data'    => ['revoked' => 5],
                    ]),
                ],
                [
                    'name'   => '403 — Untrusted device cannot revoke',
                    'code'   => 403,
                    'status' => 'Forbidden',
                    'body'   => jsonBody([
                        'success' => false,
                        'message' => 'Only trusted devices may revoke all.',
                        'errors'  => [],
                    ]),
                ],
                [
                    'name'        => '422 — Password missing',
                    'code'        => 422,
                    'status'      => 'Unprocessable Entity',
                    'requestBody' => '{}',
                    'body'        => jsonBody([
                        'success' => false,
                        'message' => 'Password confirmation required.',
                        'errors'  => [],
                    ]),
                ],
            ],
        ),
    ],
];

// ============================================================================
// SOCIAL PROFILE COMPLETION
// ============================================================================

$folder['item'][] = [
    'name' => 'Social Profile Completion',
    'description' => "When `social.profile_completion.enabled` is true, a brand-new Google user who is missing the host's required registration fields is NOT created or logged in by the OAuth callback. Instead the callback returns `requires_profile_completion` + a `completion_token`; the frontend collects the required fields and submits them here. Enforces the same `extra_fields_rules` + phone rules as the email flow. No user row exists until completion.",
    'item' => [
        endpoint(
            name: 'Google Callback → requires_profile_completion (example)',
            method: 'GET',
            urlPath: 'auth/social/google/callback',
            requestBody: null,
            description: "The OAuth callback is a browser redirect from Google — you normally don't call it directly from Postman. This item documents the RESPONSE shape your frontend must handle when profile completion is enabled and the user is brand new. Save the `completion_token` and show an onboarding form.",
            auth: false,
            events: [[
                'listen' => 'test',
                'script' => [
                    'type' => 'text/javascript',
                    'exec' => [
                        "if (pm.response.code === 202) {",
                        "    const data = pm.response.json().data || {};",
                        "    if (data.completion_token) {",
                        "        pm.collectionVariables.set('social_completion_token', data.completion_token);",
                        "        console.log('social_completion_token saved');",
                        "    }",
                        "}",
                    ],
                ],
            ]],
            cases: [
                [
                    'name'   => '202 — Profile completion required',
                    'code'   => 202,
                    'status' => 'Accepted',
                    'body'   => jsonBody([
                        'success' => true,
                        'message' => 'A few more details are needed to finish creating your account.',
                        'data'    => [
                            'requires_profile_completion' => true,
                            'completion_token'            => 'b7e2c1a0-4f3d-4a8b-9c2e-1d5f6a7b8c9d',
                            'prefill'                     => [
                                'email'  => 'newuser@gmail.com',
                                'name'   => 'New User',
                                'avatar' => 'https://lh3.googleusercontent.com/a/default',
                            ],
                        ],
                    ]),
                ],
                [
                    'name'   => '200 — Logged in (existing user or completion disabled)',
                    'code'   => 200,
                    'status' => 'OK',
                    'body'   => jsonBody([
                        'success' => true,
                        'message' => 'Logged in with Google successfully.',
                        'data'    => [
                            'user'          => ['id' => 1, 'email' => 'newuser@gmail.com'],
                            'token'         => '1|googleloginabc0123456789',
                            'refresh_token' => 'rt_1|googlerefresh0123456789',
                        ],
                    ]),
                ],
            ],
        ),
        endpoint(
            name: 'Complete Social Registration',
            method: 'POST',
            urlPath: 'auth/social/complete',
            requestBody: jsonBody([
                'completion_token' => '{{social_completion_token}}',
                'phone'            => '+14155550123',
                'username'         => 'newuser',
            ]),
            description: "Submits the host's required registration fields to finish a brand-new social signup. Validates against the same `extra_fields_rules` + phone rules as the email flow. On success: creates the user (email already verified by Google), links the social account, and issues the real token. The test script saves `auth_token`.\n\n**Errors:**\n- 422 validation — a required field is missing/invalid\n- 422 completion_token_invalid — token expired or already used\n- 404 — `social.profile_completion.enabled` is false",
            auth: false,
            events: [[
                'listen' => 'test',
                'script' => [
                    'type' => 'text/javascript',
                    'exec' => [
                        "if (pm.response.code === 200) {",
                        "    const data = pm.response.json().data || {};",
                        "    if (data.token) {",
                        "        pm.collectionVariables.set('auth_token', data.token);",
                        "        if (data.refresh_token) pm.collectionVariables.set('refresh_token', data.refresh_token);",
                        "        console.log('auth_token saved:', data.token);",
                        "    }",
                        "}",
                    ],
                ],
            ]],
            cases: [
                [
                    'name'   => '200 — Account created + logged in',
                    'code'   => 200,
                    'status' => 'OK',
                    'body'   => jsonBody([
                        'success' => true,
                        'message' => 'Account created. Logged in successfully.',
                        'data'    => [
                            'user'          => ['id' => 42, 'email' => 'newuser@gmail.com', 'phone' => '+14155550123', 'username' => 'newuser'],
                            'token'         => '5|socialcompleteabc0123456789',
                            'refresh_token' => 'rt_5|socialcompleterefresh012345',
                        ],
                    ]),
                ],
                [
                    'name'        => '422 — Required field missing',
                    'code'        => 422,
                    'status'      => 'Unprocessable Entity',
                    'requestBody' => jsonBody(['completion_token' => '{{social_completion_token}}']),
                    'body'        => jsonBody([
                        'success' => false,
                        'message' => 'The phone field is required.',
                        'errors'  => ['phone' => ['The phone field is required.']],
                    ]),
                ],
                [
                    'name'   => '422 — Completion token invalid or expired',
                    'code'   => 422,
                    'status' => 'Unprocessable Entity',
                    'body'   => jsonBody([
                        'success' => false,
                        'message' => 'Invalid or expired completion token. Please sign in with Google again.',
                        'errors'  => [],
                    ]),
                ],
                [
                    'name'   => '404 — Profile completion disabled',
                    'code'   => 404,
                    'status' => 'Not Found',
                    'body'   => jsonBody([
                        'success' => false,
                        'message' => 'Social profile completion is not enabled.',
                        'errors'  => [],
                    ]),
                ],
            ],
        ),
    ],
];

// ============================================================================
// REQUIRE2FA MIDDLEWARE — sample protected endpoint behaviors
// ============================================================================

$folder['item'][] = [
    'name' => 'Require2FA Middleware — Sample Behaviors',
    'description' => "These items document the THREE possible responses from any endpoint protected by `auth.2fa` middleware when the session has not been 2FA-verified recently. The actual URL depends on where the host app attaches the middleware (your sensitive endpoints). Use these as a reference for what your client must handle.",
    'item' => [
        endpoint(
            name: 'Protected endpoint (user has 2FA enrolled — step-up required)',
            method: 'POST',
            urlPath: 'your/sensitive/endpoint',
            requestBody: '{}',
            description: 'When the user has ≥1 verified 2FA method and the current session has no recent 2FA stamp, the middleware issues a fresh challenge_token and returns 403. The client must complete `/auth/2fa/challenge` with this token, then retry the original request.',
            cases: [
                [
                    'name'   => '403 — 2FA verification required',
                    'code'   => 403,
                    'status' => 'Forbidden',
                    'body'   => jsonBody([
                        'success' => false,
                        'message' => '2FA verification required.',
                        'data'    => [
                            'step_up'           => 'two_factor',
                            'challenge_token'   => 'a4d8f2c1-9b3e-4f7a-8d2c-1e5b6a7c8d9e',
                            'method'            => 'totp',
                            'available_methods' => ['totp', 'email'],
                            'expires_in'        => 300,
                        ],
                    ]),
                ],
            ],
        ),
        endpoint(
            name: 'Protected endpoint (no 2FA, behavior=block)',
            method: 'POST',
            urlPath: 'your/sensitive/endpoint',
            requestBody: '{}',
            description: 'When `auth_system.two_factor.middleware_behavior=block` and the user has no 2FA enrolled, the middleware hard-rejects the request.',
            cases: [
                [
                    'name'   => '403 — 2FA required but not enrolled',
                    'code'   => 403,
                    'status' => 'Forbidden',
                    'body'   => jsonBody([
                        'success' => false,
                        'message' => '2FA required but not enrolled.',
                        'data'    => ['step_up' => 'enroll_2fa'],
                    ]),
                ],
            ],
        ),
        endpoint(
            name: 'Protected endpoint (no 2FA, behavior=password_confirm)',
            method: 'POST',
            urlPath: 'your/sensitive/endpoint',
            requestBody: '{}',
            description: 'Default behavior. When the user has no 2FA enrolled, the middleware asks the client to confirm the password via `/auth/password/confirm`. Once confirmed, retries within `sudo_ttl_minutes` (default 15) pass through.',
            cases: [
                [
                    'name'   => '403 — Password confirmation required',
                    'code'   => 403,
                    'status' => 'Forbidden',
                    'body'   => jsonBody([
                        'success' => false,
                        'message' => 'Password confirmation required.',
                        'data'    => ['step_up' => 'password_confirm'],
                    ]),
                ],
                [
                    'name'   => '200 — Allowed (after sudo or 2FA challenge)',
                    'code'   => 200,
                    'status' => 'OK',
                    'body'   => jsonBody([
                        'success' => true,
                        'message' => 'OK.',
                        'data'    => [],
                    ]),
                ],
            ],
        ),
    ],
];

// ----- append + persist ------------------------------------------------------

$collection['item'][] = $folder;

// Also register two new collection variables used by the v2.6 folder.
$existingVarKeys = array_column($collection['variable'] ?? [], 'key');
$newVars = [
    ['key' => 'challenge_token',      'value' => '', 'type' => 'string', 'description' => 'v2.6 — auto-saved on Login when 2FA is enrolled. Consumed by POST /auth/2fa/challenge.'],
    ['key' => 'two_factor_method_id', 'value' => '', 'type' => 'string', 'description' => 'v2.6 — auto-saved by GET /auth/2fa/methods.'],
    ['key' => 'totp_secret',          'value' => '', 'type' => 'string', 'description' => 'v2.6 — auto-saved by POST /auth/2fa/enroll/totp/start.'],
    ['key' => 'trusted_device_id',    'value' => '', 'type' => 'string', 'description' => 'v2.6 — auto-saved by GET /auth/trusted-devices.'],
    ['key' => 'trusted_device_token', 'value' => '', 'type' => 'string', 'description' => 'v2.6 — one-time device token, auto-saved by POST /auth/2fa/challenge when trust_device=true. Send it back as the X-Trusted-Device-Token header on future logins to bypass 2FA.'],
    ['key' => 'social_completion_token', 'value' => '', 'type' => 'string', 'description' => 'v2.6 — auto-saved from the OAuth callback when social.profile_completion is enabled. Consumed by POST /auth/social/complete.'],
];

foreach ($newVars as $var) {
    if (! in_array($var['key'], $existingVarKeys, true)) {
        $collection['variable'][] = $var;
    }
}

file_put_contents(
    $path,
    json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
);

echo "OK — appended '{$folderName}' to " . basename($path) . PHP_EOL;
echo "    items: " . count($folder['item']) . " sub-folders" . PHP_EOL;
$count = 0;
foreach ($folder['item'] as $sub) {
    $count += count($sub['item'] ?? []);
}
echo "    endpoints: {$count}" . PHP_EOL;
