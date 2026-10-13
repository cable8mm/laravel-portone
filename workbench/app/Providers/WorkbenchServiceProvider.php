<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Cable8mm\LaravelPortone\Events\WebhookReceived;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Http\Middleware\RecordWebhookExchange;

class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $observationDirectory = getenv('PORTONE_WEBHOOK_OBSERVATION_DIR');

        if (! is_string($observationDirectory) || $observationDirectory === '') {
            return;
        }

        self::appendJsonLine($observationDirectory.'/server.jsonl', [
            'webhookSecretConfigured' => is_string(config('portone.webhook_secret'))
                && config('portone.webhook_secret') !== '',
        ]);

        Event::listen(WebhookReceived::class, static function (WebhookReceived $event) use ($observationDirectory): void {
            self::appendJsonLine($observationDirectory.'/events.jsonl', [
                'webhookId' => $event->webhookId,
                'webhookType' => $event->webhook::class,
            ]);
        });

        $this->app->make(Kernel::class)->pushMiddleware(RecordWebhookExchange::class);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public static function appendJsonLine(string $path, array $record): void
    {
        $written = file_put_contents(
            $path,
            json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );

        if ($written === false) {
            throw new \RuntimeException('Could not record the webhook observation.');
        }
    }
}
