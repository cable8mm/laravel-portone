<?php

declare(strict_types=1);

namespace Workbench\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Package configures no JavaScript bundler, so the Workbench app resolves
 * the published script's bare SDK import with an import map instead.
 */
final class MapBrowserSdkImport
{
    public const SDK_URL = '/build/portone-browser-sdk/v2.js';

    /** The routes whose pages load a published script. */
    private const PAGES = ['portone.checkout', 'portone.billing-key', 'portone.billing-key.completed'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // An Inertia visit answers with JSON; the React pages are bundled with the SDK.
        if (! in_array($request->route()?->getName(), self::PAGES, true) || $request->hasHeader('X-Inertia')) {
            return $response;
        }

        $importMap = json_encode(
            ['imports' => ['@portone/browser-sdk/v2' => self::SDK_URL]],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        $response->setContent(
            '<!doctype html><script type="importmap">'.$importMap.'</script>'.$response->getContent(),
        );

        return $response;
    }
}
