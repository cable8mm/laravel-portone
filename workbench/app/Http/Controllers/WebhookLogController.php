<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Workbench\App\Models\WebhookLog;

/**
 * Webhooks recorded by the WebhookReceived listener in WorkbenchServiceProvider.
 */
class WebhookLogController
{
    public function index(): View
    {
        return view('workbench::shop.webhooks.index', [
            'logs' => WebhookLog::query()->latest('id')->limit(50)->get(),
            'webhookPath' => route('portone.webhook', absolute: false),
        ]);
    }
}
