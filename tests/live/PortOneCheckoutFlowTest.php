<?php

declare(strict_types=1);

namespace Tests\Live;

use Cable8mm\LaravelPortone\Concerns\Billable;
use Cable8mm\LaravelPortone\PortOneServiceProvider;
use Dotenv\Dotenv;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Orchestra\Testbench\TestCase;

class PortOneCheckoutFlowTest extends TestCase
{
    /** @var list<string> */
    private array $publishedFiles = [];

    protected function getPackageProviders($app): array
    {
        return [PortOneServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (($_SERVER['PORTONE_CHECKOUT_FLOW_LIVE'] ?? $_ENV['PORTONE_CHECKOUT_FLOW_LIVE'] ?? null) !== '1') {
            $this->markTestSkipped('Set PORTONE_CHECKOUT_FLOW_LIVE=1 to enable the live browser checkout flow test.');
        }

        Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
        $apiSecret = $_SERVER['PORTONE_API_SECRET'] ?? $_ENV['PORTONE_API_SECRET'] ?? '';
        $storeId = $_SERVER['PORTONE_STORE_ID'] ?? $_ENV['PORTONE_STORE_ID'] ?? '';
        $channelKey = $_SERVER['PORTONE_CHANNEL_KEY'] ?? $_ENV['PORTONE_CHANNEL_KEY'] ?? '';

        self::assertNotSame('', $apiSecret, 'PORTONE_API_SECRET must be configured.');
        self::assertNotSame('', $storeId, 'PORTONE_STORE_ID must be configured.');
        self::assertNotSame('', $channelKey, 'PORTONE_CHANNEL_KEY must be configured.');

        config([
            'portone.api_secret' => $apiSecret,
            'portone.store_id' => $storeId,
            'portone.channel_key' => $channelKey,
        ]);

    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ($this->publishedFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function test_clicking_checkout_and_completing_live_payment_reaches_success(): void
    {
        $billable = $this->billable(101);
        $report = $this->runBrowserFlow(
            $billable,
            'Open http://127.0.0.1:8765/checkout and click Start payment. Complete the 10,000 KRW PortOne test payment.',
        );

        self::assertSame('success', $report['state'] ?? null);
        self::assertSame(true, $report['buttonDisabled'] ?? null);
        self::assertSame('Success', $report['statusText'] ?? null);

        $prepare = $this->readJson($this->sessionDirectory().'/prepare-response.json');
        $paymentId = $prepare['body']['paymentId'] ?? null;
        self::assertIsString($paymentId);
        $payment = $billable->findPayment($paymentId);
        self::assertNotNull($payment);
        self::assertSame(10000, $payment->amount);
        self::assertSame('PAID', $payment->fresh()->status);
        self::assertNotNull($payment->fresh()->paid_at);

        $completeRequest = $this->readJson($this->sessionDirectory().'/complete-request.json');
        self::assertSame(['paymentId' => $paymentId], $completeRequest['body'] ?? null);
        $this->recordObservation('completedPayment', [
            'paymentId' => $paymentId,
            'totalAmount' => $payment->amount,
            'browserState' => $report['state'],
            'browserButtonDisabled' => $report['buttonDisabled'],
            'sdkOutcome' => 'returned without an error code',
            'completeRouteStatus' => 200,
            'paymentStatus' => $payment->fresh()->status,
        ]);
    }

    public function test_closing_live_payment_window_returns_to_error_without_completion(): void
    {
        $billable = $this->billable(202);
        $report = $this->runBrowserFlow(
            $billable,
            'Reload http://127.0.0.1:8765/checkout, click Start payment, then close the PortOne payment window without completing.',
        );

        self::assertSame('error', $report['state'] ?? null);
        self::assertSame(false, $report['buttonDisabled'] ?? null);
        self::assertSame('Error', $report['statusText'] ?? null);
        self::assertFileDoesNotExist($this->sessionDirectory().'/complete-request.json');

        $prepare = $this->readJson($this->sessionDirectory().'/prepare-response.json');
        $paymentId = $prepare['body']['paymentId'] ?? null;
        self::assertIsString($paymentId);
        $payment = $billable->findPayment($paymentId);
        self::assertNotNull($payment);
        self::assertSame('PENDING', $payment->fresh()->status);

        $this->recordObservation('cancelledPayment', [
            'paymentId' => $paymentId,
            'totalAmount' => $payment->amount,
            'browserState' => $report['state'],
            'browserButtonDisabled' => $report['buttonDisabled'],
            'sdkOutcome' => 'returned an error code',
            'completeRouteRequested' => false,
            'paymentStatus' => $payment->fresh()->status,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function runBrowserFlow(CheckoutFlowLiveBillableUser $billable, string $instruction): array
    {
        $this->publishStubs();
        $this->includePublishedRoutes();
        $this->migratePackageTables();
        Route::get('/login', static fn () => response('login'))->name('login');
        URL::forceRootUrl('http://127.0.0.1:8765');
        $this->withoutVite();

        $sessionDirectory = $this->sessionDirectory();
        $this->resetBrowserSession($sessionDirectory);
        $this->actingAs($billable);

        $page = $this->get(route('portone.checkout'));
        $page->assertOk()->assertSee('data-state="initial"', false);
        $this->writeFile($sessionDirectory.'/page.html', (string) $page->getContent());
        $scriptPath = $this->app->resourcePath('js/portone-checkout.js');
        self::assertFileExists($scriptPath);
        $this->writeFile($sessionDirectory.'/portone-checkout.js', file_get_contents($scriptPath));

        fwrite(STDOUT, $instruction.PHP_EOL);

        return $this->waitForBrowserFlow($sessionDirectory, 900);
    }

    /**
     * @return array<string, mixed>
     */
    private function waitForBrowserFlow(string $sessionDirectory, int $timeout): array
    {
        $deadline = time() + $timeout;
        $prepareRequestPath = $sessionDirectory.'/prepare-request.json';
        $prepareResponsePath = $sessionDirectory.'/prepare-response.json';
        $completeRequestPath = $sessionDirectory.'/complete-request.json';
        $completeResponsePath = $sessionDirectory.'/complete-response.json';
        $reportPath = $sessionDirectory.'/report.json';

        while (time() < $deadline) {
            if (is_file($prepareRequestPath) && ! is_file($prepareResponsePath)) {
                $response = $this->post(route('portone.checkout.prepare'));
                $response->assertOk();
                $this->writeJson($prepareResponsePath, [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
            }

            if (is_file($completeRequestPath) && ! is_file($completeResponsePath)) {
                $request = $this->readJson($completeRequestPath);
                $paymentId = $request['body']['paymentId'] ?? null;
                self::assertIsString($paymentId);

                $response = $this->post(route('portone.checkout.complete'), ['paymentId' => $paymentId]);
                $this->writeJson($completeResponsePath, [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
            }

            if (is_file($reportPath)) {
                return $this->readJson($reportPath);
            }

            usleep(100_000);
        }

        self::fail('The browser did not report a terminal checkout state within 15 minutes.');
    }

    private function resetBrowserSession(string $sessionDirectory): void
    {
        self::assertDirectoryExists($sessionDirectory);

        foreach ([
            'page.html',
            'portone-checkout.js',
            'prepare-request.json',
            'prepare-response.json',
            'complete-request.json',
            'complete-response.json',
            'report.json',
        ] as $name) {
            $path = $sessionDirectory.'/'.$name;

            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function sessionDirectory(): string
    {
        $directory = $_SERVER['PORTONE_CHECKOUT_FLOW_SESSION_DIR']
            ?? $_ENV['PORTONE_CHECKOUT_FLOW_SESSION_DIR']
            ?? '';
        self::assertNotSame('', $directory, 'PORTONE_CHECKOUT_FLOW_SESSION_DIR must identify a temporary session directory.');

        return $directory;
    }

    private function publishStubs(): void
    {
        $this->artisan('vendor:publish', [
            '--tag' => 'portone-stubs',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertExitCode(0);

        foreach ([
            'resources/views/portone/checkout.blade.php',
            'resources/js/portone-checkout.js',
            'app/Http/Controllers/PortOneCheckoutController.php',
            'routes/portone.php',
        ] as $path) {
            $this->publishedFiles[] = $this->app->basePath($path);
        }
    }

    private function includePublishedRoutes(): void
    {
        require_once $this->app->basePath('app/Http/Controllers/PortOneCheckoutController.php');
        require $this->app->basePath('routes/portone.php');
    }

    private function migratePackageTables(): void
    {
        foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [] as $migration) {
            $this->artisan('migrate', [
                '--path' => $migration,
                '--realpath' => true,
                '--force' => true,
            ])->assertExitCode(0);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $value = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($value);

        return $value;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function writeJson(string $path, array $value): void
    {
        $this->writeFile($path, json_encode($value, JSON_THROW_ON_ERROR));
    }

    private function writeFile(string $path, string $contents): void
    {
        $temporaryPath = $path.'.tmp';

        if (file_put_contents($temporaryPath, $contents, LOCK_EX) === false || ! rename($temporaryPath, $path)) {
            throw new \RuntimeException('Could not write the checkout flow session file.');
        }
    }

    /**
     * @param  array<string, mixed>  $observation
     */
    private function recordObservation(string $scenario, array $observation): void
    {
        $path = __DIR__.'/portone-checkout-flow-observation.json';
        $existing = is_file($path)
            ? json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)
            : [];
        $existing['recordedAt'] = gmdate(DATE_ATOM);
        $existing['publishedStubs'] = true;
        $existing['scenarios'][$scenario] = $observation;
        $this->writeJson($path, $existing);
    }

    private function billable(int $id): CheckoutFlowLiveBillableUser
    {
        $billable = new CheckoutFlowLiveBillableUser;
        $billable->setRawAttributes(['id' => $id]);

        return $billable;
    }
}

class CheckoutFlowLiveBillableUser extends Authenticatable
{
    use Billable;
}
