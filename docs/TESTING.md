# Testing

이 패키지는 두 계층으로 테스트합니다.

1. **오프라인 (CI)** — `composer test` + `npm test`. 네트워크 없이, 포트원 호출은
   테스트 더블로 대체합니다. PR마다 자동 실행.
2. **Live / E2E (수동)** — 실제 포트원 테스트 채널·브라우저·(웹훅은) ngrok으로 돌립니다.
   사람이 PG 결제창을 조작해야 하므로 **CI에서는 절대 실행하지 않고**, `composer live`로만
   실행합니다.

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

`composer test`는 `tests/E2E/`를 **제외**하므로 live 테스트가 섞여 돌지 않습니다.
live 테스트는 `phpunit.dusk.xml`만 포함합니다.

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

### 1.2 워크벤치 샘플 상점 (`composer serve`)

패키지를 설치한 Laravel 앱이 실제로 어떻게 동작하는지 **SQLite 위에서 그대로 체험**하는 샘플 상점입니다.
개발자가 코드를 보면서 결제창을 직접 조작하는 수동 E2E 도구이기도 합니다.

```bash
npm ci           # 처음 한 번 (Vite·Tailwind CSS v4·@portone/browser-sdk)
composer serve   # composer build(에셋 + DB) 후 testbench serve (기본 http://127.0.0.1:8000)
```

- `composer build`는 두 가지를 합니다.
  - `npm run workbench:build`: `workbench/vite.config.js`로 Tailwind CSS v4와 체크아웃 스크립트를 빌드해
    Testbench 스켈레톤의 `public/build`에 넣습니다. 화면을 고치면서 보려면 `npm run workbench:dev`를 함께 띄우세요.
  - `workbench:build`: SQLite DB를 만들고 기본·패키지·워크벤치 마이그레이션을 돌린 뒤
    사용자 `test@example.com`과 샘플 상품을 시드합니다.
- `/`는 **개발자 안내** 페이지입니다. 자동 로그인하지 않습니다. `PORTONE_*` 설정 여부(값은 표시하지 않음),
  라우트, 결제 흐름, 테스트 명령을 보여 주고, **실제 구현 체험하기**를 누르면 시드된 사용자로 로그인해
  상점(`/shop`)으로 이동합니다.
- 상점 흐름: 상품 목록 → 상품 상세(아무것도 저장하지 않음) → **주문서**(체크아웃 스텁 `show`) →
  **결제하기**(`prepare`가 PENDING 생성) → 포트원 결제창(사람이 조작) → `complete` → 결제 내역·상세 →
  **전액 취소** / **부분 취소**(여러 번 나눠서). 검증된 웹훅은 **웹훅 로그**에 쌓입니다(로컬에서는 ngrok 필요, §2.5).
- 패키지의 모든 기능을 상점에서 해 볼 수 있습니다. 안내 페이지의 **패키지 기능 지도**가 기능별 위치를 보여 줍니다.
  - 포트원 고객(`hasPortOneId`·`portOneId`·`createOrGetPortOneCustomerId`): **고객** 화면
  - 결제 대기 건 다시 확인(`completePayment`): 결제 상세의 **포트원에서 결제 확인**
    (결제창에서 결제했는데 브라우저가 닫혀 완료 처리가 안 된 경우)
  - 전액·부분 취소(`refund`): 결제 상세
- 모든 화면 아래의 **이 화면의 코드** 패널이 그 화면을 만드는 파일과 핵심 호출을 보여 줍니다.
- 주문서는 발행되는 체크아웃 스텁의 **워크벤치 사본**입니다(`workbench/app/Http/Controllers/PortOneCheckoutController.php`,
  `workbench/routes/portone.php`, `workbench/resources/js/portone-checkout.js`). 스텁과 다른 곳은 컨트롤러의
  `product()` 본문(`Product::findOrFail(...)`)과 namespace, 그리고 뷰의 레이아웃·스타일뿐이며,
  `WorkbenchSampleShopTest`가 이 차이를 강제합니다.
