<?php

declare(strict_types=1);

namespace Workbench\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Workbench\App\Providers\WorkbenchServiceProvider;

final class RecordWebhookExchange
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $route = $request->route();

        if ($route?->getName() !== 'portone.webhook') {
            return $response;
        }

        $observationDirectory = getenv('PORTONE_WEBHOOK_OBSERVATION_DIR');

        if (is_string($observationDirectory) && $observationDirectory !== '') {
            WorkbenchServiceProvider::appendJsonLine($observationDirectory.'/requests.jsonl', [
                'headers' => [
                    'webhook-id' => $request->headers->get('webhook-id'),
                    'webhook-signature' => $request->headers->get('webhook-signature'),
                    'webhook-timestamp' => $request->headers->get('webhook-timestamp'),
                ],
                'body' => $request->getContent(),
                'response' => [
                    'status' => $response->getStatusCode(),
                    'body' => $response->getContent(),
                ],
            ]);
        }

        return $response;
    }
}
