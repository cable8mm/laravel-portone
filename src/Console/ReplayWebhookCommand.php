<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Console;

use Cable8mm\LaravelPortone\Exceptions\ConfigurationException;
use Cable8mm\LaravelPortone\PortOneGateway;
use Cable8mm\LaravelPortone\Testing\SignedWebhook;
use Cable8mm\PortonePhp\Exception\PortOneException;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * 서명된 PortOne 웹훅을 앱 자신의 웹훅 주소로 HTTP 전송하는 명령. 공개 주소 없이 로컬에서 웹훅 흐름을 확인할 때 쓴다.
 *
 * 주소는 app.url 아래의 portone.webhook 경로이다. transactionId는 PortOne 결제 조회 응답에서 가져오고,
 * --data의 JSON 객체를 data에 더한다.
 * 운영(production) 환경에서는 --force 없이 실행되지 않는다.
 */
class ReplayWebhookCommand extends Command
{
    protected $signature = 'portone:webhook-replay
        {type : Webhook type, for example Transaction.Paid or Transaction.Cancelled}
        {paymentId : PortOne payment ID}
        {--data= : JSON object merged into the webhook data, for example {"cancellationId":"..."}}
        {--force : Run in the production environment}';

    protected $description = 'Send a signed PortOne webhook to this application\'s webhook route over HTTP';

    public function handle(PortOneGateway $gateway): int
    {
        if ($this->laravel->environment('production') && ! $this->option('force')) {
            $this->error('Refusing to replay a webhook in production. Use --force to run it anyway.');

            return self::FAILURE;
        }

        $extra = $this->extraData();

        if ($extra === null) {
            $this->error('--data must be a JSON object.');

            return self::FAILURE;
        }

        $type = (string) $this->argument('type');
        $paymentId = (string) $this->argument('paymentId');

        try {
            $transactionId = $gateway->paymentResponse($paymentId)['transactionId'] ?? '';
            $body = SignedWebhook::body($type, $paymentId, array_merge(['transactionId' => $transactionId], $extra));
            $headers = SignedWebhook::headers($body);
        } catch (ConfigurationException|PortOneException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $appUrl = config('app.url');
        $url = is_string($appUrl) && $appUrl !== ''
            ? rtrim($appUrl, '/').route('portone.webhook', absolute: false)
            : route('portone.webhook');

        try {
            $response = Http::withHeaders($headers)->withBody($body, 'application/json')->post($url);
        } catch (ConnectionException $exception) {
            $this->error("Could not reach {$url}: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->line("POST {$url} -> HTTP {$response->status()}");

        return $response->successful() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * --data를 배열로 읽는다. 없으면 빈 배열, JSON 객체가 아니면 null이다.
     *
     * @return array<string, mixed>|null
     */
    private function extraData(): ?array
    {
        $data = $this->option('data');

        if ($data === null || $data === '') {
            return [];
        }

        $decoded = json_decode((string) $data, true);

        return is_array($decoded) && ($decoded === [] || ! array_is_list($decoded)) ? $decoded : null;
    }
}