- 스텁을 앱에 직접 발행해 두면(`vendor:publish --tag=portone-stubs`) 워크벤치 사본 대신 발행본이 쓰입니다.
  PHPUnit 실행(live 테스트 포함)에서는 워크벤치 사본이 등록되지 않으므로, 테스트는 항상 발행본을 검증합니다.
- **결제 대기 만료(큐 · 스케줄러)**: 워크벤치 앱이 `portone:expire-pending`(10분마다)과
  `model:prune`(매일)을 스케줄에 등록합니다. 샘플은 `testbench.yaml`에서
  `PORTONE_PENDING_EXPIRE_AFTER=1`로 1분 뒤 검사합니다(실서비스 기본값 1440분). 별도 터미널에서
  큐 워커와 스케줄러를 띄우세요.

  ```bash
  vendor/bin/testbench queue:work
  vendor/bin/testbench schedule:work
  ```

  결제 내역의 **지금 만료 검사 실행**은 웹 요청이라 `Artisan::queue()`로 명령을 큐에 넣고, 큐 워커가
  실행합니다(패키지 명령은 콘솔에서만 등록되므로 웹에서 `Artisan::call()`은 실패합니다).

- **웹훅 → 결제 확정**: `Transaction.Paid` 웹훅이 오면 앱의 리스너
  (`workbench/app/Listeners/QueuePaymentCompletion.php`)가 큐 잡으로 `completePayment()`를 불러
  결제 대기·만료 건을 `PAID`로 만듭니다. PHPUnit 실행에서는 등록되지 않습니다(live 웹훅 테스트가
  "패키지는 웹훅 처리 중 레코드를 바꾸지 않는다"를 그대로 검증하도록).
- `composer require` 등으로 `composer.json`이 바뀌면 post-autoload-dump 훅(`package:purge-skeleton`)이
  스켈레톤의 SQLite 파일을 지웁니다. 그 뒤에는 `composer build`(또는 `composer serve`)로 다시 만드세요.
- 이 서버는 live 테스트(§2)와 같은 루트 `.env`의 `PORTONE_*`를 읽습니다. PHPUnit 실행(`APP_ENV=testing`)에서는
  읽지 않습니다.
- 워크벤치가 꼬이면 `composer build`로 다시 빌드하세요.

---

## 2. Live / E2E 테스트

live 테스트는 `tests/E2E/`에 있고 **Orchestra Testbench Dusk**로 돕니다. Testbench Dusk가
워크벤치 앱을 실제 HTTP 서버로 띄우고 Chrome을 조작하므로, 발행된 스텁이 세션·CSRF·
인증 미들웨어가 붙은 실제 Laravel 앱 위에서 검증됩니다.

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

시크릿은 절대 커밋·로그·픽스처에 남기지 마세요.

**의존성** — 브라우저가 SDK를 `node_modules/@portone/browser-sdk`에서 받으므로 `npm ci`가
선행되어야 합니다.

**ChromeDriver** — 설치된 Chrome 버전에 맞는 드라이버를 한 번 받습니다(Laravel Dusk의
`dusk:chrome-driver --detect`).

```bash
composer dusk:chrome-driver
```

### 2.2 실행

```bash
composer live                                        # 전체
composer live -- --filter PortOneCheckoutFlowTest    # 하나만
```

- `composer live`는 `DUSK_HEADLESS_DISABLED=1`을 설정해 **Chrome 창을 띄웁니다**. 사람이
  PG 결제창을 조작해야 하기 때문입니다.
- 워크벤치 앱은 기본 `http://127.0.0.1:8001`에서 서빙됩니다. 바꾸려면 `DUSK_SERVE_URL`
  또는 `DUSK_SERVE_HOST`/`DUSK_SERVE_PORT`를 설정하세요.
- 각 테스트는 스텁을 `vendor:publish --tag=portone-stubs`로 워크벤치 앱에 발행하고,
  sqlite DB를 새로 마이그레이션한 뒤, Dusk `loginAs()`로 워크벤치 `User`로 로그인합니다.
