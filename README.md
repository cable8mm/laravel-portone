# Laravel PortOne

[![CI](https://github.com/cable8mm/laravel-portone/actions/workflows/ci.yml/badge.svg)](https://github.com/cable8mm/laravel-portone/actions/workflows/ci.yml)
[![update-changelog](https://github.com/cable8mm/laravel-portone/actions/workflows/update-changelog.yml/badge.svg)](https://github.com/cable8mm/laravel-portone/actions/workflows/update-changelog.yml)
[![apidoc-to-github-pages](https://github.com/cable8mm/laravel-portone/actions/workflows/apidoc-to-github-page.yml/badge.svg)](https://github.com/cable8mm/laravel-portone/actions/workflows/apidoc-to-github-page.yml)
[![PHP Version](https://img.shields.io/packagist/php-v/cable8mm/laravel-portone.svg)](https://packagist.org/packages/cable8mm/laravel-portone)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/cable8mm/laravel-portone.svg)](https://packagist.org/packages/cable8mm/laravel-portone)
[![License](https://img.shields.io/github/license/cable8mm/laravel-portone.svg)](https://github.com/cable8mm/laravel-portone/blob/main/LICENSE)

한국의 PG사 **포트원(PortOne) V2 단건 결제**를 위한 Laravel 패키지입니다.
Laravel Cashier 스타일의 인터페이스로 어떤 Eloquent 모델에도 결제 기능을 붙일 수 있습니다.

> 포트원 관리자 콘솔에서 발급받은 **API Secret / Store ID / Channel Key / Webhook Secret**이 필요합니다.

## 주요 기능

- `Billable` 트레이트로 어떤 모델에도 결제 기능 추가
- 결제 사전 생성 (`PENDING`), 브라우저 결제 후 검증·확정 (`PAID`), 웹훅용 결제 ID만으로 확정
- 실결제액이 준비 금액과 다르면 PortOne 결제를 자동 전액 취소 (`AMOUNT_MISMATCH_CANCELLED`)
- 전액 / 부분 환불 (`PARTIALLY_CANCELLED` / `CANCELLED`), 콘솔 환불은 취소 웹훅·`syncByPaymentId()`로 레코드에 반영
- 모델별 결제 내역 조회
- 포트원 웹훅을 Laravel 이벤트로 수신 (`WebhookReceived`, 종류별 `PaymentPaid`·`PaymentCancelled`·`PaymentPartiallyCancelled`·`PaymentFailed`)
- 버려진 결제 대기 건을 PortOne에 확인해 만료(`EXPIRED`)하고 결과를 이벤트로 알림, 보관 기간 뒤 정리
- 테스트용 가짜 게이트웨이(`FakePaymentGateway`)와 서명된 웹훅 도우미, 로컬 웹훅 재전송 명령(`portone:webhook-replay`)
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
PORTONE_PENDING_EXPIRE_AFTER=
PORTONE_EXPIRED_RETENTION_DAYS=
PORTONE_AMOUNT_MISMATCH_CANCEL_REASON=
```

| 변수                                    | 설명                                                 | 기본값                         |
| --------------------------------------- | ---------------------------------------------------- | ------------------------------ |
| `PORTONE_API_SECRET`                    | 포트원 API Secret (결제 조회·취소용)                 | 없음 (필수)                    |
| `PORTONE_WEBHOOK_SECRET`                | 포트원 웹훅 Secret (웹훅 검증용)                     | 없음 (필수)                    |
| `PORTONE_BASE_URL`                      | PortOne API 주소 재정의 (비우면 PortOne 기본값 사용) | 없음                           |
| `PORTONE_WEBHOOK_PATH`                  | 웹훅 수신 경로                                       | `portone/webhook`              |
| `PORTONE_STORE_ID`                      | 포트원 상점 ID (브라우저 결제창용)                   | 없음 (필수)                    |
| `PORTONE_CHANNEL_KEY`                   | 포트원 채널 키 (브라우저 결제창용)                   | 없음 (필수)                    |
| `PORTONE_PENDING_EXPIRE_AFTER`          | 이 시간(분)보다 오래된 `PENDING` 결제를 만료 검사    | `1440` (24시간)                |
| `PORTONE_EXPIRED_RETENTION_DAYS`        | `EXPIRED` 결제를 지우기 전까지 보관할 일수           | `90`                           |
| `PORTONE_AMOUNT_MISMATCH_CANCEL_REASON` | 금액 불일치 자동 취소 때 PortOne에 보낼 취소 사유    | `결제 금액 불일치로 자동 취소` |

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

이미 설치한 앱을 새 버전으로 올릴 때는 [업그레이드](#업그레이드)를 보세요.

## 업그레이드

### 마이그레이션

새 버전에서 마이그레이션이 늘었으면 같은 명령을 다시 실행합니다.

```bash
php artisan vendor:publish --tag=portone-migrations
php artisan migrate
```

v0.4.0부터 `vendor:publish`는 **앱의 `database/migrations`에 이미 있는 마이그레이션을 건너뜁니다.**
Laravel은 발행할 때 파일명 앞 날짜를 발행 시각으로 바꾸므로, 날짜를 뺀 이름(예: `create_portone_payments_table.php`)으로
비교합니다. 새 마이그레이션만 복사되므로 그대로 `migrate`하면 됩니다.

> **v0.3.0에서 올리는 경우:** v0.3.0의 `vendor:publish`는 이미 있는 `create_portone_customers_table`·`create_portone_payments_table`을
> 새 날짜로 **다시 복사**했습니다. 그 상태로 `migrate`하다 실패했다면, 새로 복사된 중복 파일(날짜만 다르고 이름이 같은 파일 중
> 아직 실행되지 않은 것)을 지운 뒤 위 명령을 다시 실행하세요. `php artisan migrate:status`에서 `Pending`인 것이 중복본입니다.

| 버전   | 추가된 마이그레이션                              | 내용                                                                                           |
| ------ | ------------------------------------------------ | ---------------------------------------------------------------------------------------------- |
| v0.3.0 | `add_paid_amount_to_portone_payments_table`      | `paid_amount` 컬럼 추가                                                                        |
| v0.4.0 | `backfill_paid_amount_on_portone_payments_table` | 이미 결제된(`PAID`·`PARTIALLY_CANCELLED`·`CANCELLED`) 레코드의 `paid_amount`를 `amount`로 채움 |

### 버전별로 추가된 설정 키

이미 발행한 `config/portone.php`에는 새 키가 없습니다. 기본값이 있어 그대로도 동작하지만, 값을 바꾸려면 아래 줄을
`config/portone.php`에 추가하거나 환경 변수만 설정하세요.

| 버전   | 설정 키                         | 환경 변수                               | 기본값                         |
| ------ | ------------------------------- | --------------------------------------- | ------------------------------ |
| v0.3.0 | `amount_mismatch_cancel_reason` | `PORTONE_AMOUNT_MISMATCH_CANCEL_REASON` | `결제 금액 불일치로 자동 취소` |

```php
// config/portone.php (v0.3.0에서 추가)
'amount_mismatch_cancel_reason' => env('PORTONE_AMOUNT_MISMATCH_CANCEL_REASON') ?: '결제 금액 불일치로 자동 취소',
```

### 버전별 동작 변경

앱 코드에 영향이 있을 수 있는 변경입니다.

- **v0.5.0**
  - **취소 웹훅 때 결제 레코드 갱신(동작 변경):** `Transaction.Cancelled`·`Transaction.PartialCancelled` 웹훅을 받으면 이벤트를 보내기 **전에**
    PortOne에 결제를 조회해 `status`와 `cancelled_amount`를 맞춥니다(`CANCELLED`·`PARTIALLY_CANCELLED`, 콘솔에서 취소한 `AMOUNT_MISMATCH`는
    `AMOUNT_MISMATCH_CANCELLED`). 이전에는 웹훅이 레코드를 바꾸지 않았습니다. 취소 웹훅을 받아 직접 레코드를 고치던 코드는 지우거나,
    이미 맞춰진 레코드를 다시 고치지 않는지 확인하세요. 자세한 규칙은 [취소 웹훅과 결제 레코드 동기화](#취소-웹훅과-결제-레코드-동기화).
  - 취소 웹훅을 처리하려면 **`PORTONE_API_SECRET`이 필요합니다.** 조회가 실패하면 웹훅 요청이 오류로 끝나고(이벤트 없음) PortOne이 다시 보냅니다.
  - **새 이벤트:** 만료 확인 잡이 결과마다 `PaymentExpired`·`PaymentPaidOnExpiry`·`PaymentAmountMismatchedOnExpiry`를 보냅니다.
    만료 결과를 따로 다시 훑던 스케줄은 이 이벤트로 바꿀 수 있습니다.
  - 새 공개 API: `PaymentOperations::syncByPaymentId()`, `GatewayPayment::$cancelledAmount`, 테스트용 `Testing\FakePaymentGateway`·`Testing\InteractsWithPortOneWebhooks`,
    로컬 확인용 `php artisan portone:webhook-replay`.
  - 의존성: `cable8mm/portone-php` `^0.4.1`(웹훅 서명기 `WebhookSigner`), `illuminate/http`가 추가로 필요합니다. `composer update`로 함께 올라갑니다.
  - `PaymentGateway`를 직접 구현한 클래스는 바꿀 필요가 없습니다. 다만 `cancelledAmount`를 채우지 않으면(`null`) 취소 동기화가 레코드를 바꾸지 않습니다.
- **v0.4.0**
  - `paid_amount` 의미 변경: 금액 불일치 때만이 아니라 **PAID로 확정하는 모든 경우**에 PortOne이 알려 준 실결제액을 저장합니다.
    `PENDING`·`EXPIRED`만 `null`입니다. `paid_amount !== null`을 "금액 불일치"로 판단하던 코드는 `status`로 바꾸세요.
  - 새 이벤트: 거래 웹훅이면 `WebhookReceived` 다음에 `PaymentPaid`·`PaymentCancelled`·`PaymentPartiallyCancelled`·`PaymentFailed`
    중 하나가 추가로 발송됩니다. `WebhookReceived`는 그대로입니다. 두 이벤트를 모두 들으면 같은 웹훅을 두 번 처리하지 않게 하세요.
  - 새 공개 API: `PaymentOperations::completeByPaymentId()`, `PortOneGateway::paymentResponse()`, `php artisan portone:payment`.
- **v0.3.0**
  - 새 상태값 `AMOUNT_MISMATCH_CANCELLED`·`AMOUNT_MISMATCH`. 금액 불일치 결제는 PortOne에서 자동 전액 취소됩니다.

## 빠른 시작

아래 순서대로 따라 하면 로그인한 사용자가 브라우저에서 결제하고 서버에서 `PAID`로 확정하는 화면까지 한 번에 동작합니다. 아직 결제 화면이 없으니 체크아웃 출발 파일(스텁)로 만들고, `.env` 키만 넣으면 실제 포트원 결제창까지 실행해 볼 수 있습니다.

### 1. 모델에 `Billable` 추가

```php
use Cable8mm\LaravelPortone\Concerns\Billable;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use Billable;
}
```

### 2. 체크아웃 화면 설치 (스텁 발행)

결제 화면 출발점이 필요하면 스텁 5종을 발행합니다.

```bash
php artisan vendor:publish --tag=portone-stubs
```

| 발행 파일                                            | 설명                                                                                     |
| ---------------------------------------------------- | ---------------------------------------------------------------------------------------- |
| `resources/views/portone/checkout.blade.php`         | 주문서(상품명·금액) + 결제 버튼 + 상태 표시 뷰                                           |
| `resources/views/portone/completed.blade.php`        | 리다이렉트 복귀 화면 (결제 성공 시 주문 완료, 실패 시 실패 안내)                         |
| `resources/js/portone-checkout.js`                   | `prepare → requestPayment(forceRedirect) → 리다이렉트 복귀` 흐름의 ES 모듈 스크립트      |
| `app/Http/Controllers/PortOneCheckoutController.php` | `show / prepare / complete / completed` 액션과 상품 조회 `product()` (샘플 상품 `Order A`, 10,000원) |
| `routes/portone.php`                                 | `auth` 미들웨어의 체크아웃 라우트 4종                                                    |

발행한 파일을 애플리케이션에 연결합니다.

1. **라우트 등록** — 발행된 라우트 파일을 애플리케이션 라우트에 포함합니다.

   ```php
   // routes/web.php
   require __DIR__.'/portone.php';
   ```

2. **브라우저 SDK 설치** — 애플리케이션에서 직접 설치합니다 (패키지가 설치하지 않습니다).

   ```bash
   npm install @portone/browser-sdk
   ```

3. **프론트엔드 빌드** — 블레이드가 `@vite('resources/js/portone-checkout.js')`로 발행된 스크립트를 참조합니다. Laravel 기본 `vite.config.js`의 `input`에는 이 파일이 없으므로 엔트리로 추가합니다. 추가하지 않으면 `npm run dev`에서는 동작해도 `npm run build`의 manifest에 없어 프로덕션에서 오류가 납니다.

   ```js
   // vite.config.js
   laravel({
       input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/portone-checkout.js'],
       refresh: true,
   }),
   ```

   ```bash
   npm run dev   # 개발 서버 (프로덕션은 npm run build)
   ```

### 3. 환경 변수 설정

브라우저 결제창에는 `PORTONE_STORE_ID`·`PORTONE_CHANNEL_KEY`가, 서버 검증에는 `PORTONE_API_SECRET`이 필요합니다. `.env`에 값을 채웁니다 (전체 항목은 [설정](#1-환경-변수) 참고).

```dotenv
PORTONE_API_SECRET=
PORTONE_STORE_ID=
PORTONE_CHANNEL_KEY=
```

### 4. 화면 확인

체크아웃 라우트는 `auth` 미들웨어 뒤에 있으므로 로그인 후 `/portone/checkout`에 접속합니다. `PORTONE_STORE_ID`·`PORTONE_CHANNEL_KEY`가 설정되어 있어야 `Start payment` 버튼이 활성화되고, 클릭하면 실제 포트원 결제창이 열립니다.

체크아웃 흐름 (단일 리다이렉트 방식: PC·모바일 동일):

1. 구매자가 `Start payment` 클릭 → `POST portone.checkout.prepare`(상품 ID만 전송) → `PENDING` 레코드 생성
2. `@portone/browser-sdk/v2`의 `requestPayment` 호출 (`prepare` 응답값 + `CARD`, `KRW`, `redirectUrl`, `forceRedirect: true`) → 브라우저가 포트원으로 이동했다가 `redirectUrl`(`GET portone.checkout.completed/{paymentId}?paymentId=...`)로 복귀
3. 복귀 시 `completed` 액션이 서버에서 결제를 확정(`completePayment`)하고 주문 완료 화면을 렌더. 실패 복귀(`?code=`)나 확정 실패면 실패 화면, 레코드는 `PENDING` 유지
4. 준비 실패·SDK 호출 실패·결제창을 닫은 뒤 뒤로가기는 주문서의 `Error` 상태로 끝남
5. 주문 완료 화면은 내 결제이면서 결제된 적 있는 건(`PAID`·`PARTIALLY_CANCELLED`·`CANCELLED`)만 보여 주고, 결제 전·알 수 없는 건은 `404`

> 로컬에서 모바일 흐름을 확인할 때는 `redirectUrl`이 휴대폰에서 닿는 주소여야 합니다. `127.0.0.1` 대신 ngrok 공개 URL이나 LAN 호스트로 serve하세요.

### 5. 화면 커스터마이징

발행된 파일은 이제 애플리케이션 것이므로 자유롭게 고칩니다. 주문서(`show`)와 결제 준비(`prepare`)는
컨트롤러의 `product()` 하나에서 상품을 가져옵니다. 주석이 달린 샘플 상품을 데이터베이스 조회로 바꾸면 됩니다.

```php
// app/Http/Controllers/PortOneCheckoutController.php
private function product(Request $request): object
{
    // Replace this sample product with your product from the database,
    // e.g. return Product::findOrFail($request->input('product'));
    return (object) ['id' => 1, 'name' => 'Order A', 'price' => 10000];
}
```

상품 상세 화면에서 `route('portone.checkout', ['product' => $product->id])`로 링크하면 주문서가 그 상품을 보여 주고,
`Start payment` 클릭 시 상품 ID만 `prepare`로 전송됩니다. 금액·주문명은 언제나 서버의 상품에서 정해집니다.
`composer serve`의 워크벤치 샘플 상점이 이 한 곳만 고친 예시입니다([docs/TESTING.md](docs/TESTING.md) §1.2).

> `complete` 액션은 요청의 금액을 무시하고 서버 레코드 기준으로 검증하므로, 클라이언트 값을 조작해도 안전합니다.

## 서버 API

자신만의 UI·플로우를 만들거나 스텁 없이 직접 제어할 때 `Billable`이 제공하는 메서드를 사용합니다.

### 결제 준비 → 확정

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

실결제액이 준비 금액과 다르면 `completePayment()`는 결제를 자동으로 전액 취소하고
`AmountMismatchException`을 던집니다([결제 금액 불일치 자동 취소](#결제-금액-불일치-자동-취소)).

### 환불

```php
// 전액 환불
$user->refund($payment->payment_id, '구매자 요청');

// 부분 환불 (금액 지정)
$user->refund($payment->payment_id, '부분 환불', 3000);
```

전액 환불되면 `CANCELLED`, 일부만 환불되면 `PARTIALLY_CANCELLED`가 되며
`cancelled_amount`에 누적 환불액이 저장됩니다.

### 결제 내역 조회

```php
// 최신순 결제 목록
$payments = $user->payments()->latest()->get();

// 단건 조회 (다른 사용자의 payment_id는 조회되지 않음)
$payment = $user->findPayment($paymentId);
```

### 고객 ID

```php
$user->hasPortOneId();                  // bool
$user->portOneId();                     // ?string
$user->createOrGetPortOneCustomerId();  // string (없으면 생성)
```

`preparePayment()`를 호출하면 고객 레코드는 자동으로 생성되므로
보통은 직접 호출하지 않아도 됩니다.

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

- 서명 유효 → `WebhookReceived` 1건 발송 (+ 거래 웹훅이면 아래 결제 이벤트 1건) + `200` 빈 응답
- 서명 무효·헤더 누락·타임스탬프 범위 초과 → `400`, 이벤트 없음
- `PORTONE_WEBHOOK_SECRET` 미설정 → `500`, 이벤트 없음
- 취소 웹훅(`Transaction.Cancelled`·`Transaction.PartialCancelled`)만 이벤트 전에 결제 레코드를 PortOne과 맞춥니다([아래](#취소-웹훅과-결제-레코드-동기화)). 그 밖의 웹훅은 고객·결제 레코드를 읽거나 쓰지 않습니다

### 결제 이벤트

서명 검증이 끝난 거래 웹훅은 `WebhookReceived` 다음에 종류별 이벤트로도 발송됩니다. `type`을 직접 거를 필요가 없습니다.

| PortOne 웹훅 `type`            | 이벤트                      |
| ------------------------------ | --------------------------- |
| `Transaction.Paid`             | `PaymentPaid`               |
| `Transaction.Cancelled`        | `PaymentCancelled`          |
| `Transaction.PartialCancelled` | `PaymentPartiallyCancelled` |
| `Transaction.Failed`           | `PaymentFailed`             |

그 밖의 종류(`Transaction.Ready`·`VirtualAccountIssued`·`PayPending`·`CancelPending`·`DisputeCreated`, `BillingKey.*`, 알 수 없는 종류)는
`WebhookReceived`로만 받습니다. 각 이벤트(`Cable8mm\LaravelPortone\Events\*`)에는 `paymentId`, 원본 `webhook`(`WebhookTransaction`),
`webhookId`가 들어 있습니다.

### 취소 웹훅과 결제 레코드 동기화

PortOne 콘솔이나 다른 시스템에서 환불하면 `Transaction.Cancelled`·`Transaction.PartialCancelled` 웹훅이 옵니다. 패키지는 서명을 검증한 뒤
**이벤트를 보내기 전에** PortOne에 결제를 다시 조회해 레코드를 맞춥니다. 그래서 `WebhookReceived`·`PaymentCancelled`·`PaymentPartiallyCancelled`
리스너는 이미 갱신된 레코드를 읽습니다. 웹훅 내용이 아니라 PortOne 조회 결과(`amount.cancelled`)를 기준으로 합니다.

| 로컬 상태                    | PortOne 조회 결과                             | 결과                                                  |
| ---------------------------- | --------------------------------------------- | ----------------------------------------------------- |
| `PAID`·`PARTIALLY_CANCELLED` | `CANCELLED`, 취소 총액 = `amount`             | `CANCELLED`, `cancelled_amount` = 취소 총액           |
| `PAID`·`PARTIALLY_CANCELLED` | `PARTIAL_CANCELLED`, 0 < 취소 총액 < `amount` | `PARTIALLY_CANCELLED`, `cancelled_amount` = 취소 총액 |
| `AMOUNT_MISMATCH`            | `CANCELLED`, 취소 총액 = `paid_amount`        | `AMOUNT_MISMATCH_CANCELLED`                           |
| 그 밖                        | 그 밖(아직 `PAID` 등)                         | 그대로. 이벤트는 평소처럼 보냄                        |

- `refund()`와 같은 잠금을 쓰고 절대값으로 저장하므로 같은 웹훅이 여러 번 와도 결과가 같습니다.
- `PENDING`·`EXPIRED`·`CANCELLED` 레코드나 이 패키지가 만들지 않은 결제는 PortOne을 조회하지 않고 이벤트만 보냅니다.
- PortOne 조회가 실패하면(설정 누락 포함) 이벤트 없이 웹훅 요청이 오류로 끝나 PortOne이 다시 보냅니다. 레코드는 그대로입니다.
- `amount`·`paid_at`·`paid_amount`는 바꾸지 않습니다.

웹훅 없이 같은 동기화를 하려면 `syncByPaymentId()`를 부르세요. 결제 ID만으로 레코드를 찾으며, 없으면 `PaymentNotFoundException`입니다.

```php
use Cable8mm\LaravelPortone\PaymentOperations;

$payment = app(PaymentOperations::class)->syncByPaymentId($paymentId);
```

### 웹훅으로 결제 확정

결제 레코드에 반영하는 것은 앱이 정합니다. 결제 완료 웹훅으로 결제를 확정하려면 `PaymentPaid`를 듣고
`PaymentOperations::completeByPaymentId()`를 부르세요. 결제 ID만으로 소유 모델(`billable_type`·`billable_id`)을 찾아
`completePayment()`와 같은 규칙(잠금, 이미 확정된 결제는 그대로 반환, 금액 불일치 자동 취소)으로 확정합니다. 결제가 없으면
`PaymentNotFoundException`입니다. PortOne에 다시 조회해 검증하므로 웹훅 내용만 믿고 상태를 바꾸지 않습니다.
PortOne을 호출하니 큐 잡으로 처리하세요.

```php
use Cable8mm\LaravelPortone\Events\PaymentPaid;
use Cable8mm\LaravelPortone\PaymentOperations;

Event::listen(PaymentPaid::class, function (PaymentPaid $event) {
    CompletePaymentFromWebhook::dispatch($event->paymentId);
});

// 큐 잡의 handle()
public function handle(PaymentOperations $payments): void
{
    $payments->completeByPaymentId($this->paymentId); // PENDING·EXPIRED → PAID
}
```

워크벤치 예시(`workbench/app/Listeners/QueuePaymentCompletion.php`, `workbench/app/Jobs/CompletePaymentFromWebhook.php`)는
v0.4.0 이전 방식(`WebhookReceived`에서 `type` 확인, Billable 모델을 찾아 `completePayment()` 호출)을 보여 줍니다. 그 방식도 그대로 동작합니다.

## 결제 상태

`portone_payments.status`는 다음 중 하나입니다.

- `PENDING` → `PAID` → `PARTIALLY_CANCELLED` → `CANCELLED`
- `PAID`에서 바로 `CANCELLED` (전액 환불)도 가능
- `CANCELLED`는 최종 상태입니다
- 버려진 결제: `PENDING` → `EXPIRED`. 만료 뒤에 결제되면 `completePayment()`로 `EXPIRED` → `PAID` 복구
- `EXPIRED`는 환불할 수 없습니다 (`InvalidPaymentStatusException`)
- 금액 불일치: `PENDING`·`EXPIRED` → `AMOUNT_MISMATCH_CANCELLED`(자동 취소 성공) 또는 `AMOUNT_MISMATCH`(자동 취소 실패).
  두 상태는 환불할 수 없습니다. `AMOUNT_MISMATCH`를 PortOne 콘솔에서 취소하면 [취소 동기화](#취소-웹훅과-결제-레코드-동기화)가
  `AMOUNT_MISMATCH_CANCELLED`로 바꿉니다
- PortOne 콘솔 등에서 환불한 결제는 취소 웹훅이나 `syncByPaymentId()`로 `PARTIALLY_CANCELLED`·`CANCELLED`가 됩니다

`paid_amount`는 **PortOne이 알려 준 실결제액**입니다. PAID로 확정할 때와 금액 불일치 때 저장하므로, 상태와 상관없이
`paid_amount` 하나로 실결제액을 볼 수 있습니다. `PENDING`·`EXPIRED`처럼 PortOne이 아직 실결제액을 알려 주지 않은 결제만 `null`입니다.
환불해도 `paid_amount`는 바뀌지 않고, 환불액은 `cancelled_amount`에 쌓입니다.

## PortOne 원본 결제 응답 보기

자동 테스트에 쓸 실제 PortOne 응답을 기록하거나 결제를 들여다볼 때 씁니다.

```bash
php artisan portone:payment {paymentId}        # 개인정보·카드·계좌 정보를 가린 JSON
php artisan portone:payment {paymentId} --raw  # 가리지 않은 원본 JSON
```

기본 출력은 다음 키의 값을 깊이와 상관없이 `[REDACTED]`로 바꿉니다. 키는 남겨 두므로 응답 구조는 실제와 같습니다.

- 고객 개인정보: `name`, `birthYear`, `birthMonth`, `birthDay`, `gender`, `email`, `phoneNumber`, `address`, `zipcode`, `customerIdentityNumber`
- 카드 식별 정보: `number`, `bin`, `approvalNumber`
- 계좌 식별 정보: `accountNumber`, `remitteeName`, `remitterName`, `depositorName`
- PG 원본 응답: `pgResponse` (JSON 문자열이라 통째로 가림. 카드번호·승인번호 포함)
- 접속·전달 기록: `ipAddress`, `userAgent`, `receiptUrl`, `webhooks`(웹훅 전달 기록, 서명 헤더 포함)

`name`은 키 이름으로 가리므로 카드사 상품명처럼 개인정보가 아닌 `name`도 가려집니다. `--raw` 출력은 기록·공유하지 마세요.

코드에서는 `PortOneGateway::paymentResponse($paymentId)`가 원본 배열을 돌려주고, `PaymentResponseRedactor::redact()`로 같은 방식으로 가릴 수 있습니다.

```php
use Cable8mm\LaravelPortone\PaymentResponseRedactor;
use Cable8mm\LaravelPortone\PortOneGateway;

$response = app(PortOneGateway::class)->paymentResponse($paymentId);
$safe = app(PaymentResponseRedactor::class)->redact($response);
```

## 결제 금액 불일치 자동 취소

브라우저가 `requestPayment()`에 넣는 금액은 구매자가 바꿀 수 있습니다. 그러면 PortOne에서는 결제가 `PAID`인데
금액이 서버가 준비한 금액(`amount`)과 다릅니다. 패키지는 이 결제를 그냥 두지 않고 PortOne에서 **실결제액 전액**을
취소합니다. 앱 코드가 PortOne API를 직접 부를 필요가 없습니다.

`completePayment()`가 PortOne 조회 결과 `PAID`이면서 금액이 다른 결제를 만나면:

1. PortOne에 전액 취소를 요청합니다. 사유는 `PORTONE_AMOUNT_MISMATCH_CANCEL_REASON`
   (기본값 `결제 금액 불일치로 자동 취소`)입니다.
2. 결과를 레코드에 저장합니다. 준비 금액(`amount`)과 `paid_at`은 바꾸지 않습니다.

   | 결과      | `status`                    | `paid_amount` | `cancelled_amount` |
   | --------- | --------------------------- | ------------- | ------------------ |
   | 취소 성공 | `AMOUNT_MISMATCH_CANCELLED` | 실결제액      | 실결제액           |
   | 취소 실패 | `AMOUNT_MISMATCH`           | 실결제액      | `0`                |

3. `AmountMismatchException`을 던집니다.

```php
use Cable8mm\LaravelPortone\Exceptions\AmountMismatchException;

try {
    $user->completePayment($paymentId);
} catch (AmountMismatchException $e) {
    $e->paidAmount();   // PortOne 실결제액 (원)
    $e->wasCancelled(); // 자동 취소 성공 여부
    $e->getPrevious();  // 취소 실패 때 PortOne 예외, 성공이면 null
}
```

- 같은 결제를 다시 완료 요청하면(브라우저와 웹훅이 겹칠 때 등) PortOne을 다시 부르지 않고 저장된 레코드로 같은 예외를 던집니다.
- 만료 검사 잡(`portone:expire-pending`)도 같은 결제를 만나면 똑같이 자동 취소합니다. 취소가 성공하면 잡은 정상 종료하고,
  실패하면 `AMOUNT_MISMATCH`를 저장한 뒤 PortOne 예외로 실패 잡이 됩니다.
- `AMOUNT_MISMATCH`는 **운영자가 처리해야 하는 건**입니다. 패키지는 다시 시도하지 않습니다. 포트원 콘솔에서 결제를 확인해
  직접 취소하고 레코드를 정리하세요.

  ```php
  Payment::query()->where('status', 'AMOUNT_MISMATCH')->get();
  ```

## 결제 대기 건 만료와 정리

결제를 시작(`prepare`)하고 끝내지 않은 `PENDING` 결제는 그대로 쌓입니다. 패키지는 스케줄을
등록하지 않으므로, 앱의 스케줄러와 큐 워커로 정리합니다.

```php
// routes/console.php
use Cable8mm\LaravelPortone\Models\Payment;
use Illuminate\Support\Facades\Schedule;

Schedule::command('portone:expire-pending')->everyTenMinutes();
Schedule::command('model:prune', ['--model' => [Payment::class]])->daily();
```

- `portone:expire-pending`은 `PORTONE_PENDING_EXPIRE_AFTER`분보다 오래된 `PENDING` 결제마다
  확인 잡을 큐에 넣습니다(PortOne 호출 없음). 앱의 기본 큐 연결을 쓰므로 `queue:work`가 떠 있어야 합니다.
- 잡은 PortOne에 결제를 조회해 결제·금액 일치면 `PAID`, 결제되지 않았으면(`READY`·`FAILED`·
  `PAYMENT_NOT_FOUND`) `EXPIRED`로 바꾸고, 그 밖에는 그대로 둬서 다음 검사에서 다시 확인합니다.
  설정 누락이나 PortOne 실패는 실패 잡으로 남습니다.
- `EXPIRED`는 "확인 시점에 결제되지 않음"입니다. 같은 결제 ID로 나중에 결제될 수 있으므로
  (실제 관측), `completePayment()`를 부르면 `PAID`로 복구됩니다. 웹훅으로 자동 복구하려면
  [웹훅](#웹훅)의 리스너 예시를 참고하세요.
- 잡은 레코드를 바꾼 뒤(커밋 뒤) 결과를 이벤트로 알립니다. 바꾼 것이 없으면 보내지 않습니다.

  | 결과                                | 이벤트                                             |
  | ----------------------------------- | -------------------------------------------------- |
  | 미결제 확정 → `EXPIRED`             | `PaymentExpired`                                   |
  | 뒤늦은 결제(금액 일치) → `PAID`     | `PaymentPaidOnExpiry`                              |
  | 다른 금액으로 결제 → 자동 취소 규칙 | `PaymentAmountMismatchedOnExpiry` (`wasCancelled`) |

  각 이벤트에는 `paymentId`, 갱신된 `payment`, 소유 모델 참조 `billableType`·`billableId`가 들어 있습니다. 자동 취소가 실패하면
  (`wasCancelled` false, `AMOUNT_MISMATCH`) 이벤트를 보낸 다음 잡이 PortOne 예외로 실패합니다.

  ```php
  use Cable8mm\LaravelPortone\Events\PaymentExpired;

  Event::listen(PaymentExpired::class, function (PaymentExpired $event) {
      $order = Order::firstWhere('payment_id', $event->paymentId);
      $order?->markAbandoned();
  });
  ```

- `model:prune`은 `PORTONE_EXPIRED_RETENTION_DAYS`일이 지난 `EXPIRED` 결제만 지웁니다.
  결제된(`PAID`·`PARTIALLY_CANCELLED`·`CANCELLED`) 기록과 금액 불일치(`AMOUNT_MISMATCH_CANCELLED`·`AMOUNT_MISMATCH`) 기록은 절대 지우지 않습니다.
- 웹 요청(예: 관리자 화면의 버튼)에서 만료 검사를 바로 돌리려면 `Artisan::queue('portone:expire-pending')`를
  쓰세요. 패키지 명령은 콘솔에서만 등록되므로 웹 요청 안의 `Artisan::call()`은 "command does not exist"로 실패합니다.

## 예외

| 예외                                        | HTTP (스텁 기준) | 의미                                                                                |
| ------------------------------------------- | ---------------- | ----------------------------------------------------------------------------------- |
| `PaymentNotFoundException`                  | `422`            | 내 범위에 해당 `payment_id` 없음                                                    |
| `PaymentNotPaidException`                   | `422`            | PortOne에서 미결제 상태                                                             |
| `AmountMismatchException`                   | `422`            | 서버 금액과 PortOne 실결제액 불일치. 결제는 자동 전액 취소됨(`wasCancelled()`)      |
| `InvalidPaymentStatusException`             | —                | 결제되지 않았거나(`PENDING`·`EXPIRED`) 이미 전액 취소됐거나 금액 불일치 결제를 환불 |
| `InvalidRefundAmountException`              | —                | 환불 금액이 0 이하이거나 환불 가능액 초과                                           |
| `ConfigurationException`                    | `500`            | `api_secret` 등 필수 설정 누락                                                      |
| `PortOneException` (`cable8mm/portone-php`) | `500`            | PortOne API 실패 (원문 그대로 전달)                                                 |

## 테스트 도우미

앱의 테스트에서 PortOne 대신 쓸 가짜 게이트웨이입니다. PortOne에 요청하지 않고, 지정한 조회 응답을 돌려주며 호출을 기록합니다.

```php
use Cable8mm\LaravelPortone\Testing\FakePaymentGateway;

$gateway = FakePaymentGateway::bind() // 컨테이너의 PaymentGateway로 바인딩
    ->payment($paymentId, 'PAID', totalAmount: 10000, cancelledAmount: 0);

$user->completePayment($paymentId);
$user->refund($paymentId, '구매자 요청', 3000);

$gateway->assertLookedUp($paymentId, times: 1);
$gateway->assertCancelled($paymentId, fn (string $reason, ?int $amount) => $amount === 3000);
$gateway->assertNothingCancelled(); // 취소 요청이 없어야 할 때
```

- `payment($id, $status, $totalAmount, $cancelledAmount)`: 결제 ID별 조회 응답. `$status`는 PortOne 값(`PAID`, `PARTIAL_CANCELLED`, `CANCELLED`, `READY`, `FAILED` 등)입니다.
- 지정하지 않은 결제 ID는 PortOne이 모르는 결제로 봅니다. `lookup()`은 `PAYMENT_NOT_FOUND` 예외, 만료 확인 조회는 `found` false입니다.
- `failLookup($id, $exception)`, `failCancel($id, $exception)`: 조회·취소 실패. 실패한 취소 요청도 기록됩니다.
- `cancel()`은 기록만 하고 응답을 바꾸지 않습니다. 취소 뒤의 조회 결과가 필요하면 `payment()`로 다시 지정하세요.
- 확인 메서드: `assertLookedUp($id, ?times)`, `assertNotLookedUp($id)`, `assertNothingLookedUp()`, `assertCancelled($id, ?callback)`,
  `assertNotCancelled($id)`, `assertNothingCancelled()`. 기록은 `lookupCount(?id)`, `cancellations()`로 읽습니다.
- PHPUnit이 필요하므로 테스트에서만 쓰세요.

### 서명된 웹훅 보내기

`InteractsWithPortOneWebhooks`를 테스트 케이스에 넣으면 설정된 `PORTONE_WEBHOOK_SECRET`으로 서명한 웹훅을 패키지 웹훅 경로로 보냅니다.
서명은 `cable8mm/portone-php`의 `WebhookSigner`로 만들므로 실제 웹훅처럼 검증, 이벤트 발송, 취소 동기화를 모두 거칩니다.

```php
use Cable8mm\LaravelPortone\Testing\InteractsWithPortOneWebhooks;

class RefundListenerTest extends TestCase
{
    use InteractsWithPortOneWebhooks;

    public function test_cancelled_webhook(): void
    {
        $this->postPortOneWebhook('Transaction.Cancelled', $paymentId, ['cancellationId' => 'c-1'])->assertOk();
    }
}
```

본문의 `data`에는 결제 ID와 `PORTONE_STORE_ID`가 들어가고, 세 번째 인자가 같은 키를 덮어씁니다.

### 로컬에서 웹훅 재전송

공개 주소(ngrok 등) 없이 로컬 앱의 웹훅 흐름을 확인할 때 씁니다. 서명한 웹훅을 `APP_URL` 아래 웹훅 경로로 **HTTP 전송**하므로 앱이 떠 있어야 합니다.

```bash
php artisan portone:webhook-replay Transaction.Cancelled <paymentId>
php artisan portone:webhook-replay Transaction.PartialCancelled <paymentId> --data='{"cancellationId":"c-1"}'
```

- `transactionId`는 PortOne 결제 조회로 가져오므로 `PORTONE_API_SECRET`이 필요합니다. `--data`의 JSON 객체는 `data`에 더해집니다.
- 응답 상태를 출력하며, 2xx가 아니면 실패 코드로 끝납니다.
- **운영(`production`) 환경에서는 `--force` 없이 실행되지 않습니다.**

## 실서비스 적용 전 확인 사항

### 검증된 범위

포트원 **테스트 채널(NHN KCP)**, 결제 수단 **카드(`CARD`)**, 통화 **원화(`KRW`)**, `@portone/browser-sdk` `0.1.13`에서
사람이 결제창을 직접 조작하는 live 테스트로 확인했습니다.

- 체크아웃 스텁: 주문서 → 결제창 → 결제 완료 → 주문 완료 화면 / 결제창 닫기 → `Error`
- 서버 검증: 금액·주문명은 서버의 상품에서만 정해지고, `completePayment()`가 PortOne 조회로 상태와 금액을 확인
- 전액 취소, 여러 번 나눠 하는 부분 취소, PortOne이 거절한 취소
- 금액 불일치 자동 취소: `completePayment()`와 만료 검사 모두 PortOne에서 `CANCELLED`로 바뀜
- 웹훅 서명 검증과 `WebhookReceived` 발송 (ngrok 공개 URL)
- 포트원 콘솔에서 한 부분 취소·전액 취소가 웹훅으로 결제 레코드에 반영됨(`PARTIALLY_CANCELLED` 300원 → `CANCELLED` 1,000원, 이벤트 전에 갱신)
- `portone:webhook-replay`로 보낸 서명 웹훅이 앱의 검증을 통과하고 취소 동기화가 실행됨
- 결제 대기 건 만료: 결제창을 연 적 없음 `PAYMENT_NOT_FOUND`, 결제창이 열려 있음 `READY`, 닫음 `FAILED`,
  같은 결제 ID로 나중에 결제됨 `PAID`
- 만료 확인 결과 이벤트: 뒤늦은 결제 `PaymentPaidOnExpiry`, 창 닫기·결제창 미오픈 `PaymentExpired`

**검증하지 않은 것**: 운영(live) 채널, KCP 외 PG, 카드 외 결제 수단(가상계좌·간편결제 등), 원화 외 통화.

### 결제 수단·통화 바꾸기

발행된 `resources/js/portone-checkout.js` 맨 위의 상수를 바꿉니다.

```js
const PAYMENT_METHOD = "CARD";
const CURRENCY = "KRW";
```

바꾸면 위의 검증 범위를 벗어납니다. 특히 가상계좌처럼 결제창을 닫은 뒤에 입금되는 수단은 스텁 흐름
(리다이렉트 복귀 → `completed`에서 바로 확정)과 만료 규칙이 그 수단에 맞는지 확인되지 않았습니다. 테스트 채널에서
그 수단으로 결제·취소·만료 흐름을 먼저 돌려 보세요.

### 운영 전환 체크리스트

1. 운영 채널의 `PORTONE_STORE_ID`·`PORTONE_CHANNEL_KEY`와 `PORTONE_API_SECRET`으로 바꿉니다.
2. 포트원 콘솔에 웹훅 URL(`https://<도메인>/portone/webhook`, 또는 `PORTONE_WEBHOOK_PATH`)을 등록하고
   `PORTONE_WEBHOOK_SECRET`을 설정합니다.
3. Vite `input`에 `resources/js/portone-checkout.js`를 넣고 `npm run build`합니다([프론트엔드 빌드](#2-체크아웃-화면-설치-스텁-발행)).
4. 큐 워커와 스케줄러(`portone:expire-pending`, `model:prune`)를 운영합니다([결제 대기 건 만료와 정리](#결제-대기-건-만료와-정리)).
5. 소액으로 **실결제 → 주문 완료 화면 → 부분 취소 → 전액 취소**를 직접 해 보고, 웹훅이 도착하는지
   확인합니다.

### 알려진 제약

- **단건 결제만** 다룹니다. 구독·정기결제는 대상이 아닙니다.
- **환불(취소) 화면은 없습니다.** 관리자 화면에서 `refund()`를 직접 호출하세요.
- **같은 초에 만들어진 결제**는 `payments()` 결과에서 순서가 정해지지 않습니다.
- **웹훅은 중복 전달될 수 있고**, 패키지는 걸러내지 않아 전달마다 `WebhookReceived`가 발생합니다. 리스너는
  여러 번 실행돼도 안전하게 만드세요. `completePayment()`는 이미 `PAID`인 결제를 그대로 돌려주고, 금액 불일치 결제는 PortOne을 다시 부르지 않으므로 안전합니다.
- **취소 직후 로컬 저장 실패**: PortOne이 취소를 받아들인 뒤 DB 저장이 3번 연속 실패하면 예외가 발생하고,
  레코드는 취소 전 상태로 남아 PortOne과 어긋납니다. 이때 `refund()`를 다시 부르면 취소 요청이 다시 가므로(PortOne이 거절할 것으로 예상),
  레코드를 직접 바로잡아야 합니다.
- **패키지 명령은 콘솔에서만 등록**되므로 웹 요청에서는 `Artisan::queue()`를 쓰세요.

## 개발 및 테스트

이 패키지에 기여하거나 live/e2e 테스트를 직접 돌려보려면 [`docs/`](./docs)를 참고하세요.

- [`docs/CONVENTIONS.md`](./docs/CONVENTIONS.md) — 명령·CI·PR 체크리스트·코딩 규칙
- [`docs/TESTING.md`](./docs/TESTING.md) — 단위 테스트와 실제 포트원 연동(live/e2e) 실행법

```bash
composer analyse && composer test   # 오프라인 검증
```

## 라이선스

MIT
