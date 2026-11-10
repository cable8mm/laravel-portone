<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Exceptions;

use Cable8mm\LaravelPortone\Models\Payment;
use RuntimeException;
use Throwable;

/**
 * 한 번만 결제되는 Billable 모델(allowsOnlyOnePaidPayment()가 true)에 두 번째 결제가 확정될 때 발생하는 예외.
 *
 * 패키지는 PortOne 결제를 자동으로 전액 취소한 뒤 결과를 결제 레코드
 * (DUPLICATE_CANCELLED 또는 DUPLICATE)에 저장하고 이 예외를 던진다.
 * 취소가 실패했으면 PortOne 예외가 getPrevious()에 담긴다.
 */
class DuplicatePaymentException extends RuntimeException
{
    /**
     * @param  string  $message  예외 메시지
     * @param  int|null  $paidAmount  PortOne 실결제액(원)
     * @param  bool  $wasCancelled  PortOne이 자동 취소를 받아들였는지
     * @param  Throwable|null  $previous  자동 취소에 실패한 PortOne 예외
     */
    public function __construct(
        string $message = '',
        private readonly ?int $paidAmount = null,
        private readonly bool $wasCancelled = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * 중복 결제로 저장된 결제 레코드에서 예외를 만든다.
     *
     * @param  Payment  $payment  DUPLICATE_CANCELLED 또는 DUPLICATE 결제 레코드
     * @param  Throwable|null  $previous  자동 취소에 실패한 PortOne 예외
     */
    public static function fromPayment(Payment $payment, ?Throwable $previous = null): self
    {
        $wasCancelled = $payment->status === 'DUPLICATE_CANCELLED';

        return new self(
            $wasCancelled
                ? 'The Billable model already has a paid payment; the duplicate payment was cancelled.'
                : 'The Billable model already has a paid payment; the automatic cancellation of the duplicate payment failed.',
            $payment->paid_amount,
            $wasCancelled,
            $previous,
        );
    }

    /**
     * PortOne 실결제액(원)을 반환한다.
     */
    public function paidAmount(): ?int
    {
        return $this->paidAmount;
    }

    /**
     * PortOne이 자동 전액 취소를 받아들였는지 반환한다.
     */
    public function wasCancelled(): bool
    {
        return $this->wasCancelled;
    }
}
