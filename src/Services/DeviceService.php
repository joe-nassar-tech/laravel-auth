<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Jenssegers\Agent\Agent;
use Joe404\LaravelAuth\Contracts\DeviceResolverContract;

class DeviceService implements DeviceResolverContract
{
    private static ?array $deviceMap = null;

    public function fingerprint(Request $request): array
    {
        if ($request->hasHeader(config('auth_system.device.header', 'X-Device-Info'))) {
            $mobile = $this->parseMobileHeader($request);
            if ($mobile !== null) {
                // Country/city are resolved asynchronously by BackfillSessionLocation
// after the session row exists. Stay out of the auth hot path — the
// session is fully usable while the GeoIP service is slow or down.
$location = ['country' => null, 'city' => null];
                $hash     = $this->resolveFingerprintHash($request, $mobile);

                return array_merge($mobile, $location, [
                    'ip_address'       => $request->ip(),
                    'fingerprint_hash' => $hash,
                ]);
            }
        }

        $ua       = $this->parseUserAgent($request);
        // Country/city are resolved asynchronously by BackfillSessionLocation
// after the session row exists. Stay out of the auth hot path — the
// session is fully usable while the GeoIP service is slow or down.
$location = ['country' => null, 'city' => null];
        $hash     = $this->resolveFingerprintHash($request, $ua);

        return array_merge($ua, $location, [
            'ip_address'       => $request->ip(),
            'fingerprint_hash' => $hash,
        ]);
    }

    /**
     * Resolve the strong device-level fingerprint hash used by the referral
     * anti-abuse system.
     *
     * Web/SPA: the frontend computes a hash from device-level signals
     * (canvas, WebGL, screen, timezone, audio) and sends it via the
     * X-Browser-Fingerprint header. We accept it verbatim.
     *
     * Mobile: the app sends `device_id` inside X-Device-Info (UUID stored
     * in iOS Keychain or ANDROID_ID on Android). We use that.
     *
     * If neither is present, returns null — the fingerprint check
     * downgrades to IP-only and the package silently allows IP-only
     * matching to be the abuse signal. This is by design so the package
     * works before the frontend implements the JS snippet.
     *
     * @param array<string, mixed> $context  Parsed UA or mobile header data.
     */
    public function resolveFingerprintHash(Request $request, array $context): ?string
    {
        $header = (string) config('auth_system.referral_code.browser_fingerprint_header', 'X-Browser-Fingerprint');
        $hash   = trim((string) $request->header($header, ''));

        if ($hash !== '' && $this->isValidFingerprintHash($hash)) {
            return $hash;
        }

        // Mobile fallback: explicit device_id wins because it's the
        // stable, secure-storage-backed identifier the app controls.
        $deviceId = $context['device_id'] ?? null;

        if (is_string($deviceId) && $deviceId !== '') {
            return substr($deviceId, 0, 191);
        }

        return null;
    }

    /**
     * The browser fingerprint is advisory — it is provided by the client
     * and MUST NOT be treated as proof of device identity. We only accept
     * it as a hex digest of a bounded length so an attacker cannot stuff
     * arbitrary payloads (long blobs, control chars, SQL fragments) into
     * the stored column.
     */
    private function isValidFingerprintHash(string $hash): bool
    {
        $min = (int) config('auth_system.referral_code.browser_fingerprint_min_length', 32);
        $max = (int) config('auth_system.referral_code.browser_fingerprint_max_length', 128);

        $len = strlen($hash);

        if ($len < $min || $len > $max) {
            return false;
        }

        return ctype_xdigit($hash);
    }

    public function parseMobileHeader(Request $request): ?array
    {
        $header = $request->header(config('auth_system.device.header', 'X-Device-Info'));

        if ($header === null || $header === '') {
            return null;
        }

        $data = json_decode($header, true);

        if (! is_array($data)) {
            return null;
        }

        return [
            'platform'              => 'mobile',
            'device_model'          => $data['model'] ?? '',
            'device_marketing_name' => $data['name'] ?? $this->resolveMarketingName($data['model'] ?? ''),
            'device_code'           => $this->resolveT2sCode($data['model'] ?? '', $data['t2s_code'] ?? null),
            'device_platform'       => strtolower($data['platform'] ?? ''),
            // Stable, secure-storage-backed identifier from the mobile app:
            //   iOS:     UUID kept in Keychain (survives uninstall)
            //   Android: ANDROID_ID (Settings.Secure.ANDROID_ID)
            // Used by the referral anti-abuse system to detect when a user
            // tries to self-refer with a fresh install of the app.
            'device_id'             => isset($data['device_id']) && is_string($data['device_id']) && $data['device_id'] !== ''
                ? $data['device_id']
                : null,
            'browser'               => null,
            'os'                    => null,
        ];
    }

    public function parseUserAgent(Request $request): array
    {
        $agent = new Agent();
        $agent->setUserAgent($request->userAgent() ?? '');

        $browserName    = (string) ($agent->browser() ?: '');
        $browserVersion = $browserName !== '' ? (string) ($agent->version($browserName) ?: '') : '';
        $browser        = trim($browserName . ' ' . $browserVersion);

        $platformName    = (string) ($agent->platform() ?: '');
        $platformVersion = $platformName !== '' ? (string) ($agent->version($platformName) ?: '') : '';
        $os              = trim($platformName . ' ' . $platformVersion);

        return [
            'platform'              => 'web',
            'browser'               => $browser !== '' ? $browser : null,
            'os'                    => $os !== '' ? $os : null,
            'device_model'          => null,
            'device_marketing_name' => null,
            'device_code'           => null,
            'device_platform'       => null,
            'device_id'             => null,
        ];
    }

    public function resolveMarketingName(string $modelCode): string
    {
        $map = $this->loadDeviceMap();

        return $map[$modelCode] ?? $modelCode;
    }

    public function resolveT2sCode(string $modelCode, ?string $clientT2sCode = null): string
    {
        if ($clientT2sCode !== null && $clientT2sCode !== '') {
            return $clientT2sCode;
        }

        return $modelCode;
    }

    public function resolveLocation(string $ip): array
    {
        if (! config('auth_system.device.resolve_location', false)) {
            return ['country' => null, 'city' => null];
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return ['country' => null, 'city' => null];
        }

        $endpoint = (string) config(
            'auth_system.device.location_endpoint',
            'https://ip-api.com/json/{ip}',
        );
        $url = str_replace('{ip}', rawurlencode($ip), $endpoint);

        try {
            $response = Http::timeout(3)->get($url, [
                'fields' => 'status,country,city',
            ]);

            if (! $response->successful()) {
                return ['country' => null, 'city' => null];
            }

            $body = $response->json();

            if (! is_array($body) || ($body['status'] ?? '') !== 'success') {
                return ['country' => null, 'city' => null];
            }

            return [
                'country' => $body['country'] ?? null,
                'city'    => $body['city'] ?? null,
            ];
        } catch (\Throwable) {
            return ['country' => null, 'city' => null];
        }
    }

    public function resolve(Request $request): array
    {
        return $this->fingerprint($request);
    }

    private function loadDeviceMap(): array
    {
        if (self::$deviceMap === null) {
            $path            = __DIR__ . '/../../resources/devices.json';
            self::$deviceMap = file_exists($path)
                ? json_decode((string) file_get_contents($path), true) ?? []
                : [];
        }

        return self::$deviceMap;
    }
}
