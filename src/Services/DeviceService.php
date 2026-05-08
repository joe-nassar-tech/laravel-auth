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
                $location = $this->resolveLocation($request->ip() ?? '');

                return array_merge($mobile, $location, ['ip_address' => $request->ip()]);
            }
        }

        $ua       = $this->parseUserAgent($request);
        $location = $this->resolveLocation($request->ip() ?? '');

        return array_merge($ua, $location, ['ip_address' => $request->ip()]);
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

        try {
            $response = Http::timeout(3)->get("http://ip-api.com/json/{$ip}", [
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
