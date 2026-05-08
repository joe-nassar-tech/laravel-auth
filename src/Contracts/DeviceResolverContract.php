<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Contracts;

use Illuminate\Http\Request;

interface DeviceResolverContract
{
    /**
     * Parse the request and return a full device fingerprint array.
     *
     * @return array{platform: string, browser?: string, os?: string,
     *               device_model?: string, device_marketing_name?: string,
     *               device_code?: string, device_platform?: string}
     */
    public function resolve(Request $request): array;
}
