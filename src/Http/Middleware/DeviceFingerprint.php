<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Joe404\LaravelAuth\Services\DeviceService;
use Joe404\LaravelAuth\Services\SessionService;

class DeviceFingerprint
{
    public function __construct(
        private readonly DeviceService $deviceService,
        private readonly SessionService $sessionService,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $fingerprint = $this->deviceService->fingerprint($request);
        $request->merge(['_device' => $fingerprint]);

        $response = $next($request);

        if ($request->user() !== null) {
            $this->sessionService->touch($request);
        }

        return $response;
    }
}
