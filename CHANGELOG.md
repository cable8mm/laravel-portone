# Release Notes

## v0.7.0 - 2026-11-11

v0.7.0은 웹훅 기록과 중복 처리 방지, 상태 변경마다 한 번만 발송되는 결제 이벤트, 한 번만 결제되는 모델(`allowsOnlyOnePaidPayment()`)의 중복 결제 자동 취소, PortOne 영수증 URL(`receipt_url`) 저장, 빌링키 발급·확인·삭제와 빌링키 결제(`chargeBillingKey()`)를 추가합니다. 테스트용 `FakePaymentGateway`에서 영수증 URL을 지정할 수 있습니다. 구독과 빌링키·React 스텁은 이 버전에 포함되지 않습니다.

### 호환성이 깨지는 변경

README "업그레이드 > v0.6.x에서 v0.7.0으로"를 참고하세요.

- `cable8mm/portone-php` `^0.7.0` 필요
- 마이그레이션 4개 필수: `create_portone_webhooks_table`, `add_receipt_url_to_portone_payments_table`, `create_portone_billing_keys_table`, `add_billing_key_to_portone_payments_table` (`vendor:publish --tag=portone-migrations` 후 `migrate`)
- 이벤트 삭제: `PaymentPaid`→`PaymentCompleted`, `PaymentCancelled`→`PaymentRefunded`, `PaymentPartiallyCancelled`→`PaymentPartiallyRefunded`, `PaymentFailed`→없음(`WebhookReceived`의 `Transaction.Failed`), `PaymentPaidOnExpiry`→`PaymentCompleted`(`source`=`expiry`), `PaymentAmountMismatchedOnExpiry`→`PaymentAmountMismatched`(`source`=`expiry`), `PaymentWebhookEvent`·`PaymentExpiryEvent`→`PaymentEvent`
- `PaymentExpired` 생성자가 `(payment, source)`로 변경, `source` 속성 추가
- `Transaction.Paid` 웹훅이 결제를 자동 확정: 리스너의 `completeByPaymentId()`·`completePayment()` 호출 제거
- `WebhookReceived`는 `webhook-id`당 한 번만 발송, 처리 실패 시 `WebhookFailed`
- `Billable`에 새 메서드(`allowsOnlyOnePaidPayment`, `prepareBillingKey`, `completeBillingKey`, `billingKeys`, `deleteBillingKey`, `chargeBillingKey`): 같은 이름의 모델 메서드는 이름 변경 필요

### What's Changed

