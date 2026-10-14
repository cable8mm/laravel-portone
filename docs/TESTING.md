# Testing

이 패키지는 두 계층으로 테스트합니다.

1. **오프라인 (CI)** — `composer test` + `npm test`. 네트워크 없이, 포트원 호출은
   테스트 더블로 대체합니다. PR마다 자동 실행.
2. **Live / E2E (수동)** — 실제 포트원 테스트 채널·브라우저·(웹훅은) ngrok으로 돌립니다.
   사람이 PG 결제창을 조작해야 하므로 **CI에서는 절대 실행하지 않고**, 게이트 플래그를
   켜야만 동작합니다.

> 근거: `.replworks/TECH_STACK.md` §4 — "Live verification MUST NOT run in CI.",
> "Live webhook verification MUST use an ngrok public URL."

---

## 1. 오프라인 테스트 (가장 먼저 돌리는 것)

```bash
composer install
composer analyse      # PHPStan 에러 0 확인
composer test         # PHPUnit 단위 테스트
npm ci
npm run check         # Prettier + ESLint
npm test              # Vitest (jsdom)
```

`composer test`는 live 테스트가 `markTestSkipped()`로 **자동 스킵**되므로 안전합니다.
게이트 플래그를 주지 않으면 아래 live 테스트는 전부 건너뜁니다.

### 1.1 테스트 하네스: Orchestra Testbench

PHP 테스트는 **Orchestra Testbench** 위에서 돕니다. Testbench은 패키지가 실제 사이트에
붙을 때와 **같은 Laravel 앱을 인-프로세스로 부팅**하므로, 테스트가 검증하는 경로는
운영 코드와 동일합니다(프로바이더·라우트·컨트롤러·모델·마이그레이션).

- 베이스 클래스는 `Orchestra\Testbench\TestCase`입니다. 공용 `tests/TestCase.php`는
  없고 각 테스트가 직접 상속합니다.
- `getPackageProviders()`로 `PortOneServiceProvider`를 등록해 설정 병합·라우트 등록
  (웹훅 라우트 등)·컨테이너 바인딩이 실제와 똑같이 일어납니다.
- DB는 `phpunit.xml.dist`의 `sqlite :memory:`를 쓰고, 각 테스트 헬퍼
  `migratePackageTables()`가 `database/migrations/*`를 `artisan migrate`로 실제 구동합니다.
- **포트원 API 경계만 테스트 더블로 바꿉니다.** `PaymentGateway` 목을 컨테이너에
  주입(`$this->app->instance(PaymentGateway::class, $mock)`)해 네트워크 없이 조회·취소
  응답을 흉내냅니다. 즉 운영 코드는 그대로 두고 외부 호출만 목으로 친 셈이라, 나머지는
  운영 경로와 1:1로 같습니다.

> 체크아웃 스텁(뷰·컨트롤러·라우트)은 테스트가 직접 발행·포함한 뒤 HTTP로 검증하므로,
> 사용자가 `vendor:publish --tag=portone-stubs`로 받는 파일이 실제로 도는지까지
> 오프라인에서 확인합니다.

### 1.2 실제 서버로 띄워 보기 (`composer serve`)

브라우저 e2e(§2) 없이 **실제 Laravel HTTP 서버**를 띄워 수동으로 찔러볼 수 있습니다.

```bash
composer serve   # testbench workbench:build + testbench serve (기본 http://127.0.0.1:8000)
```

- 이 서버는 `workbench/`의 워크벤치 앱(`Workbench\App\Models\User`,
  `WorkbenchServiceProvider`)을 띄웁니다. 패키지가 **자동 등록**하는 라우트 — 예:
  `POST /portone/webhook` — 를 curl 등으로 바로 확인하기 좋습니다.
- 체크아웃 스텁 라우트는 워크벤치에 자동 포함되지 않습니다(호스트 앱에 발행·포함하는
  구조). 그것까지 보려면 §2의 브라우저 라우터를 쓰세요. `composer serve`는 웹훅처럼
  자동 등록되는 경로를 빠르게 확인하는 용도입니다.
- 워크벤치가 꼬이면 `composer build`로 워크벤치를 다시 빌드하세요.

---

## 2. Live / E2E 테스트

### 2.1 사전 준비

**자격증명** — 포트원 테스트 채널의 값을 루트 `.env`에 넣습니다(`.env`는 gitignore됨).
`.env.example`를 복사해 시작하세요.

```bash
cp .env.example .env
```

```dotenv
PORTONE_API_SECRET=...        # 결제 조회·취소용
PORTONE_WEBHOOK_SECRET=...    # 웹훅 검증용 (웹훅 테스트에서만)
PORTONE_STORE_ID=...
PORTONE_CHANNEL_KEY=...
PORTONE_BASE_URL=             # 비우면 포트원 기본값
```

