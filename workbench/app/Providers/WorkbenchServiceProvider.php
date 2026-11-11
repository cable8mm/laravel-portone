<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Cable8mm\LaravelPortone\Events\BillingKeyDeleted;
use Cable8mm\LaravelPortone\Events\BillingKeyEvent;
use Cable8mm\LaravelPortone\Events\BillingKeyIssued;
use Cable8mm\LaravelPortone\Events\PaymentAmountMismatched;
use Cable8mm\LaravelPortone\Events\PaymentCompleted;
use Cable8mm\LaravelPortone\Events\PaymentDuplicated;
use Cable8mm\LaravelPortone\Events\PaymentEvent;
use Cable8mm\LaravelPortone\Events\PaymentExpired;
use Cable8mm\LaravelPortone\Events\PaymentPartiallyRefunded;
use Cable8mm\LaravelPortone\Events\PaymentRefunded;
use Cable8mm\LaravelPortone\Events\WebhookReceived;
use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\PortonePhp\Webhook\WebhookTransaction;
use Dotenv\Dotenv;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Http\Middleware\RecordWebhookExchange;
use Workbench\App\Models\WebhookLog;

use function Orchestra\Testbench\package_path;
use function Orchestra\Testbench\workbench_path;

class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // The served Workbench app reads the same local .env as the live tests;
        // PHPUnit runs must not, so their configuration stays as each test sets it.
        if (! $this->app->runningUnitTests()) {
            Dotenv::createImmutable(package_path())->safeLoad();
            $this->app['config']->set('portone', require package_path('config/portone.php'));
        }
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // The Package only publishes its migrations; the Workbench database runs them in place.
        $this->loadMigrationsFrom([package_path('database/migrations'), workbench_path('database/migrations')]);

        // The Package registers no schedule; the Application does (T-023).
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('portone:expire-pending')->everyTenMinutes();
            $schedule->command('model:prune', ['--model' => [Payment::class]])->daily();
        });

        // The sample shop's webhook log: record every verified webhook the Package dispatches.
        Event::listen(WebhookReceived::class, static function (WebhookReceived $event): void {
            if (! Schema::hasTable('webhook_logs')) {
                return;
            }

            WebhookLog::create([
                'webhook_id' => $event->webhookId,
                'type' => property_exists($event->webhook, 'type') ? $event->webhook->type : class_basename($event->webhook),
                'payment_id' => $event->webhook instanceof WebhookTransaction ? $event->webhook->data->paymentId : null,
            ]);
        });

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

        $paymentEvents = [
            PaymentCompleted::class,
            PaymentPartiallyRefunded::class,
            PaymentRefunded::class,
            PaymentAmountMismatched::class,
            PaymentDuplicated::class,
            PaymentExpired::class,
        ];

        foreach ($paymentEvents as $paymentEvent) {
            Event::listen($paymentEvent, static function (PaymentEvent $event) use ($observationDirectory): void {
                // The record as the listener sees it: the Package dispatches after the write commits.
                $payment = Payment::query()->where('payment_id', $event->paymentId)->first();
                $request = app()->bound('request') ? request() : null;

                self::appendJsonLine($observationDirectory.'/payment-events.jsonl', [
                    'event' => $event::class,
                    'paymentId' => $event->paymentId,
                    'source' => $event->source->value,
                    'webhookId' => $request?->headers->get('webhook-id'),
                    'recordStatus' => $payment?->status,
                    'recordCancelledAmount' => $payment?->cancelled_amount,
                ]);
            });
        }

        foreach ([BillingKeyIssued::class, BillingKeyDeleted::class] as $billingKeyEvent) {
            Event::listen($billingKeyEvent, static function (BillingKeyEvent $event) use ($observationDirectory): void {
                $request = app()->bound('request') ? request() : null;

                // Never the billing key: only the issuance and the record state the listener sees.
                self::appendJsonLine($observationDirectory.'/billing-key-events.jsonl', [
                    'event' => $event::class,
                    'issueId' => $event->billingKey->issue_id,
                    'source' => $event->source->value,
                    'webhookId' => $request?->headers->get('webhook-id'),
                    'recordStatus' => $event->billingKey->fresh()?->status,
                ]);
            });
        }

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
