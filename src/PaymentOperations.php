<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone;

use Cable8mm\LaravelPortone\Events\BillingKeyDeleted;
use Cable8mm\LaravelPortone\Events\BillingKeyEventSource;
use Cable8mm\LaravelPortone\Events\BillingKeyIssued;
use Cable8mm\LaravelPortone\Events\PaymentEventSource;
use Cable8mm\LaravelPortone\Exceptions\AmountMismatchException;
use Cable8mm\LaravelPortone\Exceptions\BillingKeyNotFoundException;
use Cable8mm\LaravelPortone\Exceptions\BillingKeyNotIssuedException;
use Cable8mm\LaravelPortone\Exceptions\DuplicatePaymentException;
use Cable8mm\LaravelPortone\Exceptions\InvalidBillingKeyException;
use Cable8mm\LaravelPortone\Exceptions\PaymentNotFoundException;
use Cable8mm\LaravelPortone\Exceptions\PaymentNotPaidException;
use Cable8mm\LaravelPortone\Models\BillingKey;
use Cable8mm\LaravelPortone\Models\Payment;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 결제 준비·확정·환불 흐름을 조율하는 서비스.
 *
 * Billable이 호출하는 진입점으로, 실제 상태 계산은 PaymentLifecycle,
 * 저장은 PaymentLedger, 외부 호출은 PaymentGateway가 맡는다.
 */
class PaymentOperations
{
    /**
     * @param  CustomerRegistry  $customers  고객 레코드 관리
     * @param  PaymentLedger  $payments  결제 레코드 저장소
     * @param  PaymentGateway  $gateway  PortOne API 호출
     * @param  PaymentLifecycle  $lifecycle  상태 전이 계산
     * @param  EventDispatcher  $events  커밋 뒤 결제·빌링키 이벤트 발송
     * @param  BillingKeyRegistry  $billingKeys  빌링키 기록 저장소
     * @param  BillingKeyGateway  $billingKeyGateway  PortOne 빌링키 API 호출
     */
    public function __construct(
        private readonly CustomerRegistry $customers,
        private readonly PaymentLedger $payments,
        private readonly PaymentGateway $gateway,
        private readonly PaymentLifecycle $lifecycle,
        private readonly EventDispatcher $events,
        private readonly BillingKeyRegistry $billingKeys,
        private readonly BillingKeyGateway $billingKeyGateway,
    ) {}

