# TECH_STACK

## 1. Languages / runtimes / frameworks / libraries

- PHP: ^8.2
- Laravel components (illuminate/\*): ^12.0 || ^13.0
- The Package MUST NOT require laravel/framework.
- Eloquent ORM and Laravel migrations: as provided by the illuminate/\* version in use.
- illuminate/console and illuminate/queue: ^12.0 || ^13.0; required for the expiry command (EL-031) and the expiry job (EL-032).
- cable8mm/portone-php: ^0.4
- Composer: PSR-4 autoload, Laravel package discovery through composer.json extra.laravel.providers
- orchestra/testbench: ^10.0 || ^11.0, including Workbench
- PHPUnit: ^11.5 || ^12
- Laravel Pint: ^1.0
- PHPStan: ^2.0
- Larastan: ^3.0
- Node.js: ^22.12 || >=24
- npm: used for JavaScript development tooling only
- @portone/browser-sdk: ^0.1.13; the Developer installs it in the Application; the Package MUST NOT install it in the Application
- Vitest: ^4.0
- Vite: ^8.0; development-only, used only by workbench/
- laravel-vite-plugin: ^3.0; development-only, used only by workbench/
- Tailwind CSS: ^4.0 with @tailwindcss/vite; development-only, used only by workbench/
- jsdom: ^27.0
- Prettier: ^3.0
- ESLint: ^10.0
- GitHub Actions: CI
- Packagist: distribution
- ngrok: development-only public URL for live webhook verification
- orchestra/testbench-dusk: ^10.0 || ^11.0; development-only; provides laravel/dusk and php-webdriver/webdriver transitively; used only for live browser tests, never run in CI

## 2. Framework- or tool-mandated paths and file names only

- composer.json at the repository root
- composer.lock MUST NOT be committed and MUST be listed in .gitignore
- phpunit.xml.dist at the repository root
- phpunit.dusk.xml at the repository root
- testbench.yaml at the repository root
- workbench/ for Testbench Workbench files
- pint.json at the repository root
- phpstan.neon.dist at the repository root
- package.json and package-lock.json at the repository root; package-lock.json MUST be committed
- vitest.config.js at the repository root
- eslint.config.js at the repository root
- .prettierrc at the repository root
- .github/workflows/ for GitHub Actions workflows
- .gitattributes at the repository root
- .env.example at the repository root
- config/portone.php as the published configuration file name
- LICENSE at the repository root

## 3. Coding conventions specific to the chosen stack

- PHP files MUST declare strict_types=1.
- PHP formatting MUST be produced by Laravel Pint with the laravel preset.
- PHP code MUST pass PHPStan with Larastan at level 6 with zero errors.
- All PortOne API calls MUST go through cable8mm/portone-php.
- Persistence MUST use Eloquent models and migrations.
- The expiry command MUST be an Artisan command registered by the service provider.
- The expiry job MUST implement Illuminate\Contracts\Queue\ShouldQueue and MUST use the Application's default queue connection.
- The prunable definition (EL-035) MUST use Laravel's MassPrunable model trait so that model:prune can select it.
- Configuration values MUST be read through config().
- The stub script MUST be an ES module.
- The stub script MUST import PortOne from @portone/browser-sdk/v2 (verify).
- The published checkout view MUST load the stub script with Laravel Vite; the Application is responsible for building its published resources/js/portone-checkout.js entry.
- JavaScript formatting MUST be produced by Prettier.
- JavaScript linting MUST be produced by ESLint with the flat config format.
- The stub view MUST use Blade only.

## 4. Testing rules

