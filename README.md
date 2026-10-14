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

결제 화면 출발점이 필요하면 스텁 4종을 발행합니다.

```bash
php artisan vendor:publish --tag=portone-stubs
```

| 발행 파일                                            | 설명                                                                    |
| ---------------------------------------------------- | ----------------------------------------------------------------------- |
| `resources/views/portone/checkout.blade.php`         | 결제 버튼 + 상태 표시 뷰                                                |
| `resources/js/portone-checkout.js`                   | `prepare → requestPayment → complete` 흐름의 ES 모듈 스크립트           |
| `app/Http/Controllers/PortOneCheckoutController.php` | `show / prepare / complete` 액션과 상품 조회 `product()` (샘플 상품 `Order A`, 10,000원) |
| `routes/portone.php`                                 | `auth` 미들웨어의 체크아웃 라우트 3종                                   |

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

3. **프론트엔드 실행** — 블레이드가 `@vite('resources/js/portone-checkout.js')`로 발행된 스크립트를 참조합니다. Laravel 기본 Vite 설정을 그대로 쓰면 되며 추가 설정은 없습니다.

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

체크아웃 흐름:

1. 구매자가 `Start payment` 클릭 → `POST portone.checkout.prepare`(상품 ID만 전송) → `PENDING` 레코드 생성
2. `@portone/browser-sdk/v2`의 `requestPayment` 호출 (storeId, channelKey, paymentId, orderName, totalAmount, customerId, `CARD`, `KRW`)
3. 에러 코드 없이 반환되면 `POST portone.checkout.complete` (`paymentId`만 전송)
4. `PAID` 응답이면 `Success`, 그 외(준비 실패·SDK 실패·미결제·금액 불일치 등)는 `Error`

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

## 개발 및 테스트

이 패키지에 기여하거나 live/e2e 테스트를 직접 돌려보려면 [`docs/`](./docs)를 참고하세요.

- [`docs/CONVENTIONS.md`](./docs/CONVENTIONS.md) — 명령·CI·PR 체크리스트·코딩 규칙
- [`docs/TESTING.md`](./docs/TESTING.md) — 단위 테스트와 실제 포트원 연동(live/e2e) 실행법

```bash
composer analyse && composer test   # 오프라인 검증
```

## 라이선스

MIT
