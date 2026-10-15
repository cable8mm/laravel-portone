<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone;

use Cable8mm\LaravelPortone\Console\ExpirePendingPaymentsCommand;
use Cable8mm\LaravelPortone\Console\ReplayWebhookCommand;
use Cable8mm\LaravelPortone\Console\ShowPaymentCommand;
use Cable8mm\LaravelPortone\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * 패키지 서비스 프로바이더.
 *
 * 설정 병합, 게이트웨이 바인딩, 웹훅 라우트 등록, 만료 명령 등록,
 * 설정·마이그레이션·체크아웃 스텁 발행을 담당한다. 스케줄은 등록하지 않는다.
 */
class PortOneServiceProvider extends ServiceProvider
{
    /**
     * 설정을 병합하고 PortOne 게이트웨이를 바인딩한다.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/portone.php', 'portone');
        $this->app->bind(PaymentGateway::class, PortOneGateway::class);
    }

    /**
     * 웹훅 라우트를 등록하고 발행 자원을 선언한다.
     *
     * 웹훅 경로는 portone.webhook_path 설정(기본 portone/webhook)을 사용한다.
     */
    public function boot(): void
    {
        Route::post(
            (string) config('portone.webhook_path', 'portone/webhook'),
            WebhookController::class,
        )->name('portone.webhook');

        if ($this->app->runningInConsole()) {
            $this->commands([ExpirePendingPaymentsCommand::class, ShowPaymentCommand::class, ReplayWebhookCommand::class]);

            $this->publishes([
                __DIR__.'/../config/portone.php' => $this->app->configPath('portone.php'),
            ], 'portone-config');

            $this->publishesMigrations($this->unpublishedMigrations([
                '2026_10_13_000000_create_portone_customers_table.php',
                '2026_10_13_000001_create_portone_payments_table.php',
                '2026_10_15_000000_add_paid_amount_to_portone_payments_table.php',
                '2026_10_16_000000_backfill_paid_amount_on_portone_payments_table.php',
            ]), 'portone-migrations');

            $this->publishes([
                __DIR__.'/../stubs/resources/views/portone/checkout.blade.php' => $this->app->resourcePath('views/portone/checkout.blade.php'),
                __DIR__.'/../stubs/resources/views/portone/completed.blade.php' => $this->app->resourcePath('views/portone/completed.blade.php'),
                __DIR__.'/../stubs/resources/js/portone-checkout.js' => $this->app->resourcePath('js/portone-checkout.js'),
                __DIR__.'/../stubs/app/Http/Controllers/PortOneCheckoutController.php' => $this->app->path('Http/Controllers/PortOneCheckoutController.php'),
                __DIR__.'/../stubs/routes/portone.php' => $this->app->basePath('routes/portone.php'),
            ], 'portone-stubs');
        }
    }

    /**
     * 앱에 아직 발행되지 않은 마이그레이션만 발행 대상으로 돌려준다.
     *
     * vendor:publish는 발행 시각으로 파일명 앞부분을 바꾸므로, 이미 발행된 마이그레이션을 원래 이름으로는
     * 찾지 못해 다시 복사한다. 날짜 접두사를 뺀 이름이 앱의 migrations 디렉터리에 있으면 건너뛴다.
     *
     * @param  list<string>  $files  패키지 마이그레이션 파일명
     * @return array<string, string> 원본 경로 => 앱 경로
     */
    private function unpublishedMigrations(array $files): array
    {
        $paths = [];

        foreach ($files as $file) {
            $name = substr($file, strlen('2026_10_13_000000_'));

            if ((glob($this->app->databasePath('migrations/*_'.$name)) ?: []) !== []) {
                continue;
            }

            $paths[__DIR__.'/../database/migrations/'.$file] = $this->app->databasePath('migrations/'.$file);
        }

        return $paths;
    }
}
