# Laravel PortOne

[![CI](https://github.com/cable8mm/laravel-portone/actions/workflows/ci.yml/badge.svg)](https://github.com/cable8mm/laravel-portone/actions/workflows/ci.yml)

한국의 PG사 **포트원(PortOne) V2 단건 결제**를 위한 Laravel 패키지입니다.
Laravel Cashier 스타일의 인터페이스로 어떤 Eloquent 모델에도 결제 기능을 붙일 수 있습니다.

> 포트원 관리자 콘솔에서 발급받은 **API Secret / Store ID / Channel Key / Webhook Secret**이 필요합니다.

## 주요 기능

- `Billable` 트레이트로 어떤 모델에도 결제 기능 추가
- 결제 사전 생성 (`PENDING`), 브라우저 결제 후 검증·확정 (`PAID`)
- 전액 / 부분 환불 (`PARTIALLY_CANCELLED` / `CANCELLED`)
- 모델별 결제 내역 조회
- 포트원 웹훅을 Laravel 이벤트(`WebhookReceived`)로 수신
- 수정 가능한 체크아웃 출발 파일(뷰·스크립트·컨트롤러·라우트) 발행

## 요구 사항

- PHP `^8.2`
- Laravel 컴포넌트(`illuminate/*`) `^12.0 || ^13.0`
- `cable8mm/portone-php: ^0.4` (모든 PortOne API 호출은 이 클라이언트를 통합니다)
- 프론트엔드 결제창: 애플리케이션에서 `@portone/browser-sdk` 설치 필요 (패키지가 대신 설치하지 않습니다)

## 설치

```bash
composer require cable8mm/laravel-portone
```

서비스 프로바이더는 자동 등록됩니다 (`composer.json`의 `extra.laravel.providers`).

## 설정

### 1. 환경 변수

`.env`에 아래 값을 설정합니다.

```dotenv
PORTONE_API_SECRET=
PORTONE_WEBHOOK_SECRET=
PORTONE_BASE_URL=
PORTONE_WEBHOOK_PATH=
PORTONE_STORE_ID=
PORTONE_CHANNEL_KEY=
```

| 변수                     | 설명                                                 | 기본값            |
| ------------------------ | ---------------------------------------------------- | ----------------- |
| `PORTONE_API_SECRET`     | 포트원 API Secret (결제 조회·취소용)                 | 없음 (필수)       |
| `PORTONE_WEBHOOK_SECRET` | 포트원 웹훅 Secret (웹훅 검증용)                     | 없음 (필수)       |
| `PORTONE_BASE_URL`       | PortOne API 주소 재정의 (비우면 PortOne 기본값 사용) | 없음              |
| `PORTONE_WEBHOOK_PATH`   | 웹훅 수신 경로                                       | `portone/webhook` |
| `PORTONE_STORE_ID`       | 포트원 상점 ID (브라우저 결제창용)                   | 없음 (필수)       |
| `PORTONE_CHANNEL_KEY`    | 포트원 채널 키 (브라우저 결제창용)                   | 없음 (필수)       |

### 2. 설정 / 마이그레이션 발행

```bash
php artisan vendor:publish --tag=portone-config
php artisan vendor:publish --tag=portone-migrations
php artisan migrate
```

발행되는 설정 파일: `config/portone.php`

생성되는 테이블:

- `portone_customers`: Billable 모델별 `customer_id` 1건 저장
- `portone_payments`: 결제 기록 (`payment_id` 전역 유니크)

## 빠른 시작 (서버 API)

### 1. 모델에 `Billable` 추가

```php
use Cable8mm\LaravelPortone\Concerns\Billable;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use Billable;
}
```

### 2. 결제 준비 → 확정

```php
// 1) 결제 레코드 사전 생성 (PortOne 호출 없음, 상태 PENDING)
$payment = $user->preparePayment(10000, 'Order A');

// 2) 브라우저 SDK에 전달할 값
$params = $payment->checkoutParameters();
// [
//   'paymentId' => '...',   // PortOne 결제 식별자로 그대로 사용
//   'orderName' => 'Order A',
//   'totalAmount' => 10000,
//   'customerId' => '...',
// ]

// 3) 구매자가 브라우저에서 결제한 뒤 서버에서 검증·확정
$paid = $user->completePayment($payment->payment_id);
// $paid->status === 'PAID'
```

### 3. 환불

```php
// 전액 환불
$user->refund($payment->payment_id, '구매자 요청');

// 부분 환불 (금액 지정)
$user->refund($payment->payment_id, '부분 환불', 3000);
```

전액 환불되면 `CANCELLED`, 일부만 환불되면 `PARTIALLY_CANCELLED`가 되며
`cancelled_amount`에 누적 환불액이 저장됩니다.

### 4. 결제 내역 조회

```php
// 최신순 결제 목록
$payments = $user->payments()->latest()->get();

// 단건 조회 (다른 사용자의 payment_id는 조회되지 않음)
$payment = $user->findPayment($paymentId);
```

### 5. 고객 ID

```php
$user->hasPortOneId();                  // bool
$user->portOneId();                     // ?string
$user->createOrGetPortOneCustomerId();  // string (없으면 생성)
```

`preparePayment()`를 호출하면 고객 레코드는 자동으로 생성되므로
보통은 직접 호출하지 않아도 됩니다.

## 체크아웃 출발 파일 (뷰 + JS + 컨트롤러 + 라우트)

결제 화면 출발점이 필요하면 스텁 4종을 발행합니다.

```bash
php artisan vendor:publish --tag=portone-stubs
```

| 발행 파일                                            | 설명                                                                    |
| ---------------------------------------------------- | ----------------------------------------------------------------------- |
| `resources/views/portone/checkout.blade.php`         | 결제 버튼 + 상태 표시 뷰                                                |
| `resources/js/portone-checkout.js`                   | `prepare → requestPayment → complete` 흐름의 ES 모듈 스크립트           |
| `app/Http/Controllers/PortOneCheckoutController.php` | `show / prepare / complete` 액션 (샘플 금액 10,000원, 주문명 `Order A`) |
| `routes/portone.php`                                 | `auth` 미들웨어의 체크아웃 라우트 3종                                   |

발행 후 애플리케이션에서 라우트 파일을 포함하고, 프론트엔드에서 브라우저 SDK와 Vite 엔트리를 연결합니다.

```php
// routes/web.php
require __DIR__.'/portone.php';
```

```bash
# 애플리케이션에서 직접 설치 (패키지가 설치하지 않음)
npm install @portone/browser-sdk
```

```js
// vite.config.js (애플리케이션)
export default {
  plugins: [
    // resources/js/portone-checkout.js 를 엔트리로 빌드
  ],
};
```

체크아웃 흐름:

1. 구매자가 `Start payment` 클릭 → `POST portone.checkout.prepare` → `PENDING` 레코드 생성
2. `@portone/browser-sdk/v2`의 `requestPayment` 호출 (storeId, channelKey, paymentId, orderName, totalAmount, customerId, `CARD`, `KRW`)
3. 에러 코드 없이 반환되면 `POST portone.checkout.complete` (`paymentId`만 전송)
4. `PAID` 응답이면 `Success`, 그 외(준비 실패·SDK 실패·미결제·금액 불일치 등)는 `Error`

> 스텁 컨트롤러의 금액·주문명은 상수로 고정된 예시입니다.
> 실제 상품에 맞게 애플리케이션에서 수정해서 사용하세요.
> `complete` 액션은 요청의 금액을 무시하고 서버 레코드 기준으로 검증합니다.

## 웹훅

웹훅 라우트는 패키지가 자동 등록합니다 (기본 `POST portone/webhook`, CSRF 제외).
서명은 `cable8mm/portone-php`의 검증기로 확인하며, 검증 성공 시에만 이벤트가 발생합니다.

```php
use Cable8mm\LaravelPortone\Events\WebhookReceived;
use Illuminate\Support\Facades\Event;

Event::listen(WebhookReceived::class, function (WebhookReceived $event) {
    $webhook = $event->webhook;     // 포트원 웹훅 객체
    $webhookId = $event->webhookId; // webhook-id 헤더
});
```

동작 요약:

- 서명 유효 → `WebhookReceived` 1건 발송 + `200` 빈 응답
- 서명 무효·헤더 누락·타임스탬프 범위 초과 → `400`, 이벤트 없음
- `PORTONE_WEBHOOK_SECRET` 미설정 → `500`, 이벤트 없음
- 웹훅은 고객·결제 레코드를 읽거나 쓰지 않습니다

## 결제 상태

`portone_payments.status`는 다음 중 하나입니다.

- `PENDING` → `PAID` → `PARTIALLY_CANCELLED` → `CANCELLED`
- `PAID`에서 바로 `CANCELLED` (전액 환불)도 가능
- `CANCELLED`는 최종 상태입니다

## 예외

| 예외                                        | HTTP (스텁 기준) | 의미                                      |
| ------------------------------------------- | ---------------- | ----------------------------------------- |
| `PaymentNotFoundException`                  | `422`            | 내 범위에 해당 `payment_id` 없음          |
| `PaymentNotPaidException`                   | `422`            | PortOne에서 미결제 상태                   |
| `AmountMismatchException`                   | `422`            | 서버 금액과 PortOne 실결제액 불일치       |
| `InvalidRefundAmountException`              | —                | 환불 금액이 0 이하이거나 환불 가능액 초과 |
| `ConfigurationException`                    | `500`            | `api_secret` 등 필수 설정 누락            |
| `PortOneException` (`cable8mm/portone-php`) | `500`            | PortOne API 실패 (원문 그대로 전달)       |

## 라이선스

MIT
