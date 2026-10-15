<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Testing;

use Cable8mm\LaravelPortone\GatewayPayment;
use Cable8mm\LaravelPortone\PaymentGateway;
use Cable8mm\PortonePhp\Exception\UnknownException;
use Closure;
use PHPUnit\Framework\Assert as PHPUnit;
use Throwable;

/**
 * 테스트용 PaymentGateway. PortOne에 요청하지 않고 지정한 응답을 돌려주며 호출을 기록한다.
 *
 * 응답을 지정하지 않은 결제 ID는 PortOne이 모르는 결제로 본다: lookup()은 PAYMENT_NOT_FOUND 예외를
 * 던지고, lookupForExpiry()는 found가 false인 결과를 반환한다(PortOneGateway와 같다).
 * cancel()은 기록만 하며 지정한 응답을 바꾸지 않는다. 취소 뒤의 조회 결과는 payment()로 다시 지정한다.
 *
 *     $gateway = FakePaymentGateway::bind()
 *         ->payment($paymentId, 'PAID', totalAmount: 10000, cancelledAmount: 0);
 *     // ... 테스트 대상 실행
 *     $gateway->assertLookedUp($paymentId, times: 1);
 *     $gateway->assertCancelled($paymentId, fn (string $reason, ?int $amount): bool => $amount === null);
 */
final class FakePaymentGateway implements PaymentGateway
{
    /** @var array<string, GatewayPayment|Throwable> 결제 ID별 조회 응답 또는 조회 실패 */
    private array $payments = [];

    /** @var array<string, Throwable> 결제 ID별 취소 실패 */
    private array $cancelFailures = [];

    /** @var list<string> 조회한 결제 ID(lookup·lookupForExpiry 모두) */
    private array $lookups = [];

    /** @var list<array{paymentId: string, reason: string, amount: int|null}> 취소 요청 */
    private array $cancellations = [];

    /**
     * 새 가짜 게이트웨이를 애플리케이션 컨테이너의 PaymentGateway로 바인딩하고 반환한다.
     */
    public static function bind(): self
    {
        $gateway = new self;

        app()->instance(PaymentGateway::class, $gateway);

        return $gateway;
    }

    /**
     * 결제 ID의 조회 응답을 지정한다.
     *
     * @param  string  $paymentId  PortOne 결제 식별자
     * @param  string  $status  PortOne 결제 상태(예: PAID, PARTIAL_CANCELLED, CANCELLED, READY, FAILED)
     * @param  int|null  $totalAmount  실결제 총액(amount.total)
     * @param  int|null  $cancelledAmount  취소 총액(amount.cancelled)
     */
    public function payment(string $paymentId, string $status, ?int $totalAmount = null, ?int $cancelledAmount = null): self
    {
        $this->payments[$paymentId] = new GatewayPayment($status, $totalAmount, cancelledAmount: $cancelledAmount);

        return $this;
    }

    /**
     * 결제 ID의 조회가 이 예외를 던지게 한다(예: PortOne 장애).
     */
    public function failLookup(string $paymentId, Throwable $exception): self
    {
        $this->payments[$paymentId] = $exception;

        return $this;
    }

    /**
     * 결제 ID의 취소 요청이 이 예외를 던지게 한다. 요청은 기록된다.
     */
    public function failCancel(string $paymentId, Throwable $exception): self
    {
        $this->cancelFailures[$paymentId] = $exception;

        return $this;
    }

    /**
     * 지정한 조회 응답을 반환한다. 지정하지 않은 결제 ID면 PAYMENT_NOT_FOUND 예외를 던진다.
     *
     * @throws Throwable 지정한 조회 실패, 또는 PAYMENT_NOT_FOUND
     */
    public function lookup(string $paymentId): GatewayPayment
    {
        $this->lookups[] = $paymentId;

        return $this->response($paymentId);
    }

    /**
     * 지정한 조회 응답을 반환한다. 지정하지 않은 결제 ID면 found가 false인 결과를 반환한다.
     *
     * @throws Throwable 지정한 조회 실패
     */
    public function lookupForExpiry(string $paymentId): GatewayPayment
    {
        $this->lookups[] = $paymentId;

        if (! array_key_exists($paymentId, $this->payments)) {
            return new GatewayPayment('PAYMENT_NOT_FOUND', null, found: false);
        }

        return $this->response($paymentId);
    }