* test: drop an assertion PHPStan 2.3.5 proves always true by [@replworks-bot](https://github.com/replworks-bot) in https://github.com/cable8mm/laravel-portone/pull/50
* docs(spec): plan v0.7.0 webhook log, payment events, billing keys, and subscriptions by [@replworks-bot](https://github.com/replworks-bot) in https://github.com/cable8mm/laravel-portone/pull/51
* build: require cable8mm/portone-php ^0.7.0 by [@replworks-bot](https://github.com/replworks-bot) in https://github.com/cable8mm/laravel-portone/pull/52
* docs(architecture): align webhook and duplicate rules with the v0.7.0 spec by [@replworks-bot](https://github.com/replworks-bot) in https://github.com/cable8mm/laravel-portone/pull/53
* feat: record webhooks and process each webhook-id once by [@replworks-bot](https://github.com/replworks-bot) in https://github.com/cable8mm/laravel-portone/pull/54
* docs(tasks): defer easy payments to v0.8.0 by [@replworks-bot](https://github.com/replworks-bot) in https://github.com/cable8mm/laravel-portone/pull/55
* feat!: dispatch one payment event per status change by [@replworks-bot](https://github.com/replworks-bot) in https://github.com/cable8mm/laravel-portone/pull/56
* chore(deps): bump [@vitest](https://github.com/vitest)/mocker and vitest by [@dependabot](https://github.com/dependabot)[bot] in https://github.com/cable8mm/laravel-portone/pull/48
* feat: cancel the second paid payment of a single-payment billable by [@replworks-bot](https://github.com/replworks-bot) in https://github.com/cable8mm/laravel-portone/pull/57
* feat: store the PortOne receipt URL on payment records by [@replworks-bot](https://github.com/replworks-bot) in https://github.com/cable8mm/laravel-portone/pull/58
* feat(testing): set the receipt URL on the fake gateway by [@replworks-bot](https://github.com/replworks-bot) in https://github.com/cable8mm/laravel-portone/pull/59
* test: observe billing-key issuance, charge, and deletion (T-045) by [@replworks-bot](https://github.com/replworks-bot) in https://github.com/cable8mm/laravel-portone/pull/60
* feat: billing keys and billing-key charges by [@replworks-bot](https://github.com/replworks-bot) in https://github.com/cable8mm/laravel-portone/pull/61
* docs(tasks): release v0.7.0 without subscriptions and stubs by [@replworks-bot](https://github.com/replworks-bot) in https://github.com/cable8mm/laravel-portone/pull/62
* docs: document v0.7.0 features and upgrade from v0.6.x by [@replworks-bot](https://github.com/replworks-bot) in https://github.com/cable8mm/laravel-portone/pull/63

### New Contributors

* [@dependabot](https://github.com/dependabot)[bot] made their first contribution in https://github.com/cable8mm/laravel-portone/pull/48

**Full Changelog**: https://github.com/cable8mm/laravel-portone/compare/v0.6.0...v0.7.0

## v0.6.0 - 2026-10-16

### What's Changed

* feat: single redirect checkout flow for mobile PG windows by [@replworks-bot](https://github.com/replworks-bot) in https://github.com/cable8mm/laravel-portone/pull/46
* docs: changing force-redirect flow by [@cable8mm](https://github.com/cable8mm) in https://github.com/cable8mm/laravel-portone/pull/47

**Full Changelog**: https://github.com/cable8mm/laravel-portone/compare/v0.5.0...v0.6.0

## v0.5.0 - 2026-10-15

### What's Changed

* feat!: sync cancellations from PortOne, add expiry events and testing helpers by [@replworks-bot](https://github.com/replworks-bot) in https://github.com/cable8mm/laravel-portone/pull/45

**Full Changelog**: https://github.com/cable8mm/laravel-portone/compare/v0.4.0...v0.5.0

## v0.4.0 - 2026-10-15

### What's Changed

* feat!: add webhook payment events, completion by payment ID, and upgrade-safe migrations by [@cable8mm](https://github.com/cable8mm) in https://github.com/cable8mm/laravel-portone/pull/44

**Full Changelog**: https://github.com/cable8mm/laravel-portone/compare/v0.3.0...v0.4.0

## v0.3.0 - 2026-10-15

### What's Changed

* feat!: cancel a payment automatically when the paid amount differs by [@cable8mm](https://github.com/cable8mm) in https://github.com/cable8mm/laravel-portone/pull/42
* chore: remove the hand-written v0.3.0 CHANGELOG entry by [@cable8mm](https://github.com/cable8mm) in https://github.com/cable8mm/laravel-portone/pull/43

**Full Changelog**: https://github.com/cable8mm/laravel-portone/compare/v0.2.1...v0.3.0

## v0.2.1 - 2026-10-14

### What's Changed

* feat(stubs)!: send the payer to an order completion page after paying by [@replworks-bot](https://github.com/replworks-bot) in https://github.com/cable8mm/laravel-portone/pull/40
* docs(readme): add what to check before using the package in production by [@replworks-bot](https://github.com/replworks-bot) in https://github.com/cable8mm/laravel-portone/pull/41

**Full Changelog**: https://github.com/cable8mm/laravel-portone/compare/v0.2.0...v0.2.1

## v0.2.0 - 2026-10-14

### What's Changed

* chore: enhance detailed documentation by [@cable8mm](https://github.com/cable8mm) in https://github.com/cable8mm/laravel-portone/pull/19
* refactor: migrate live browser checkout tests to Ochestra Testbench Dusk by [@cable8mm](https://github.com/cable8mm) in https://github.com/cable8mm/laravel-portone/pull/20
* refactor: relocate live verification probes out of `tests/` by [@cable8mm](https://github.com/cable8mm) in https://github.com/cable8mm/laravel-portone/pull/21
* refactor: update task statuses and enhance testing documentation by [@cable8mm](https://github.com/cable8mm) in https://github.com/cable8mm/laravel-portone/pull/22
* fix: re-verify the browser SDK probe and make it runnable as a router by [@cable8mm](https://github.com/cable8mm) in https://github.com/cable8mm/laravel-portone/pull/23
* Add Claude Code GitHub Workflow by [@cable8mm](https://github.com/cable8mm) in https://github.com/cable8mm/laravel-portone/pull/24
* ci(claude): let Claude open pull requests for issues by [@cable8mm](https://github.com/cable8mm) in https://github.com/cable8mm/laravel-portone/pull/25
* ci(claude): request maintainer review and hand off live tests by [@cable8mm](https://github.com/cable8mm) in https://github.com/cable8mm/laravel-portone/pull/26
* ci: skip Claude and Pages workflows on forks by [@replworks-bot](https://github.com/replworks-bot) in https://github.com/cable8mm/laravel-portone/pull/28
* ci(claude): review pull requests opened by the Claude app by [@replworks-bot](https://github.com/replworks-bot) in https://github.com/cable8mm/laravel-portone/pull/31
* docs: explain why independent probes exist and live outside workbench by [@claude](https://github.com/claude)[bot] in https://github.com/cable8mm/laravel-portone/pull/30
* ci(claude): assign pull requests to replworks-bot by [@replworks-bot](https://github.com/replworks-bot) in https://github.com/cable8mm/laravel-portone/pull/32
* ci(claude): remove the automatic pull request review by [@replworks-bot](https://github.com/replworks-bot) in https://github.com/cable8mm/laravel-portone/pull/33
* ci(claude): let Claude run the offline checks and re-request review by [@replworks-bot](https://github.com/replworks-bot) in https://github.com/cable8mm/laravel-portone/pull/35
* feat(stubs)!: look up the product in the checkout stubs and add a Workbench sample shop by [@replworks-bot](https://github.com/replworks-bot) in https://github.com/cable8mm/laravel-portone/pull/34
* docs: specify pending payment expiry and record the abandoned payment lookup by [@replworks-bot](https://github.com/replworks-bot) in https://github.com/cable8mm/laravel-portone/pull/36
* feat: expire and prune abandoned pending payments by [@replworks-bot](https://github.com/replworks-bot) in https://github.com/cable8mm/laravel-portone/pull/37
* feat(workbench): run the expiry check and webhook completion through a queue by [@replworks-bot](https://github.com/replworks-bot) in https://github.com/cable8mm/laravel-portone/pull/38
* docs(readme): fix the Vite entry, exceptions and webhook guidance by [@replworks-bot](https://github.com/replworks-bot) in https://github.com/cable8mm/laravel-portone/pull/39

### New Contributors

* [@replworks-bot](https://github.com/replworks-bot) made their first contribution in https://github.com/cable8mm/laravel-portone/pull/28
* [@claude](https://github.com/claude)[bot] made their first contribution in https://github.com/cable8mm/laravel-portone/pull/30

**Full Changelog**: https://github.com/cable8mm/laravel-portone/compare/v0.1.0...v0.2.0

## v0.1.0 - 2026-10-13

### What's Changed

* feat: initialize Laravel Portone package with configuration, service provider, and testsdata. by [@sington2014-commits](https://github.com/sington2014-commits) in https://github.com/cable8mm/laravel-portone/pull/1
* test: verify PortOne browser SDK payment-window behavior by [@sington2014-commits](https://github.com/sington2014-commits) in https://github.com/cable8mm/laravel-portone/pull/2
* test: verify PortOne payment lookup behavior by [@sington2014-commits](https://github.com/sington2014-commits) in https://github.com/cable8mm/laravel-portone/pull/3
* docs: update AGENTS.md to clarify task renumbering rules for AI by [@sington2014-commits](https://github.com/sington2014-commits) in https://github.com/cable8mm/laravel-portone/pull/4
* test: Publish and read configuration by [@sington2014-commits](https://github.com/sington2014-commits) in https://github.com/cable8mm/laravel-portone/pull/5
* feat: implement customer registry and billable model integration with migration and tests by [@sington2014-commits](https://github.com/sington2014-commits) in https://github.com/cable8mm/laravel-portone/pull/6
* feat: implement payment preparation functionality with migration and tests by [@sington2014-commits](https://github.com/sington2014-commits) in https://github.com/cable8mm/laravel-portone/pull/7
* feat: add payment history functionality with tests by [@sington2014-commits](https://github.com/sington2014-commits) in https://github.com/cable8mm/laravel-portone/pull/8
* feat: implement PortOne checkout functionality with stubs, routes, and tests by [@sington2014-commits](https://github.com/sington2014-commits) in https://github.com/cable8mm/laravel-portone/pull/9
* feat: implement checkout preparation action with controller and tests by [@sington2014-commits](https://github.com/sington2014-commits) in https://github.com/cable8mm/laravel-portone/pull/10
* test: Verify PortOne payment cancellation behavior by [@sington2014-commits](https://github.com/sington2014-commits) in https://github.com/cable8mm/laravel-portone/pull/11
* feat: Implement payment completion functionality with PortOne integration by [@sington2014-commits](https://github.com/sington2014-commits) in https://github.com/cable8mm/laravel-portone/pull/12
* feat: Implement refund functionality in PaymentOperations and PortOneGateway by [@sington2014-commits](https://github.com/sington2014-commits) in https://github.com/cable8mm/laravel-portone/pull/13
* feat: Implement PortOne webhook handling with controller, verifier, and tests by [@sington2014-commits](https://github.com/sington2014-commits) in https://github.com/cable8mm/laravel-portone/pull/14
* feat: Implement checkout completion functionality with live tests and observations by [@sington2014-commits](https://github.com/sington2014-commits) in https://github.com/cable8mm/laravel-portone/pull/15
* test: brewser checkout flow against the live PG payment window by [@sington2014-commits](https://github.com/sington2014-commits) in https://github.com/cable8mm/laravel-portone/pull/16
* docs: Add README.md for Laravel PortOne package documentation by [@cable8mm](https://github.com/cable8mm) in https://github.com/cable8mm/laravel-portone/pull/17
* chore: Add GitHub Actions workflows for API documentation and changelog updates, and update README with badges by [@cable8mm](https://github.com/cable8mm) in https://github.com/cable8mm/laravel-portone/pull/18

### New Contributors

* [@sington2014-commits](https://github.com/sington2014-commits) made their first contribution in https://github.com/cable8mm/laravel-portone/pull/1
* [@cable8mm](https://github.com/cable8mm) made their first contribution in https://github.com/cable8mm/laravel-portone/pull/17

**Full Changelog**: https://github.com/cable8mm/laravel-portone/commits/v0.1.0
