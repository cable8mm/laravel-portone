<?php

declare(strict_types=1);

namespace Tests\E2E;

use Cable8mm\LaravelPortone\Models\Payment;
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
 */
class PortOneReactCheckoutFlowTest extends TestCase
{
    private const PUBLISHED_REACT_STUBS = [
        'resources/js/pages/portone/checkout.tsx',
        'resources/js/pages/portone/completed.tsx',
        'app/Http/Controllers/PortOneCheckoutController.php',
        'routes/portone.php',
    ];

    private const CHECKOUT = '[data-portone-checkout]';

    private const BUTTON = '#portone-checkout-button';

    private const STATUS = '#portone-checkout-status';

    private const COMPLETED = '[data-portone-completed]';

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), InertiaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        Inertia::setRootView('workbench::inertia');
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
}