- 패키지는 번들러를 두지 않으므로, 테스트가 발행된 `portone-checkout.js`를 그대로
  `public/build/`에 두고 Vite manifest를 써 줍니다. `@portone/browser-sdk/v2` import는
  워크벤치 미들웨어 `MapBrowserSdkImport`가 붙이는 import map으로 해석됩니다.

### 2.3 테스트 목록

| 테스트                           | 결제창 조작                         | 추가 요구사항                                                                     |
| -------------------------------- | ----------------------------------- | --------------------------------------------------------------------------------- |
| `PortOneCheckoutCompletionTest`  | 10,000원 결제 1회 완료              | 조회 probe 관측(`tools/probes/portone-payment-lookup-observation.json`)           |
| `PortOneCheckoutFlowTest`        | 10,000원 결제 1회 완료, 1회 창 닫기 | —                                                                                 |
| `PortOnePaymentCancellationTest` | 100원 결제 2회 완료                 | —                                                                                 |
| `PortOnePaymentCompletionTest`   | 100원 결제 1회 완료                 | 조회 probe 관측                                                                   |
| `PortOneAmountMismatchTest`      | 100원 결제 2회 완료                 | — (결제 뒤 레코드 금액을 1000원으로 바꿔 불일치를 만들고, 자동 취소를 확인)       |
| `PortOnePaymentCommandTest`      | 100원 결제 1회 완료                 | — (`portone:payment` 출력을 확인하고, 가린 응답을 기록한 뒤 취소)                 |
| `PortOneWebhookEventsTest`       | 1,000원 결제 1회 완료, 1회 창 닫기  | `PORTONE_WEBHOOK_PUBLIC_URL`, `PORTONE_WEBHOOK_SECRET`, ngrok (§2.5)              |
| `PortOnePaymentRefundTest`       | 100원 1회 + 10,000원 2회 결제 완료  | 취소 테스트 관측(이미 취소된 결제 ID, 재사용 가능)                                |
| `PortOnePaymentExpiryTest`       | 100원 결제 1회 완료, 1회 창 닫기    | 버려진 결제 probe 관측(`tools/probes/portone-abandoned-payment-observation.json`) |
| `PortOneWebhookTest`             | 100원 결제 1회 완료                 | `PORTONE_WEBHOOK_PUBLIC_URL`, `PORTONE_WEBHOOK_SECRET`, ngrok (§2.5)              |
| `PortOneCancellationSyncTest`    | 1,000원 결제 1회 완료               | ngrok (§2.5), 포트원 콘솔에서 300원 부분 취소 → 나머지 700원 취소 (안내에 따라)   |
| `PortOneWebhookReplayTest`       | 없음                                | 취소 테스트 관측(이미 취소된 결제 ID, 재사용 가능), `PORTONE_WEBHOOK_SECRET`      |

- `PortOneWebhookTest`·`PortOneWebhookEventsTest`·`PortOneCancellationSyncTest`는 `PORTONE_WEBHOOK_PUBLIC_URL`이 없으면 **스킵**됩니다.
- 각 테스트는 **단독으로 실행**할 수 있습니다. 소모하는 결제는 테스트가 직접 만들고,
  다른 테스트가 먼저 돌 필요가 없습니다. 미결제·취소 상태로 고정된 결제 ID처럼 몇 번이든
  다시 쓸 수 있는 기록만 재사용합니다.
- 결제 페이지는 두 가지입니다. 체크아웃 테스트는 **발행된 스텁 페이지**
  (`/portone/checkout`)를, 나머지는 패키지가 준비한 임의 결제를 띄우는 워크벤치 전용
  페이지(`/workbench/pay/{paymentId}`)를 씁니다.

### 2.4 사람이 하는 일

테스트가 로그인·버튼 클릭까지 진행한 뒤 STDOUT에 안내를 찍고 대기합니다(타임아웃 900초).

```text
>>> Complete the 10,000 KRW PortOne test payment in the browser window.
```

