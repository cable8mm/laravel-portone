<?php

declare(strict_types=1);

namespace Tests\E2E;

use Cable8mm\LaravelPortone\BillingKeyRegistry;
use Cable8mm\LaravelPortone\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Laravel\Dusk\Browser;
use Symfony\Component\Process\Process;
use Workbench\Database\Factories\UserFactory;

use function Orchestra\Testbench\package_path;
use function Orchestra\Testbench\workbench_path;

/**
 * Live run of the Inertia React stubs (SCR-014, FLOW-008) through the Workbench Inertia app,
 * built with Vite and @vitejs/plugin-react from the pages published into the Testbench skeleton.
 * Neither run needs a webhook: the completed actions look the payment and the billing key up with PortOne (EL-005, EL-073).
 */
class PortOneReactCheckoutFlowTest extends TestCase
{
    private const PUBLISHED_REACT_STUBS = [
        'resources/js/pages/portone/checkout.tsx',
        'resources/js/pages/portone/completed.tsx',
        'resources/js/pages/portone/billing-key.tsx',
        'app/Http/Controllers/PortOneCheckoutController.php',
        'routes/portone.php',
    ];

    private const CHECKOUT = '[data-portone-checkout]';

    private const BUTTON = '#portone-checkout-button';

    private const STATUS = '#portone-checkout-status';

    private const COMPLETED = '[data-portone-completed]';

    private const BILLING_KEY = '[data-portone-billing-key]';

    private const BILLING_KEY_BUTTON = '#portone-billing-key-button';