- PHP tests MUST use PHPUnit with an Orchestra Testbench test case.
- PHP test class names MUST end with Test.
- PHP test files MUST be under tests/.
- PHP tests MUST NOT send real network requests.
- PHP tests MUST replace PortOne client calls with test doubles.
- JavaScript tests MUST use Vitest with jsdom.
- JavaScript tests MUST replace @portone/browser-sdk with a mock.
- JavaScript test file names MUST end with .test.js.
- Live verification MUST run through Testbench Workbench using the published stubs.
- Live webhook verification MUST use an ngrok public URL.
- Live verification MUST NOT run in CI.
- Live tests MUST be under tests/E2E/ and MUST be covered only by phpunit.dusk.xml.
- Each live test MUST be runnable on its own: it MUST create any payment it consumes and MUST NOT require another live test to run first. A live test MAY reuse a recorded resource that stays valid across runs.
- composer test MUST exclude tests/E2E/.
- Live browser tests (the Payer opens and completes the PG payment window) MUST use Orchestra Testbench Dusk against the Workbench app and MUST NOT use a PHP built-in server router script.
- Live server-only tests (no payment window) MUST use plain live PHP requests through the Workbench app.
- Live webhook tests MUST assert delivery at the Workbench portone.webhook route through ngrok; a browser is used only to trigger a payment, never to receive the webhook.
- Live browser tests MUST run headed so the human can operate the PG payment window; DUSK_HEADLESS_DISABLED=1 MUST disable headless and MUST be set in the composer live script.
- The Testbench Dusk serve port MUST be known and configurable so the ngrok target matches; it is set through DUSK_SERVE_URL, or DUSK_SERVE_PORT/DUSK_SERVE_HOST (default port 8001).
- Live browser tests MUST authenticate with Dusk loginAs().
- The composer serve app MUST show a developer guide at / without signing anyone in, with a link that signs in the seeded Workbench user and opens the Workbench sample shop.
- The Workbench app served by composer serve MUST read the same local .env file as live tests; PHPUnit runs (APP_ENV=testing) MUST NOT read it.
- Live verification probes are a documented exception to the no-PHP-built-in-server-router rule: probes MUST reside outside tests/ under tools/probes/ because their purpose is to observe external behavior independently of the Package's own code; the no-router rule applies to live tests only.
- Coverage threshold: None.
- Coverage tooling MUST NOT be configured.
- CI MUST run: composer validate, Pint in check mode, PHPStan, PHPUnit, Prettier in check mode, ESLint, Vitest.

## 5. Configuration rules

- Configuration MUST live in config/portone.php and MUST be merged by the service provider.
- Setting names and environment variable names are defined only in PRODUCT_SPEC.md.
- env() MUST be called only inside config/portone.php.
- Secrets MUST be supplied through environment variables.
- Secrets MUST NOT be hardcoded.
- Secrets MUST NOT be committed.
- The Package MUST NOT read .env files directly.
- The development .env MUST be listed in .gitignore.
- .env.example MUST list every environment variable name and MUST NOT contain values.

## 6. Build, packaging, and release rules

- PHP code MUST NOT have a build step.
- Stub JavaScript MUST be published as source and MUST NOT be bundled or compiled by the Package.
- Composer package name: cable8mm/laravel-portone
- composer.json license MUST be MIT.
- composer.json require MUST contain only php, illuminate/\* components, and cable8mm/portone-php.
- composer.json require-dev MUST contain orchestra/testbench, orchestra/testbench-dusk, phpunit/phpunit, laravel/pint, phpstan/phpstan, and larastan/larastan.
- package.json is used only for development and testing of the Package; it MUST list dependencies under devDependencies only, and its dependency list is not otherwise restricted.
- Dev-only files MUST be excluded from the distribution archive with export-ignore in .gitattributes: tests/, workbench/, package.json, package-lock.json, vitest.config.js, eslint.config.js, .prettierrc, phpstan.neon.dist, pint.json, phpunit.xml.dist, testbench.yaml, phpunit.dusk.xml, tools/probes/, .github/.
- Distribution target: public GitHub repository and Packagist.
- Versioning: SemVer.
- Release tags MUST match vMAJOR.MINOR.PATCH.
- The first release tag MUST be v0.1.0.
- Packagist MUST update from GitHub on each pushed tag.
- CI MUST run on every push and every pull request.
- CI matrix MUST cover PHP 8.2, 8.3, 8.4, 8.5 with Laravel 12, and PHP 8.3, 8.4, 8.5 with Laravel 13.

## 7. Security rules tied to the chosen stack

- Webhook verification MUST use the webhook verifier of cable8mm/portone-php.
- Custom webhook signature verification MUST NOT be implemented.
- Webhook verification MUST run on the raw request body before JSON decoding.
- CSRF verification MUST be excluded for the webhook route only.
- CSRF verification MUST NOT be excluded for any other route of the Package.
- API secret and webhook secret MUST NOT be written to logs, exception messages, or test fixtures.
- CI MUST run composer audit.

## 8. Non-goals

- laravel/cashier MUST NOT be a dependency.
- Pest MUST NOT be used.
- Code coverage tools (PCOV, Xdebug coverage) and coverage thresholds MUST NOT be used.
- PortOne V1 SDKs and APIs MUST NOT be used.
- JavaScript bundlers (Vite, webpack) MUST NOT be configured for the Package's distributed code (src/, stubs/, config/). Development and test code (tests/, workbench/) is excluded from this rule; the Workbench sample shop is built with Vite, laravel-vite-plugin, and Tailwind CSS v4 (@tailwindcss/vite) through workbench/vite.config.js.