안내에 따라 Chrome 창의 포트원 테스트 결제창에서 **결제 완료** 또는 **창만 닫기**를 하면(`PortOneCancellationSyncTest`는
포트원 콘솔에서 안내된 결제 ID를 **부분 취소·전액 취소**),
테스트가 화면 상태와 DB·포트원 조회 결과를 검증하고 다음으로 넘어갑니다. 실패하면
스크린샷과 콘솔 로그가 `tests/E2E/debug/`에 남습니다(gitignore됨).

### 2.5 웹훅 live 테스트 (ngrok)

웹훅은 포트원이 **공개 URL로 콜백**해야 하므로, Dusk가 서빙하는 포트로 터널을 엽니다.

```bash
ngrok http 8001
```

발급된 https URL에 웹훅 경로(`PORTONE_WEBHOOK_PATH`, 기본 `portone/webhook`)를 붙여
포트원 콘솔의 웹훅 URL로 등록하고, 같은 값을 넘겨 실행합니다.

```bash
PORTONE_WEBHOOK_PUBLIC_URL=https://<ngrok-host>/portone/webhook composer live -- --filter PortOneWebhookTest
```

브라우저는 결제를 일으키는 데만 쓰이고, 웹훅 수신은 워크벤치의 `portone.webhook`
라우트에서 확인합니다.

---

## 3. 독립 관측 probe (T-002 / T-003)

패키지 코드와 무관하게 **외부 경계 동작 자체를 관측**하는 독립 스크립트입니다.
결과는 `tools/probes/`의 probe 관측 JSON 두 개에 기록되고, 일부 live 테스트가 이를
입력으로 읽습니다.

### 3.0 왜 probe가 필요한가

이 패키지의 단위 테스트는 포트원 호출을 테스트 더블로 대체합니다(§1.1). 그런데 목의
값(성공·미완료 결과 코드, 결제 상태, 에러 응답 등)을 **추측해서 쓰면** 목이 실제 포트원과
다르게 동작해도 테스트는 계속 통과합니다. 그래서 목 값은 실제로 관측한 기록에서만
가져옵니다(`AGENTS.md` MOCK_RULES: "Mock only observed behavior"). probe는 그 관측을
만드는 도구입니다.

- **브라우저 SDK probe (T-002)**: PG 결제창이 열리는지, 결제 완료/창 닫기 때 SDK가 어떤
  결과(에러 코드 유무)를 돌려주는지 기록합니다.
- **결제 조회 probe (T-003)**: 결제된/결제되지 않은/모르는 결제 ID와 잘못된 시크릿에 대해
  조회 API가 어떻게 응답하는지 기록합니다.
- **버려진 결제 probe (T-021)**: 브라우저 SDK probe 페이지의 "Abandoned payments" 버튼 두 개로,
  결제창을 열어 둔 동안(READY)과 닫은 뒤(FAILED), 같은 paymentId로 다시 결제했을 때(PAID)의
  상태를 패키지 없이 조회 API로 직접 기록합니다. 조회 결과는
  `tools/probes/portone-abandoned-payment-log.jsonl`(gitignore)에 쌓이고, 정리한 결과가
  `portone-abandoned-payment-observation.json`입니다.

### 3.0.1 왜 워크벤치(live 테스트) 안에 넣지 않는가

probe는 `tests/`나 워크벤치 안이 아니라 `tools/probes/`에 둡니다. 임의 선택이 아니라
`.replworks/TECH_STACK.md` §4에 명시된 규칙입니다. probe의 목적이 패키지 자체 코드와
독립적으로 외부 동작을 관측하는 것이므로 `tests/` 밖에 있어야 합니다.

1. **관측 대상과 관측 도구가 같으면 안 됩니다.** 워크벤치 안에서 돌리면 패키지의
   서비스 프로바이더·컨트롤러·체크아웃 스텁·`MapBrowserSdkImport` 미들웨어를 거칩니다.
   그 경로에 버그나 보정 로직이 있으면 결과가 포트원의 실제 동작인지 패키지의 동작인지
   구분할 수 없고, 그 결과로 만든 목은 패키지의 가정을 되풀이할 뿐입니다(순환 검증).
   probe는 패키지 코드를 거치지 않고 SDK·API를 직접 호출해 포트원의 동작을 있는 그대로
   봅니다. 결제 조회 probe도 패키지가 아니라 포트원 클라이언트(`cable8mm/portone-php`)만
   사용합니다.