    private const BILLING_KEY_STATUS = '#portone-billing-key-status';

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), InertiaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        Inertia::setRootView('workbench::inertia');

        // EL-057 billing_channel_key (PORTONE_BILLING_CHANNEL_KEY); when it is unset the live test uses the KCP
        // test channel that issued billing keys in T-045 (PORTONE_E2E_CHANNEL_KEY), as PortOneBillingKeyStubTest does.
        $app['config']->set('portone.billing_channel_key', self::env('PORTONE_BILLING_CHANNEL_KEY') ?? self::env('PORTONE_E2E_CHANNEL_KEY'));
    }

    public function test_the_published_react_pages_complete_a_live_payment(): void
    {
        $user = UserFactory::new()->create();

        $this->browse(function (Browser $browser) use ($user): void {
            $browser->loginAs($user)
                ->visit(route('portone.checkout', absolute: false))
                ->waitFor(self::CHECKOUT.'[data-state="initial"]');
            self::assertSame('Idle', $browser->text(self::STATUS));
            $browser->assertSeeIn(self::CHECKOUT, 'Order A')->assertSeeIn(self::CHECKOUT, '10,000 KRW');

            $browser->click(self::BUTTON)->waitFor(self::CHECKOUT.'[data-state="loading"]');
            self::assertSame('Loading', $browser->text(self::STATUS));
            $browser->assertButtonDisabled(self::BUTTON);

            $this->instruct('Complete the 10,000 KRW PortOne test payment in the browser window (React checkout page).');
            $browser->waitFor(self::COMPLETED.', '.self::CHECKOUT.'[data-state="error"]', self::PAYER_TIMEOUT);

            $payment = Payment::query()->sole();

            // The redirect return renders the published React completed page of the paid payment (FR-153).
            $browser->assertPathIs(route('portone.checkout.completed', ['paymentId' => $payment->payment_id], false));
            self::assertSame('PAID', $browser->attribute(self::COMPLETED, 'data-status'));
            $browser->assertSeeIn(self::COMPLETED, 'Thank you for your order')
                ->assertSeeIn(self::COMPLETED, 'Order A')
                ->assertSeeIn(self::COMPLETED, '10,000 KRW')
                ->assertSeeIn(self::COMPLETED, $payment->payment_id);
            // The page is rendered by Inertia, not by a Blade view.
            self::assertNotNull($browser->element('[data-page]'));

            self::assertSame(10000, $payment->amount);
            self::assertSame('PAID', $payment->status);
            self::assertNotNull($payment->paid_at);

            $this->recordObservation('portone-react-checkout-flow-observation.json', [
                'recordedAt' => gmdate(DATE_ATOM),
                'publishedStubs' => 'portone-stubs-react',
                'scenarios' => ['completedPayment' => [
                    'paymentId' => $payment->payment_id,
                    'totalAmount' => $payment->amount,
                    'paymentStatus' => $payment->status,
                    'completedPage' => 'portone/completed (Inertia)',
                ]],
            ]);
        });
    }

    public function test_the_published_react_billing_key_page_issues_a_live_billing_key(): void
    {
        $user = UserFactory::new()->create();

        $this->browse(function (Browser $browser) use ($user): void {
            $browser->loginAs($user)
                ->visit(route('portone.billing-key', absolute: false))
                ->waitFor(self::BILLING_KEY.'[data-state="initial"]');
            self::assertSame('Idle', $browser->text(self::BILLING_KEY_STATUS));
            $browser->assertButtonEnabled(self::BILLING_KEY_BUTTON);

            $browser->click(self::BILLING_KEY_BUTTON)->waitFor(self::BILLING_KEY.'[data-state="loading"]');
            self::assertSame('Loading', $browser->text(self::BILLING_KEY_STATUS));
            $browser->assertButtonDisabled(self::BILLING_KEY_BUTTON);

            $this->instruct('Register a test card in the KCP window and finish the registration (React billing-key page; no payment is made).');
            $browser->waitFor(
                self::BILLING_KEY.'[data-state="issued"], '.self::BILLING_KEY.'[data-state="failed"], '.self::BILLING_KEY.'[data-state="error"]',
                self::PAYER_TIMEOUT,
            );

            $record = $user->billingKeys()->sole();
            $state = (string) $browser->attribute(self::BILLING_KEY, 'data-state');
            $currentUrl = (string) $browser->driver->getCurrentURL();
            $urlQuery = parse_url($currentUrl, PHP_URL_QUERY);
            self::assertSame('issued', $state, 'The published React page did not reach the Issued state.');

            // The completed action handled the redirect query and rendered the page from the path without it (FR-160).
            $browser->assertPathIs(route('portone.billing-key.completed', ['issueId' => $record->issue_id], false));
            self::assertNull($urlQuery);
            self::assertSame('Issued', $browser->text(self::BILLING_KEY_STATUS));
            self::assertSame($record->issue_id, $browser->attribute(self::BILLING_KEY, 'data-issue-id'));
            // The page is rendered by Inertia, not by a Blade view.
            self::assertNotNull($browser->element('[data-page]'));
            self::assertSame('ISSUED', $record->status);
            self::assertNotNull($record->issued_at);

            // The plain billing key appears in no page, URL, record, log, or recording (FR-130, FR-160).
            $plainKey = $this->app->make(BillingKeyRegistry::class)->plainKey($record);
            self::assertIsString($plainKey);
            self::assertStringNotContainsString($plainKey, (string) $browser->driver->getPageSource());
            self::assertStringNotContainsString($plainKey, $currentUrl);
            self::assertStringNotContainsString($plainKey, (string) json_encode(DB::table('portone_billing_keys')->get()));
            self::assertStringNotContainsString($plainKey, (string) json_encode($record->toArray()));

            foreach (glob($this->app->storagePath('logs/*.log')) ?: [] as $log) {
                self::assertStringNotContainsString($plainKey, (string) file_get_contents($log));
            }

            // Leave no live billing key behind (EL-075).
            $deleted = $user->deleteBillingKey($record->issue_id, 'T-049 React billing-key stub cleanup');
            self::assertSame('DELETED', $deleted->status);

            $observation = [
                'recordedAt' => gmdate(DATE_ATOM),
                'publishedStubs' => 'portone-stubs-react',
                'secretsRecorded' => false,
                'scenarios' => ['issuedBillingKey' => [
                    'channel' => 'NHN KCP test (PORTONE_BILLING_CHANNEL_KEY, or PORTONE_E2E_CHANNEL_KEY when unset)',
                    'issueId' => $record->issue_id,
                    'pageState' => $state,
                    'pageUrlQuery' => $urlQuery,
                    'recordStatus' => $record->status,
                    'recordStatusAfterCleanup' => $deleted->status,
                    'billingKeyPage' => 'portone/billing-key (Inertia)',
                ]],
            ];
            self::assertStringNotContainsString($plainKey, (string) json_encode($observation));
            $this->recordObservation('portone-react-checkout-flow-observation.json', $observation);
        });
    }

    protected function publishCheckoutStubs(): void
    {
        $this->artisan('vendor:publish', [
            '--tag' => 'portone-stubs-react',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertExitCode(0);

        foreach (self::PUBLISHED_REACT_STUBS as $path) {
            $file = $this->app->basePath($path);
            self::assertFileExists($file);
            $this->createdFiles[] = $file;
        }

        if (! Route::has('portone.checkout')) {
            Route::middleware('web')->group(workbench_path('routes/web.php'));
            Route::getRoutes()->refreshNameLookups();
        }
    }

    /**
     * Build the Workbench Inertia app with Vite, as an Application builds its pages.
     */
    protected function buildCheckoutEntry(): void
    {
        $build = new Process(['npm', 'run', 'workbench:build'], package_path(), ['WORKBENCH_APP_PATH' => $this->app->basePath()]);
        $build->setTimeout(300)->mustRun();

        $manifest = $this->app->publicPath('build/manifest.json');
        self::assertFileExists($manifest);
        self::assertStringContainsString('resources/js/inertia.tsx', (string) file_get_contents($manifest));
    }

    private static function env(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
