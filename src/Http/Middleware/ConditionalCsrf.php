<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Request;

class ConditionalCsrf extends VerifyCsrfToken
{
    protected function inExceptArray($request): bool
    {
        // Bearer-token requests are CSRF-immune — the browser cannot attach an
        // Authorization header to a cross-site request without an explicit
        // CORS pre-flight, so an attacker page cannot forge one.
        //
        // We deliberately do NOT exempt requests based on X-Client-Type: any
        // page can set that header on a same-origin XHR, so it is not a
        // trustworthy CSRF signal.
        if ($request->bearerToken() !== null) {
            return true;
        }

        return parent::inExceptArray($request);
    }
}