    /**
     * 결제 레코드를 미리 만든다. PortOne을 호출하지 않는다.
     *
     * 고객 레코드 확보와 결제 생성을 하나의 트랜잭션으로 처리한다.
     * 실패하면 고객 레코드도 남지 않는다.
     *
     * @param  Model  $billable  결제를 소유할 Billable 모델
     * @param  int  $amount  결제 금액(원, 0보다 커야 함)
     * @param  string  $orderName  주문명(빈 문자열 불가)
     * @return Payment 생성된 PENDING 결제 레코드
     *
     * @throws \InvalidArgumentException 금액이 0 이하이거나 주문명이 비었을 때
     */
    public function prepare(Model $billable, int $amount, string $orderName): Payment
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Payment amount must be greater than zero.');
        }

        if ($orderName === '') {
            throw new \InvalidArgumentException('Payment order name must not be empty.');
        }

        return DB::transaction(function () use ($billable, $amount, $orderName): Payment {
            $customerId = $this->customers->ensureId($billable);

            return $this->payments->createPending($billable, $customerId, $amount, $orderName);
        });
    }

    /**
     * 결제를 확정한다. 레코드에 배타 접근을 걸고 PortOne 조회를 수행한다.
     *
     * PENDING·EXPIRED가 아닌 레코드는 PortOne을 호출하지 않고 그대로 반환한다.
     * EXPIRED 레코드는 만료 뒤에 결제된 경우를 복구하기 위해 다시 조회한다.
     * 실결제액이 준비 금액과 다르면 PortOne 결제를 전액 취소하고 결과를 저장한 뒤
     * AmountMismatchException을 던진다. 이미 금액 불일치로 저장된 레코드는 PortOne을
     * 다시 부르지 않고 같은 예외를 던진다.
     * 로컬 저장 실패 시 같은 값으로 최대 3회까지 다시 시도한다.
     * 한 번만 결제되는 Billable 모델(allowsOnlyOnePaidPayment())은 모델 단위 배타 접근 안에서 확정하며,
     * 이미 결제된 레코드가 있으면 이 결제를 PortOne에서 자동 전액 취소하고 DuplicatePaymentException을 던진다.
     *
     * @param  Model  $billable  결제를 소유한 Billable 모델
     * @param  string  $paymentId  PortOne 결제 식별자
     * @return Payment 확정된(또는 기존 상태 그대로인) 결제 레코드
     *
     * @throws AmountMismatchException 실결제액이 준비 금액과 다를 때
     * @throws DuplicatePaymentException 한 번만 결제되는 모델에 이미 결제된 레코드가 있을 때
     */
    public function complete(Model $billable, string $paymentId): Payment
    {
        return $this->completeLocked(
            $this->allowsOnlyOnePaidPayment($billable) ? $this->payments->reference($billable) : null,
            fn (Closure $operation): Payment => $this->payments->withExclusiveAccess($billable, $paymentId, $operation),
            PaymentEventSource::Complete,
        );
    }

    /**
     * 결제 ID만으로 결제를 확정한다. 웹훅처럼 소유 Billable을 모를 때 쓴다.
     *
     * portone_payments의 billable_type·billable_id가 가리키는 모델의 결제로 보고,
     * complete()와 같은 잠금·멱등성·예외 규칙을 따른다.
     *
     * @param  string  $paymentId  PortOne 결제 식별자
     * @param  PaymentEventSource  $source  결제 이벤트의 source (Transaction.Paid 웹훅은 webhook)
     * @return Payment 확정된(또는 기존 상태 그대로인) 결제 레코드
     *
     * @throws PaymentNotFoundException 해당 결제 레코드가 없을 때
     * @throws PaymentNotPaidException PortOne이 결제 완료로 표시하지 않았을 때
     * @throws AmountMismatchException 실결제액이 준비 금액과 다를 때
     * @throws DuplicatePaymentException 한 번만 결제되는 모델에 이미 결제된 레코드가 있을 때
     */
    public function completeByPaymentId(string $paymentId, PaymentEventSource $source = PaymentEventSource::Complete): Payment
    {
        $reference = $this->payments->billableReference($paymentId);

        return $this->completeLocked(
            $reference !== null && $this->allowsOnlyOnePaidPayment($this->payments->billableModel(...$reference)) ? $reference : null,
            fn (Closure $operation): Payment => $this->payments->withExclusiveAccessByPaymentId($paymentId, $operation),
            $source,
        );
    }

    /**
     * 결제 ID로 레코드를 찾아 PortOne의 취소 상태를 반영한다(취소 동기화).
     *
     * 소유 Billable은 따지지 않는다. refund()와 같은 배타 접근을 잡고, PAID·PARTIALLY_CANCELLED·
     * AMOUNT_MISMATCH·DUPLICATE 레코드만 PortOne에 조회한다. PortOne이 CANCELLED·PARTIAL_CANCELLED이고 취소 총액이
     * 맞을 때만 status와 cancelled_amount를 바꾸며, 그 밖에는 그대로 둔다. 같은 PortOne 상태로 다시
     * 호출해도 결과가 같다. 취소 웹훅(Transaction.Cancelled·Transaction.PartialCancelled)도 이 메서드를 쓴다.
     *
     * @param  string  $paymentId  PortOne 결제 식별자
     * @param  PaymentEventSource  $source  결제 이벤트의 source (취소 웹훅은 webhook)
     * @return Payment 동기화된(또는 그대로인) 결제 레코드
     *
     * @throws PaymentNotFoundException 해당 결제 레코드가 없을 때
     */
    public function syncByPaymentId(string $paymentId, PaymentEventSource $source = PaymentEventSource::Sync): Payment
    {
        return $this->payments->withExclusiveAccessByPaymentId(
            $paymentId,
            function (Payment $payment) use ($source): Payment {
                if (! in_array($payment->status, ['PAID', 'PARTIALLY_CANCELLED', 'AMOUNT_MISMATCH', 'DUPLICATE'], true)) {
                    return $payment;
                }

                $gatewayPayment = $this->gateway->lookup($payment->payment_id);
                $outcome = $this->lifecycle->syncCancellation($payment, $gatewayPayment);

                return $outcome === null ? $payment : $this->write($payment, $outcome, $source, $gatewayPayment);
            },
        );
    }

    /**
     * 잠금을 잡은 레코드에 확정 규칙을 적용한다. complete()와 completeByPaymentId()가 공유한다.
     *
     * 한 번만 결제되는 Billable 모델이면 그 참조의 배타 접근을 레코드 잠금보다 먼저 잡는다.
     *
     * @param  array{0: string, 1: string}|null  $singlePayment  한 번만 결제되는 Billable 모델의 참조, 아니면 null
     * @param  Closure(Closure(Payment): Payment): Payment  $lock  레코드를 잠그고 연산을 실행하는 함수
     * @param  PaymentEventSource  $source  결제 이벤트의 source
     * @param  bool  $unknownIsNotPaid  PortOne이 결제를 모르면(PAYMENT_NOT_FOUND) 예외 대신 미결제로 볼지
     * @return Payment 확정된(또는 기존 상태 그대로인) 결제 레코드
     *
     * @throws PaymentNotPaidException PortOne이 결제 완료로 표시하지 않았을 때
     * @throws AmountMismatchException 실결제액이 준비 금액과 다를 때
     * @throws DuplicatePaymentException 한 번만 결제되는 모델에 이미 결제된 레코드가 있을 때
     */
    private function completeLocked(?array $singlePayment, Closure $lock, PaymentEventSource $source, bool $unknownIsNotPaid = false): Payment
    {
        $failure = null;

        $complete = function (Payment $payment) use (&$failure, $source, $singlePayment, $unknownIsNotPaid): Payment {
            if (in_array($payment->status, ['AMOUNT_MISMATCH_CANCELLED', 'AMOUNT_MISMATCH'], true)) {
                $failure = AmountMismatchException::fromPayment($payment);

                return $payment;
            }

            if (! in_array($payment->status, ['PENDING', 'EXPIRED'], true)) {
                return $payment;
            }

            $gatewayPayment = $unknownIsNotPaid
                ? $this->gateway->lookupForExpiry($payment->payment_id)
                : $this->gateway->lookup($payment->payment_id);

            if ($this->lifecycle->isAmountMismatch($payment, $gatewayPayment)) {
                $failure = $this->cancelAmountMismatch($payment, $gatewayPayment, $source);

                return $payment;
            }

            $outcome = $this->lifecycle->complete($payment, $gatewayPayment, now());

            if ($singlePayment !== null && $this->payments->hasOtherPaid($payment)) {
                $failure = $this->cancelDuplicate($payment, $gatewayPayment, $source);

                return $payment;
            }

            return $this->write($payment, $outcome, $source, $gatewayPayment);
        };
        $operation = fn (): Payment => $lock($complete);

        $payment = $singlePayment === null
            ? $operation()
            : $this->payments->withBillableExclusiveAccess($singlePayment[0], $singlePayment[1], $operation);

        // 잠금 트랜잭션 안에서 던지면 저장한 금액 불일치·중복 결제 결과가 롤백되므로, 커밋 뒤에 던진다.
        if ($failure !== null) {
            throw $failure;
        }

        return $payment;
    }

    /**
     * 오래된 PENDING 결제 1건을 PortOne에 확인해 PAID 또는 EXPIRED로 바꾼다.
     *
     * 레코드가 없거나 더 이상 PENDING이 아니면 아무것도 하지 않는다.
     * 판단할 수 없는 조회 결과면 그대로 두어 다음 만료 검사에서 다시 확인한다.
     * 실결제액이 준비 금액과 다르면 complete()와 같이 자동 전액 취소한다. 취소가 성공하면
     * 예외 없이 끝나고, 실패하면 AMOUNT_MISMATCH를 저장한 뒤 PortOne 예외를 전파한다.
     * 설정 누락·PortOne 실패·저장 실패는 감싸지 않고 전파한다.
     * 저장한 결과는 커밋 뒤에 source expiry의 결제 이벤트로 알린다: EXPIRED면 PaymentExpired, PAID면 PaymentCompleted,
     * 금액 불일치면 PaymentAmountMismatched(wasCancelled). 저장한 것이 없으면 보내지 않는다.
     * 한 번만 결제되는 Billable 모델에 이미 결제된 레코드가 있으면 PAID 대신 중복 결제로 자동 취소하며,
     * 금액 불일치와 같이 취소가 실패했을 때만 PortOne 예외를 전파한다.
     *
     * @param  string  $billableType  소유 Billable 모델의 morph 타입
     * @param  string  $billableId  소유 Billable 모델의 키
     * @param  string  $paymentId  PortOne 결제 식별자
     */
    public function expire(string $billableType, string $billableId, string $paymentId): void
    {
        $cancellationFailure = null;
        $singlePayment = $this->allowsOnlyOnePaidPayment($this->payments->billableModel($billableType, $billableId));

        $expire = function (Payment $payment) use (&$cancellationFailure, $singlePayment): void {
            if ($payment->status !== 'PENDING') {
                return;
            }

            $gatewayPayment = $this->gateway->lookupForExpiry($payment->payment_id);

            if ($this->lifecycle->isAmountMismatch($payment, $gatewayPayment)) {
                $cancellationFailure = $this->cancelAmountMismatch($payment, $gatewayPayment, PaymentEventSource::Expiry)->getPrevious();

                return;
            }

            $outcome = $this->lifecycle->expire($payment, $gatewayPayment, now());

            if ($outcome !== null && $outcome['status'] === 'PAID' && $singlePayment && $this->payments->hasOtherPaid($payment)) {
                $cancellationFailure = $this->cancelDuplicate($payment, $gatewayPayment, PaymentEventSource::Expiry)->getPrevious();

                return;
            }

            if ($outcome !== null) {
                // 영수증 URL은 PAID 확정(FR-060)에만 저장한다. EXPIRED는 결제되지 않은 조회 결과이다.
                $this->write($payment, $outcome, PaymentEventSource::Expiry, $outcome['status'] === 'PAID' ? $gatewayPayment : null);
            }
        };
        $operation = fn () => $this->payments->withExclusiveAccessByReference($billableType, $billableId, $paymentId, $expire);

        if ($singlePayment) {
            $this->payments->withBillableExclusiveAccess($billableType, $billableId, $operation);
        } else {
            $operation();
        }

        // AMOUNT_MISMATCH 저장과 그 이벤트가 롤백되지 않도록 커밋 뒤에 취소 실패를 전파한다.
        if ($cancellationFailure !== null) {
            throw $cancellationFailure;
        }
    }

    /**
     * PortOne 취소를 먼저 승인받은 뒤 로컬 레코드에 반영한다.
     *
     * 환불 결과는 PortOne 호출 전에 미리 계산되며, 로컬 저장 실패 시
     * 같은 값으로 최대 3회까지 다시 시도한다(PortOne 재호출 없음).
     *
     * @param  Model  $billable  결제를 소유한 Billable 모델
     * @param  string  $paymentId  PortOne 결제 식별자
     * @param  string  $reason  PortOne에 전달할 환불 사유(빈 문자열 불가)
     * @param  int|null  $amount  환불 금액(원, null이면 전액)
     * @return Payment 환불이 반영된 결제 레코드
     */
    public function refund(Model $billable, string $paymentId, string $reason, ?int $amount = null): Payment
    {
        return $this->payments->withExclusiveAccess(
            $billable,
            $paymentId,
            function (Payment $payment) use ($paymentId, $reason, $amount): Payment {
                if ($reason === '') {
                    throw new \InvalidArgumentException('Payment refund reason must not be empty.');
                }

                $outcome = $this->lifecycle->refund($payment, $amount);
                $this->gateway->cancel($paymentId, $reason, $amount);

                return $this->write($payment, $outcome, PaymentEventSource::Refund);
            },
        );
    }

    /**
     * PENDING 빌링키 기록을 미리 만든다. PortOne을 호출하지 않는다.
     *
     * 고객 레코드 확보와 기록 생성을 하나의 트랜잭션으로 처리한다.
     *
     * @param  Model  $billable  빌링키를 소유할 Billable 모델
     * @param  string  $issueName  발급 이름(PortOne issueName)
     * @return BillingKey 생성된 PENDING 빌링키 기록
     */
    public function prepareBillingKey(Model $billable, string $issueName): BillingKey
    {
        return DB::transaction(function () use ($billable, $issueName): BillingKey {
            $customerId = $this->customers->ensureId($billable);

            return $this->billingKeys->createPending($billable, $customerId, $issueName);
        });
    }

    /**
     * 브라우저가 돌려준 빌링키를 PortOne에 확인하고 기록을 ISSUED로 바꾼다.
     *
     * PENDING·FAILED 기록만 PortOne에 조회한다. PortOne이 이 기록의 고객에게 발급했다고 하면 빌링키를 암호화해 저장하고
     * ISSUED로 바꾼 뒤 커밋 뒤에 BillingKeyIssued(source call)를 보낸다. 그렇지 않으면 FAILED를 저장한 뒤 예외를 던진다.
     * 같은 빌링키로 이미 ISSUED인 기록은 PortOne을 부르지 않고 그대로 반환한다.
     *
     * @param  Model  $billable  빌링키 기록을 소유한 Billable 모델
     * @param  string  $issueId  Issue ID
     * @param  string  $billingKey  브라우저 리다이렉트가 돌려준 평문 빌링키
     * @return BillingKey ISSUED(또는 그대로인) 빌링키 기록
     *
     * @throws BillingKeyNotFoundException 범위 안에 PENDING·FAILED·ISSUED 기록이 없을 때
     * @throws BillingKeyNotIssuedException PortOne이 발급하지 않았거나 다른 고객에게 발급했다고 할 때, ISSUED 기록에 다른 빌링키를 넘겼을 때
     */
    public function completeBillingKey(Model $billable, string $issueId, #[\SensitiveParameter] string $billingKey): BillingKey
    {
        $failure = null;

        $record = $this->billingKeys->withExclusiveAccess($billable, $issueId, function (?BillingKey $record) use ($billingKey, &$failure): BillingKey {
            if ($record === null || ! in_array($record->status, ['PENDING', 'FAILED', 'ISSUED'], true)) {
                throw new BillingKeyNotFoundException('The billing-key issuance does not belong to this Billable model.');
            }

            if ($record->status === 'ISSUED') {
                if (! $this->billingKeys->holds($record, $billingKey)) {
                    throw new BillingKeyNotIssuedException('The billing-key issuance already holds another billing key.');
                }

                return $record;
            }

            $failure = $this->applyIssuance($record, $billingKey, $this->billingKeyGateway->billingKeyInfo($billingKey), BillingKeyEventSource::Call);

            return $record;
        });

        // 트랜잭션 안에서 던지면 저장한 FAILED가 롤백되므로, 커밋 뒤에 던진다.
        if ($failure !== null) {
            throw $failure;
        }

        return $record;
    }

    /**
     * ISSUED 빌링키를 PortOne에서 삭제하고 기록을 DELETED로 바꾼다. 커밋 뒤에 BillingKeyDeleted(source call)를 보낸다.
     *
     * @param  Model  $billable  빌링키 기록을 소유한 Billable 모델
     * @param  string  $issueId  Issue ID
     * @param  string  $reason  PortOne에 전달할 삭제 사유
     * @return BillingKey DELETED 빌링키 기록
     *
     * ACTIVE·PAST_DUE 구독이 결제하는 기록은 PortOne에 요청하지 않고 거절한다(FR-123).
     *
     * @throws InvalidBillingKeyException 범위 안에 ISSUED 기록이 없거나, ACTIVE·PAST_DUE 구독이 그 기록으로 결제할 때
     */
    public function deleteBillingKey(Model $billable, string $issueId, string $reason): BillingKey
    {
        return $this->billingKeys->withExclusiveAccess($billable, $issueId, function (?BillingKey $record) use ($reason): BillingKey {
            $billingKey = $this->issuedKey($record);

            if ($this->billingKeys->isChargedBySubscription($record)) {
                throw new InvalidBillingKeyException('The billing key is charged by an active or past-due subscription.');
            }

            $this->billingKeyGateway->deleteBillingKey($billingKey, $reason);
            $this->billingKeys->markDeleted($record, now());
            $this->events->dispatchAfterCommit(new BillingKeyDeleted($record, BillingKeyEventSource::Call));

            return $record;
        });
    }

    /**
     * ISSUED 빌링키로 결제한다.
     *
     * preparePayment()처럼 그 빌링키 기록을 가리키는 PENDING 결제 레코드를 만들고 커밋한 뒤 payWithBillingKey를 요청한다.
     * 요청이 실패하면 레코드는 PENDING으로 남고 PortOne 예외가 그대로 전파된다. 성공하면 completeByPaymentId()의 규칙으로
     * 확정하며, 결제 이벤트의 source는 charge이다.
     *
     * @param  Model  $billable  빌링키 기록을 소유한 Billable 모델
     * @param  string  $issueId  Issue ID
     * @param  int  $amount  결제 금액(원, 0보다 커야 함)
     * @param  string  $orderName  주문명(빈 문자열 불가)
     * @param  int|null  $subscriptionId  구독 갱신 결제면 레코드를 만들 때 연결할 구독 기록의 키
     * @return Payment 확정된 결제 레코드
     *
     * @throws \InvalidArgumentException 금액이 0 이하이거나 주문명이 비었을 때
     * @throws InvalidBillingKeyException 범위 안에 ISSUED 기록이 없을 때
     * @throws PaymentNotPaidException PortOne이 결제 완료로 표시하지 않았을 때
     * @throws AmountMismatchException 실결제액이 결제 금액과 다를 때
     * @throws DuplicatePaymentException 한 번만 결제되는 모델에 이미 결제된 레코드가 있을 때
     */
    public function chargeBillingKey(Model $billable, string $issueId, int $amount, string $orderName, ?int $subscriptionId = null): Payment
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Payment amount must be greater than zero.');
        }

        if ($orderName === '') {
            throw new \InvalidArgumentException('Payment order name must not be empty.');
        }

        [$payment, $billingKey] = $this->billingKeys->withExclusiveAccess($billable, $issueId, function (?BillingKey $record) use ($billable, $amount, $orderName, $subscriptionId): array {
            $billingKey = $this->issuedKey($record);
            // ERR-028: 요청을 보낼 수 없으면 결제 레코드도 만들지 않는다.
            $this->billingKeyGateway->assertConfigured();
            $customerId = $this->customers->ensureId($billable);

            return [$this->payments->createPending($billable, $customerId, $amount, $orderName, $record->id, $subscriptionId), $billingKey];
        });

        $this->billingKeyGateway->payWithBillingKey($payment->payment_id, $billingKey, $orderName, $amount);

        return $this->completeByPaymentId($payment->payment_id, PaymentEventSource::Charge);
    }

    /**
     * 구독 기간의 PENDING 결제 레코드를 먼저 확정한다(FR-137). 갱신 결제 직후 중단된 작업을 다시 실행할 때 두 번 결제하지 않기 위해서다.
     *
     * 구독 기록에 연결되고 기간 시작 시각 이후에 만든 PENDING 레코드를 만든 순서대로 completeByPaymentId()의 규칙(source charge)으로
     * 확정한다. PortOne이 결제를 모르는 레코드(PAYMENT_NOT_FOUND, 결제 요청이 결제를 만들지 못한 경우)는 미결제로 본다.
     * PAID가 된 레코드가 있으면 그것을, 모두 미결제면 null을 반환한다.
     *
     * @param  int  $subscriptionId  구독 기록의 키
     * @param  Carbon  $periodStart  갱신할 기간의 시작 시각(구독의 current_period_end)
     * @return Payment|null PAID가 된 결제 레코드, 없으면 null
     *
     * @throws AmountMismatchException 실결제액이 결제 금액과 다를 때
     * @throws DuplicatePaymentException 한 번만 결제되는 모델에 이미 결제된 레코드가 있을 때
     */
    public function completeSubscriptionPeriod(int $subscriptionId, Carbon $periodStart): ?Payment
    {
        foreach ($this->payments->pendingSubscriptionPaymentIds($subscriptionId, $periodStart) as $paymentId) {
            try {
                $payment = $this->completeLocked(
                    null,
                    fn (Closure $operation): Payment => $this->payments->withExclusiveAccessByPaymentId($paymentId, $operation),
                    PaymentEventSource::Charge,
                    unknownIsNotPaid: true,
                );
            } catch (PaymentNotPaidException) {
                continue;
            }

            if ($payment->status === 'PAID') {
                return $payment;
            }
        }

        return null;
    }

    /**
     * 결제 레코드를 그 결제가 낸 구독 기록에 연결한다. 첫 기간 결제가 PAID가 된 뒤 구독을 만들 때 쓴다(FR-131).
     *
     * @param  Payment  $payment  첫 기간의 PAID 결제 레코드
     * @param  int  $subscriptionId  구독 기록의 키
     * @return Payment 연결된 결제 레코드
     */
    public function linkSubscription(Payment $payment, int $subscriptionId): Payment
    {
        return $this->payments->linkSubscription($payment, $subscriptionId);
    }

    /**
     * 검증된 BillingKey 웹훅을 반영한다. 웹훅에는 빌링키만 있으므로 PortOne에 조회해 Issue ID로 기록을 찾는다.
     *
     * BillingKey.Issued: PENDING 기록이면 completeBillingKey()와 같은 규칙으로 ISSUED(또는 FAILED)가 된다.
     * BillingKey.Deleted: 같은 빌링키를 가진 ISSUED 기록이 DELETED가 된다. 이벤트의 source는 webhook이다.
     * 그 밖의 종류이거나, 기록이 없거나 상태가 맞지 않으면 아무것도 바꾸지 않으며 PortOne에 조회하지 않는 종류도 있다.
     *
     * @param  string  $type  웹훅 종류(BillingKey.Issued, BillingKey.Deleted 등)
     * @param  string  $billingKey  웹훅 data.billingKey
     */
    public function applyBillingKeyWebhook(string $type, #[\SensitiveParameter] string $billingKey): void
    {
        if (! in_array($type, ['BillingKey.Issued', 'BillingKey.Deleted'], true) || $billingKey === '') {
            return;
        }

        $info = $this->billingKeyGateway->billingKeyInfo($billingKey);

        if ($info->issueId === null) {
            return;
        }

        $this->billingKeys->withExclusiveAccessByIssueId($info->issueId, function (?BillingKey $record) use ($type, $billingKey, $info): void {
            if ($type === 'BillingKey.Issued' && $record?->status === 'PENDING') {
                // FAILED가 되어도 웹훅 처리는 성공이다(예외를 던지지 않고 버린다).
                $this->applyIssuance($record, $billingKey, $info, BillingKeyEventSource::Webhook);

                return;
            }

            if ($type === 'BillingKey.Deleted' && $record?->status === 'ISSUED' && $this->billingKeys->holds($record, $billingKey)) {
                $this->billingKeys->markDeleted($record, now());
                $this->events->dispatchAfterCommit(new BillingKeyDeleted($record, BillingKeyEventSource::Webhook));
            }
        });
    }

    /**
     * 잠금을 잡은 PENDING·FAILED 기록에 PortOne 빌링키 조회 결과를 반영한다.
     *
     * 이 기록의 고객에게 발급되었으면 ISSUED를 저장하고 BillingKeyIssued를 커밋 뒤에 보내도록 넘긴다.
     * 아니면 FAILED를 저장하고, 호출자가 커밋 뒤에 던질 예외를 반환한다.
     *
     * @param  BillingKey  $record  잠금을 잡은 PENDING 또는 FAILED 기록
     * @param  string  $billingKey  평문 빌링키
     * @param  GatewayBillingKey  $info  그 빌링키의 PortOne 조회 결과
     * @param  BillingKeyEventSource  $source  빌링키 이벤트의 source
     */
    private function applyIssuance(BillingKey $record, #[\SensitiveParameter] string $billingKey, GatewayBillingKey $info, BillingKeyEventSource $source): ?BillingKeyNotIssuedException
    {
        if (! $this->lifecycle->isIssuedFor($record, $info)) {
            $this->billingKeys->markFailed($record);

            return new BillingKeyNotIssuedException('PortOne did not report the billing key issued for this customer.');
        }

        $this->billingKeys->markIssued($record, $billingKey, now());
        $this->events->dispatchAfterCommit(new BillingKeyIssued($record, $source));

        return null;
    }

    /**
     * ISSUED 기록의 평문 빌링키를 반환한다. 기록이 없거나 ISSUED가 아니면 예외를 던진다.
     *
     * @param  BillingKey|null  $record  범위 안에서 잠금을 잡은 기록
     *
     * @phpstan-assert BillingKey $record
     *
     * @throws InvalidBillingKeyException 기록이 없거나 ISSUED가 아닐 때
     */
    private function issuedKey(?BillingKey $record): string
    {
        $billingKey = $record?->status === 'ISSUED' ? $this->billingKeys->plainKey($record) : null;

        if ($billingKey === null) {
            throw new InvalidBillingKeyException('The billing key is not an issued billing key of this Billable model.');
        }

        return $billingKey;
    }

    /**
     * 금액이 다른 결제를 PortOne에서 전액 취소하고 결과를 저장한다.
     *
     * 두 결과는 취소 요청 전에 계산한다. 취소가 실패해도 예외를 던지지 않고
     * AMOUNT_MISMATCH를 저장한 뒤, PortOne 예외를 previous로 담은 예외를 반환한다.
     *
     * @param  Payment  $payment  잠금을 잡은 PENDING 또는 EXPIRED 결제 레코드
     * @param  GatewayPayment  $gatewayPayment  금액이 다른 PAID 조회 결과
     * @param  PaymentEventSource  $source  결제 이벤트의 source
     * @return AmountMismatchException 호출자가 잠금이 끝난 뒤 던지거나 해석할 예외
     */
    private function cancelAmountMismatch(Payment $payment, GatewayPayment $gatewayPayment, PaymentEventSource $source): AmountMismatchException
    {
        $outcomes = $this->lifecycle->amountMismatch($gatewayPayment);

        try {
            $this->gateway->cancel($payment->payment_id, $this->amountMismatchCancelReason(), null);
        } catch (Throwable $exception) {
            return AmountMismatchException::fromPayment($this->write($payment, $outcomes['failed'], $source, $gatewayPayment), $exception);
        }

        return AmountMismatchException::fromPayment($this->write($payment, $outcomes['cancelled'], $source, $gatewayPayment));
    }

    /**
     * 한 번만 결제되는 Billable 모델의 두 번째 결제를 PortOne에서 전액 취소하고 결과를 저장한다.
     *
     * 두 결과는 취소 요청 전에 계산한다. 취소가 실패해도 예외를 던지지 않고
     * DUPLICATE를 저장한 뒤, PortOne 예외를 previous로 담은 예외를 반환한다.
     *
     * @param  Payment  $payment  잠금을 잡은 PENDING 또는 EXPIRED 결제 레코드
     * @param  GatewayPayment  $gatewayPayment  금액이 일치하는 PAID 조회 결과
     * @param  PaymentEventSource  $source  결제 이벤트의 source
     * @return DuplicatePaymentException 호출자가 잠금이 끝난 뒤 던지거나 해석할 예외
     */
    private function cancelDuplicate(Payment $payment, GatewayPayment $gatewayPayment, PaymentEventSource $source): DuplicatePaymentException
    {
        $outcomes = $this->lifecycle->duplicate($gatewayPayment, now());

        try {
            $this->gateway->cancel($payment->payment_id, $this->duplicateCancelReason(), null);
        } catch (Throwable $exception) {
            return DuplicatePaymentException::fromPayment($this->write($payment, $outcomes['failed'], $source, $gatewayPayment), $exception);
        }

        return DuplicatePaymentException::fromPayment($this->write($payment, $outcomes['cancelled'], $source, $gatewayPayment));
    }

    /**
     * Billable 모델이 한 번만 결제되는지(allowsOnlyOnePaidPayment()) 확인한다. 모델이 없거나 메서드가 없으면 false이다.
     *
     * @param  Model|null  $billable  확인할 Billable 모델
     */
    private function allowsOnlyOnePaidPayment(?Model $billable): bool
    {
        return $billable !== null
            && method_exists($billable, 'allowsOnlyOnePaidPayment')
            && $billable->allowsOnlyOnePaidPayment() === true;
    }

    /**
     * 중복 결제 자동 취소 사유(portone.duplicate_cancel_reason)를 반환한다.
     */
    private function duplicateCancelReason(): string
    {
        $reason = config('portone.duplicate_cancel_reason');

        return is_string($reason) && $reason !== '' ? $reason : '중복 결제로 자동 취소';
    }

    /**
     * 금액 불일치 자동 취소 사유(portone.amount_mismatch_cancel_reason)를 반환한다.
     */
    private function amountMismatchCancelReason(): string
    {
        $reason = config('portone.amount_mismatch_cancel_reason');

        return is_string($reason) && $reason !== '' ? $reason : '결제 금액 불일치로 자동 취소';
    }

    /**
     * 미리 계산된 결과를 저장한다. 실패하면 같은 값으로 최대 3회까지 시도한다.
     *
     * 저장한 변경의 결제 이벤트는 커밋 뒤에 1번 발송되도록 넘긴다. 롤백되면 발송되지 않는다.
     * PortOne 조회를 반영하는 저장이면 그 조회의 영수증 URL을 receipt_url에 함께 쓰고,
     * 조회에 영수증 URL이 없으면 receipt_url을 그대로 둔다(FR-113).
     *
     * @param  Payment  $payment  갱신할 결제 레코드
     * @param  array{status: string, paid_at: Carbon, paid_amount: int, cancelled_amount?: int}|array{status: string, cancelled_amount: int}|array{status: string, paid_amount: int, cancelled_amount?: int}|array{status: string}  $outcome  저장할 절대값
     * @param  PaymentEventSource  $source  결제 이벤트의 source
     * @param  GatewayPayment|null  $lookup  이 저장이 반영하는 PortOne 조회 결과, 조회 없이 쓰면(환불) null
     * @return Payment 갱신된 결제 레코드
     */
    private function write(Payment $payment, array $outcome, PaymentEventSource $source, ?GatewayPayment $lookup = null): Payment
    {
        if ($lookup?->receiptUrl !== null) {
            $outcome['receipt_url'] = $lookup->receiptUrl;
        }

        $previousStatus = $payment->status;
        $previousCancelledAmount = $payment->cancelled_amount;

        for ($attempt = 1; ; $attempt++) {
            try {
                $payment = $this->payments->update($payment, $outcome);
                break;
            } catch (Throwable $exception) {
                if ($attempt >= 3) {
                    throw $exception;
                }
            }
        }

        $event = $this->lifecycle->paymentEvent($previousStatus, $previousCancelledAmount, $payment, $source);

        if ($event !== null) {
            $this->events->dispatchAfterCommit($event);
        }

        return $payment;
    }
}
