# Conventions

개발·기여 시 지켜야 할 규칙과 명령 모음입니다. 사용자 대상 문서는 루트
[`README.md`](../README.md)를, 실제 테스트 실행 방법은 [`TESTING.md`](./TESTING.md)를
보세요.

## 명령 (Composer / npm)

| 명령                    | 하는 일                                                      |
| ----------------------- | ------------------------------------------------------------ |
| `composer install`      | PHP 의존성 설치 후 테스트벤치 스켈레톤 정리·디스커버리 실행  |
| `composer format:check` | Pint 포맷 검사 (변경 없이 확인만)                            |
| `composer analyse`      | PHPStan(Larastan) 레벨 6 정적 분석, **에러 0이어야 통과**    |
| `composer test`         | PHPUnit 단위 테스트 실행                                     |
| `composer lint`         | Pint 자동 수정 + PHPStan 상세 분석 (로컬 점검용)             |
| `composer build`        | 테스트벤치 워크벤치 빌드                                     |
| `composer serve`        | 워크벤치 빌드 후 테스트벤치 개발 서버 실행                   |
| `npm ci`                | 프론트엔드 도구·브라우저 SDK 설치 (`package-lock.json` 기준) |
| `npm run check`         | Prettier 포맷 검사 + ESLint                                  |
| `npm run format`        | Prettier 자동 포맷                                           |
| `npm test`              | Vitest(jsdom) 자바스크립트 테스트                            |

`composer test`는 **live 테스트(`tests/E2E/`)를 제외합니다**. 실제 포트원과
연동하는 e2e 실행(`composer live`)은 [`TESTING.md`](./TESTING.md)를 참고하세요.

## CI

`.github/workflows/ci.yml`이 `develop`/`main` 푸시와 PR에서 아래를 **전부** 실행합니다.
로컬에서 커밋하기 전에 같은 명령을 돌리면 CI와 동일하게 검증할 수 있습니다.

1. `composer validate --strict`
2. `composer update` (매트릭스 버전 고정)
3. `composer audit`
4. `composer format:check`
5. `composer analyse`
6. `composer test`
7. `npm ci` → `npm run check` → `npm test`

### 버전 매트릭스

- PHP `8.2`, `8.3`, `8.4`, `8.5` × Laravel `12.0`
- PHP `8.3`, `8.4`, `8.5` × Laravel `13.0`

> **live/e2e 테스트는 CI에서 절대 실행되지 않습니다.** 네트워크 호출과 사람의
> 브라우저 조작이 필요하기 때문입니다(`.replworks/TECH_STACK.md`).

## PR 체크리스트

커밋·PR 전에 확인하세요.

- [ ] `composer analyse` → **에러 0**
- [ ] `composer format:check` 통과
- [ ] `composer test` 통과
- [ ] `npm run check`, `npm test` 통과 (JS/뷰/스텁을 만졌다면 필수)
- [ ] 새 기능·동작 변경이면 대응하는 **단위 테스트** 추가/수정
- [ ] 외부 경계(포트원 API·브라우저 SDK·웹훅)를 만졌다면 대응하는 **live 관측** 기록
- [ ] `.env`·시크릿·`composer.lock` 커밋 금지 (`.gitignore`으로 제외됨)
- [ ] 커밋은 Conventional Commits (`feat:`, `fix:`, `test:`, `docs:`, `chore:` …)

## 코딩 규칙 (요약)

근거는 `.replworks/TECH_STACK.md`입니다.

- PHP 파일은 `declare(strict_types=1);`로 시작.
- 포맷은 **Laravel Pint (laravel preset)** 강제.
- 정적 분석은 **Larastan 레벨 6, 에러 0**.
- 포트원 API 호출은 **모두 `cable8mm/portone-php` 클라이언트**를 통해서만.
- 설정 값은 `config('portone.*')`로 읽고, `env()`는 `config/portone.php` 안에서만 호출.
- 영속성은 Eloquent 모델 + 마이그레이션.
- 시크릿은 환경 변수로만 주입. 코드·로그·예외 메시지·테스트 픽스처에 하드코딩 금지.
- PHP 테스트는 PHPUnit + Orchestra Testbench. 클래스명은 `*Test`.
- JS 테스트는 Vitest + jsdom. 파일명은 `*.test.js`. 브라우저 SDK는 목으로 대체.
- PHP 테스트는 **실제 네트워크 요청을 보내지 않음** — 포트원 호출은 테스트 더블로 대체.

## 저장소 구조

```text
src/            패키지 소스 (Concerns, Exceptions, Models, …)
config/         발행되는 설정 (portone.php)
database/       마이그레이션
stubs/          발행용 체크아웃 파일 (뷰·JS·컨트롤러·라우트)
tests/Unit/     오프라인 단위 테스트
tests/E2E/      live/e2e 테스트(Testbench Dusk) + 관측 기록 (CI 미실행)
tests/live/     독립 관측 probe + probe 관측 기록
docs/           개발자 문서 (이 파일, TESTING.md)
workbench/      테스트벤치 워크벤치
```

## 라이선스

MIT
