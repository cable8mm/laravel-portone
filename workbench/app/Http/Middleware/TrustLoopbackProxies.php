<?php

declare(strict_types=1);

namespace Workbench\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trust forwarding headers from loopback reverse proxies (ngrok, LAN relays).
 *
 * The framework's TrustProxies middleware trusts nothing and resets the static
 * trusted-proxy list on every request, so this runs after it and restores the
 * loopback trust. Proxy requests then generate https asset and redirect URLs
 * while direct local requests without forwarding headers stay http.
 */
final class TrustLoopbackProxies
{
    public function handle(Request $request, Closure $next): Response
    {
        Request::setTrustedProxies(
            ['127.0.0.1'],
            Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO,
        );

        return $next($request);
    }
}