테스트는 이 값을 `Dotenv`으로 읽습니다. 시크릿은 절대 커밋·로그·픽스처에 남기지 마세요.

**의존성** — 브라우저 테스트는 SDK를 정적 파일로 서빙하므로 `npm ci`가 선행되어야
`node_modules/@portone/browser-sdk`가 존재합니다.

**세션 디렉터리** — 브라우저 테스트는 임시 디렉터리로 PHP 테스트와 브라우저 라우터가
파일로 통신합니다. 실행 전 `mkdir`로 만들어 두세요(테스트가 존재를 단정합니다).

### 2.2 브라우저 라우터 서버 띄우기 (핵심)

브라우저 테스트들은 사람에게 `http://127.0.0.1:8765/...`를 열라고 안내하지만, **그 포트를
서빙하는 서버는 당신이 직접 띄워야 합니다.** 패키지 루트에서 아래처럼 PHP 내장 서버에
해당 테스트의 라우터 스크립트를 붙입니다.

```bash
php -S 127.0.0.1:8765 tests/live/portone-checkout-flow-browser.php
```

- 라우터는 자기 경로(`/checkout` 등)만 처리하고, 나머지는 `return false` → PHP 내장 서버가
  패키지 루트를 문서 루트로 정적 서빙합니다. 그래서
  `/node_modules/@portone/browser-sdk/...`가 함께 제공됩니다(검증 완료: 200).
- 세션 디렉터리 환경변수를 **같이** export한 뒤 서버를 띄우세요(미설정이면 모든 요청이
  503 "session directory not configured"로 떨어집니다).
- **한 번에 하나의 테스트만** 같은 포트로 돌리세요. 테스트가 끝나면 `Ctrl+C`로 서버를
  내리고 다음 테스트용 라우터로 교체합니다.

테스트 ↔ 라우터 매핑:

| 라이브 테스트                   | 라우터 스크립트                          | URL 경로      | 필수 세션 env                       |
| ------------------------------- | ---------------------------------------- | ------------- | ----------------------------------- |
| `PortOneCheckoutFlowTest`       | `portone-checkout-flow-browser.php`      | `/checkout`   | `PORTONE_CHECKOUT_FLOW_SESSION_DIR` |
| `PortOnePaymentCompletionTest`  | `portone-payment-completion-browser.php` | `/completion` | `PORTONE_COMPLETION_SESSION_DIR`    |
| `PortOneCheckoutCompletionTest` | `portone-payment-completion-browser.php` | `/completion` | `PORTONE_COMPLETION_SESSION_DIR`    |
| `PortOnePaymentRefundTest`      | `portone-payment-refund-browser.php`     | `/refund`     | `PORTONE_REFUND_SESSION_DIR`        |
| `PortOneWebhookTest`            | `portone-webhook-browser.php`            | `/webhook`    | `PORTONE_WEBHOOK_SESSION_DIR`       |

> `PortOnePaymentCancellationTest`는 **브라우저 서버가 필요 없습니다**(서버↔API만).
> `PortOnePaymentCompletionTest`·`PortOneCheckoutCompletionTest`는 같은 라우터·같은
> `/completion` 경로를 쓰므로 동시에 띄우지 마세요.

### 2.3 테스트별 실행

각 테스트는 **게이트 플래그 `=1`**로 켜고, 해당 PHPUnit 테스트만 필터 실행합니다.

```bash
# 예: 체크아웃 플로우
export PORTONE_CHECKOUT_FLOW_LIVE=1
export PORTONE_CHECKOUT_FLOW_SESSION_DIR=/tmp/pf-session   # 미리 mkdir
php -S 127.0.0.1:8765 tests/live/portone-checkout-flow-browser.php &   # 라우터
composer test -- --filter PortOneCheckoutFlowTest
```

아래 표로 전체 게이트 플래그와 추가 요구사항을 정리했습니다.

