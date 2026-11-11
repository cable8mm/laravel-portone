<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 빌링키 기록 Eloquent 모델. 빌링키 발급 1건이며, 한 Billable 모델이 소유한다.
 *
 * 상태 전이: PENDING → ISSUED → DELETED, PENDING → FAILED, FAILED → ISSUED.
 * billing_key 속성은 애플리케이션 키로 암호화한 값 그대로이며, 평문 빌링키는 BillingKeyRegistry만 꺼낸다.
 * 배열·JSON 변환에서는 billing_key를 숨긴다.
 *
 * @property int $id
 * @property string $issue_id PortOne issueId로 쓰는 발급 식별자(Issue ID)
 * @property string $billable_type
 * @property string $billable_id
 * @property string $customer_id
 * @property string $issue_name
 * @property string|null $billing_key 암호화한 빌링키, 발급 전에는 null
 * @property string $status PENDING, ISSUED, FAILED, DELETED 중 하나
 * @property Carbon|null $issued_at
 * @property Carbon|null $deleted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class BillingKey extends Model
{
    protected $table = 'portone_billing_keys';

    protected $guarded = [];

    protected $hidden = ['billing_key'];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * 브라우저 빌링키 발급(requestIssueBillingKey)에 넘길 값을 반환한다. 빌링키는 들어 있지 않다.
     *
     * @return array{issueId: string, issueName: string, customerId: string} 발급 파라미터
     */
    public function issueParameters(): array
    {
        return [
            'issueId' => $this->issue_id,
            'issueName' => $this->issue_name,
            'customerId' => $this->customer_id,
        ];
    }
}