2. **서버 구동 방식이 다릅니다.** 브라우저 SDK probe는 PHP 내장 서버의 라우터
   스크립트로 동작합니다(`php -S ... portone-browser-sdk-probe.php`). TECH_STACK은 live
   테스트에서 라우터 스크립트 사용을 금지하고 probe만 문서화된 예외로 허용하므로,
   Testbench Dusk가 띄우는 워크벤치 서버 위에서는 이 방식을 쓸 수 없습니다.
3. **실행 시점이 다릅니다.** probe는 CI는 물론 `composer live`에도 포함되지 않고,
   필요할 때(§3.1) 사람이 직접 돌려 관측 파일을 갱신합니다. live 테스트는 그 관측 파일을
   입력으로 읽습니다. probe 디렉터리는 배포 아카이브에서 `export-ignore`됩니다.

정리하면, 워크벤치 안의 live 테스트는 "패키지가 포트원과 **올바르게 연동하는지**"를
검증하고, probe는 "포트원이 **실제로 어떻게 동작하는지**"를 기록합니다. 후자가 전자의
기준(목 값)이 되므로 둘을 한 곳에 합치지 않습니다.

**브라우저 SDK probe (T-002)** — PG 창이 열리는지·완료/미완료 결과 코드를 관측:

```bash
php -S 127.0.0.1:8765 tools/probes/portone-browser-sdk-probe.php
# 브라우저에서 열고: 한 번은 결제 완료, 한 번은 창만 닫기
```

**결제 조회 probe (T-003)** — 위 관측의 paymentId로 상태·금액을 조회:

```bash
php tools/probes/portone-payment-lookup-probe.php
```

> 관측 파일은 의도된 출력입니다(커밋 대상). 다만 **시크릿 값이 들어가지 않도록** 주의하세요.

### 3.1 재검증 시점

단위 테스트의 목 값은 probe 관측과 live 테스트 관측(`tests/E2E/*-observation.json`)에서
옵니다. 외부 동작이 바뀌었을 수 있는 아래 경우에는 다시 관측하세요.

| 계기                                                  | 다시 돌릴 것                                   |
| ----------------------------------------------------- | ---------------------------------------------- |
| `cable8mm/portone-php` 버전 변경                      | `composer live` 전체 + 조회 probe              |
| `@portone/browser-sdk` 버전 변경, 테스트 채널·PG 변경 | 브라우저 SDK probe + `PortOneCheckoutFlowTest` |
| 포트원 웹훅 설정·시크릿 변경                          | `PortOneWebhookTest`                           |
| 릴리스 태그 직전                                      | `composer live` 전체                           |

---

## 4. 문제 해결

| 증상                                | 원인 / 해결                                                         |
| ----------------------------------- | ------------------------------------------------------------------- |
| `Invalid path to Chromedriver`      | `composer dusk:chrome-driver` 미실행                                |
| `Unable to start server` (8001)     | 포트 사용 중 — 이전 실행의 서버를 종료하거나 `DUSK_SERVE_PORT` 변경 |
| Chrome 창이 안 뜸                   | `CI` 환경변수가 설정됨(Testbench Dusk가 헤드리스로 전환)            |
| SDK import 실패                     | `npm ci` 미실행                                                     |
| 웹훅 테스트가 스킵됨                | `PORTONE_WEBHOOK_PUBLIC_URL` 미설정                                 |
| 웹훅 미도착                         | ngrok 미실행, 포트 불일치, 또는 콘솔 웹훅 URL 불일치                |
| 타임아웃(900초)                     | 브라우저에서 결제를 끝내지 않음                                     |
| 워크벤치 라우트/프로바이더가 이상함 | `composer build`로 워크벤치 재빌드                                  |