| 테스트                           | 게이트 플래그                      | 추가 요구사항                                                                                   |
| -------------------------------- | ---------------------------------- | ----------------------------------------------------------------------------------------------- |
| `PortOnePaymentCompletionTest`   | `PORTONE_COMPLETE_LIVE=1`          | `PORTONE_COMPLETION_SESSION_DIR`                                                                |
| `PortOneCheckoutCompletionTest`  | `PORTONE_CHECKOUT_COMPLETE_LIVE=1` | `PORTONE_COMPLETION_SESSION_DIR`                                                                |
| `PortOnePaymentRefundTest`       | `PORTONE_REFUND_LIVE=1`            | `PORTONE_REFUND_SESSION_DIR` + 완료 관측(`portone-payment-completion-observation.json`)         |
| `PortOneCheckoutFlowTest`        | `PORTONE_CHECKOUT_FLOW_LIVE=1`     | `PORTONE_CHECKOUT_FLOW_SESSION_DIR`                                                             |
| `PortOnePaymentCancellationTest` | `PORTONE_CANCEL_LIVE=1`            | `PORTONE_CANCEL_PARTIAL_PAYMENT_ID` + 브라우저 SDK 관측(`portone-browser-sdk-observation.json`) |
| `PortOneWebhookTest`             | `PORTONE_WEBHOOK_LIVE=1`           | `PORTONE_WEBHOOK_SESSION_DIR`, `PORTONE_WEBHOOK_OBSERVATION_DIR`, `PORTONE_WEBHOOK_PUBLIC_URL`  |

공통 자격증명: `PORTONE_API_SECRET`, `PORTONE_STORE_ID`, `PORTONE_CHANNEL_KEY`
(웹훅은 추가로 `PORTONE_WEBHOOK_SECRET`).

### 2.4 사람이 하는 일

테스트를 실행하면 STDOUT에 안내가 뜨고, 테스트는 브라우저가 파일로 신호를 보낼 때까지
대기합니다(타임아웃 900초). 예:

```text
Open http://127.0.0.1:8765/checkout and click Start payment.
Complete the 10,000 KRW PortOne test payment.
```

1. 브라우저에서 안내된 URL을 엽니다.
2. 포트원 테스트 결제창에서 **결제 완료**(또는 테스트 의도에 따라 **창만 닫기**)를 합니다.
3. 페이지가 결과를 라우터에 전송하면 PHP 테스트가 이어서 서버 검증을 마치고 종료합니다.

- 체크아웃 플로우 테스트는 **성공 케이스**(결제 완료 → `Success`)와 **실패 케이스**
  (창 닫기 → `Error`, 완료 요청 없음) 두 메서드를 가집니다.
- 웹훅 테스트는 결제 완료 후 **포탈 웹훅이 ngrok 공개 URL로 실제로 도착**하는지 봅니다.

### 2.5 웹훅 live 테스트 (ngrok)

웹훅은 포트원이 **공개 URL로 콜백**해야 하므로 로컬 터널이 필요합니다.

```bash
ngrok http 8765
# 발급된 https URL 을 PORTONE_WEBHOOK_PUBLIC_URL 에 넣고,
# 포트원 콘솔 웹훅 설정도 그 URL + /portone/webhook 으로 맞춥니다.
```

그리고 2.3 표의 env를 모두 설정한 뒤 웹훅 라우터를 띄우고 실행합니다.

---

## 3. 독립 관측 probe (T-002 / T-003)

패키지 코드와 무관하게 **외부 경계 동작 자체를 관측**하는 독립 스크립트입니다.
결과는 `tests/live/portone-*-observation.json`에 기록되고, 일부 live 테스트가 이를
입력으로 읽습니다.

**브라우저 SDK probe (T-002)** — PG 창이 열리는지·완료/미완료 결과 코드를 관측:

```bash
php -S 127.0.0.1:8765 tests/live/portone-browser-sdk-probe.php
# 브라우저에서 열고: 한 번은 결제 완료, 한 번은 창만 닫기
```

**결제 조회 probe (T-003)** — 위 관측의 paymentId로 상태·금액을 조회:

```bash
php tests/live/portone-payment-lookup-probe.php
```

> 관측 파일은 의도된 출력입니다(커밋 대상). 다만 **시크릿 값이 들어가지 않도록** 주의하세요.

---

## 4. 문제 해결

| 증상                                   | 원인 / 해결                                               |
| -------------------------------------- | --------------------------------------------------------- |
| 테스트가 전부 "skipped"                | 게이트 플래그(`*_LIVE=1`) 미설정                          |
| 브라우저가 연결 실패                   | 라우터 서버(`php -S`) 미실행 또는 포트 불일치             |
| 503 "session directory not configured" | 세션 env 미설정 — 라우터 띄우기 전에 export               |
| 503 waiting for view/script            | 정상 — 테스트가 아직 페이지를 준비 중                     |
| SDK 로딩 실패(404)                     | `npm ci` 미실행 또는 라우터를 패키지 루트에서 띄우지 않음 |
| 웹훅 미도착                            | ngrok 미실행 또는 콘솔 웹훅 URL 불일치                    |
| 타임아웃(900초)                        | 브라우저에서 결제를 끝내지 않음                           |
| 워크벤치 라우트/프로바이더가 이상함    | `composer build`로 워크벤치 재빌드                        |