    /**
     * 취소 요청을 기록한다. 지정한 취소 실패가 있으면 기록한 뒤 던진다.
     *
     * @throws Throwable 지정한 취소 실패
     */
    public function cancel(string $paymentId, string $reason, ?int $amount): void
    {
        $this->cancellations[] = ['paymentId' => $paymentId, 'reason' => $reason, 'amount' => $amount];

        if (isset($this->cancelFailures[$paymentId])) {
            throw $this->cancelFailures[$paymentId];
        }
    }

    /**
     * 기록된 조회 횟수를 반환한다. 결제 ID를 주면 그 결제만 센다.
     */
    public function lookupCount(?string $paymentId = null): int
    {
        return $paymentId === null
            ? count($this->lookups)
            : count(array_keys($this->lookups, $paymentId, true));
    }

    /**
     * 기록된 취소 요청을 반환한다.
     *
     * @return list<array{paymentId: string, reason: string, amount: int|null}>
     */
    public function cancellations(): array
    {
        return $this->cancellations;
    }

    /**
     * 결제 ID를 조회했는지 확인한다. times를 주면 정확한 횟수를 확인한다.
     */
    public function assertLookedUp(string $paymentId, ?int $times = null): void
    {
        $count = $this->lookupCount($paymentId);

        if ($times === null) {
            PHPUnit::assertGreaterThan(0, $count, "The payment [{$paymentId}] was not looked up.");

            return;
        }

        PHPUnit::assertSame($times, $count, "The payment [{$paymentId}] was looked up {$count} times instead of {$times}.");
    }

    /**
     * 결제 ID를 조회하지 않았는지 확인한다.
     */
    public function assertNotLookedUp(string $paymentId): void
    {
        $this->assertLookedUp($paymentId, 0);
    }

    /**
     * 아무 결제도 조회하지 않았는지 확인한다.
     */
    public function assertNothingLookedUp(): void
    {
        PHPUnit::assertSame(0, $this->lookupCount(), 'Payments were looked up unexpectedly.');
    }

    /**
     * 결제 ID의 취소 요청이 있었는지 확인한다.
     *
     * @param  (Closure(string, int|null): bool)|null  $callback  사유와 금액(null이면 전액)을 받아 일치하면 true
     */
    public function assertCancelled(string $paymentId, ?Closure $callback = null): void
    {
        $matching = array_filter(
            $this->cancellations,
            fn (array $cancellation): bool => $cancellation['paymentId'] === $paymentId
                && ($callback === null || $callback($cancellation['reason'], $cancellation['amount'])),
        );

        PHPUnit::assertNotEmpty($matching, "The expected cancellation of the payment [{$paymentId}] was not requested.");
    }

    /**
     * 결제 ID의 취소 요청이 없었는지 확인한다.
     */
    public function assertNotCancelled(string $paymentId): void
    {
        $matching = array_filter($this->cancellations, fn (array $cancellation): bool => $cancellation['paymentId'] === $paymentId);

        PHPUnit::assertEmpty($matching, "The payment [{$paymentId}] was cancelled unexpectedly.");
    }

    /**
     * 아무 취소 요청도 없었는지 확인한다.
     */
    public function assertNothingCancelled(): void
    {
        PHPUnit::assertSame([], $this->cancellations, 'Payments were cancelled unexpectedly.');
    }

    /**
     * 지정한 응답을 반환하거나 지정한 실패를 던진다.
     *
     * @throws Throwable 지정한 조회 실패, 또는 PAYMENT_NOT_FOUND
     */
    private function response(string $paymentId): GatewayPayment
    {
        // T-003·T-021: PortOne은 모르는 결제 ID에 HTTP 404 PAYMENT_NOT_FOUND로 답한다.
        $response = $this->payments[$paymentId]
            ?? new UnknownException('getPayment', 'Payment not found', status: 404, errorType: 'PAYMENT_NOT_FOUND');

        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response;
    }
}
