<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Console;

use Cable8mm\LaravelPortone\PaymentResponseRedactor;
use Cable8mm\LaravelPortone\PortOneGateway;
use Illuminate\Console\Command;

/**
 * PortOne 결제 조회 응답을 JSON으로 출력하는 명령. 자동 테스트용 실제 응답을 기록할 때 쓴다.
 *
 * 기본으로 개인정보와 카드·계좌 식별 정보를 가리고, --raw일 때만 그대로 출력한다.
 * 결제 레코드를 읽거나 쓰지 않는다.
 */
class ShowPaymentCommand extends Command
{
    protected $signature = 'portone:payment
        {paymentId : PortOne payment ID}
        {--raw : Print the response without redacting personal, card, and account data}';

    protected $description = 'Print the PortOne payment lookup response as JSON';

    public function handle(PortOneGateway $gateway, PaymentResponseRedactor $redactor): int
    {
        $response = $gateway->paymentResponse($this->argument('paymentId'));

        if (! $this->option('raw')) {
            $response = $redactor->redact($response);
        }

        $this->line(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
