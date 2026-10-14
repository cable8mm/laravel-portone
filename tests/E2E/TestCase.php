<?php

declare(strict_types=1);

namespace Tests\E2E;

use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\LaravelPortone\PortOneServiceProvider;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\Route;
use Laravel\Dusk\Browser;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\Dusk\TestCase as DuskTestCase;
use Workbench\App\Http\Middleware\MapBrowserSdkImport;
use Workbench\App\Models\User;

use function Orchestra\Testbench\default_migration_path;
use function Orchestra\Testbench\package_path;
use function Orchestra\Testbench\workbench_path;

/**
 * Live tests drive the Workbench app, served by Testbench Dusk, with the
 * published checkout stubs. Testbench Dusk boots the served app from this
 * class, so defineEnvironment() applies to the test and the server alike.
 */
abstract class TestCase extends DuskTestCase
{
    use WithWorkbench;

    /** Seconds the human has to operate the PG payment window. */
    protected const PAYER_TIMEOUT = 900;

    private const PUBLISHED_STUBS = [
        'resources/views/portone/checkout.blade.php',
        'resources/js/portone-checkout.js',
        'app/Http/Controllers/PortOneCheckoutController.php',
        'routes/portone.php',
    ];

    /** @var list<string> */
    private array $createdFiles = [];

    protected function getPackageProviders($app): array
    {
        // The parent returns the providers listed in testbench.yaml.
        return [...parent::getPackageProviders($app), PortOneServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // Providers register before this hook runs, so the Package configuration
        // was merged before the local .env was loaded; read it again.
        Dotenv::createImmutable(package_path())->safeLoad();
        $app['config']->set('portone', require package_path('config/portone.php'));
        $app['config']->set('auth.providers.users.model', User::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['api_secret', 'store_id', 'channel_key'] as $key) {
            self::assertNotEmpty(config('portone.'.$key), 'PORTONE_'.strtoupper($key).' must be set in the local .env file.');
        }

        $this->publishCheckoutStubs();
        $this->buildCheckoutEntry();
        $this->migrateFreshDatabase();
    }

    protected function tearDown(): void
    {
        $createdFiles = $this->createdFiles;

        parent::tearDown();

        foreach ($createdFiles as $file) {
            if (is_link($file) || is_file($file)) {
                unlink($file);
            }
        }
    }

    protected function prepareDirectories(): void
    {
        foreach (['screenshots', 'console', 'source'] as $name) {
            $directory = __DIR__.'/debug/'.$name;

            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
        }

        Browser::$storeScreenshotsAt = __DIR__.'/debug/screenshots';
        Browser::$storeConsoleLogAt = __DIR__.'/debug/console';
        Browser::$storeSourceAt = __DIR__.'/debug/source';
    }

    /**
     * Tell the human what to do in the headed browser window.
     */
    protected function instruct(string $instruction): void
    {
        fwrite(STDOUT, PHP_EOL.'>>> '.$instruction.PHP_EOL);
    }

    /**
     * Have the human pay a Package-prepared payment on the Workbench pay page.
     */
    protected function payInBrowser(Browser $browser, User $user, Payment $payment, string $instruction): void
    {
        $browser->loginAs($user)
            ->visit(route('workbench.pay', ['paymentId' => $payment->payment_id], false))
            // Dusk reads a bare "#id:pseudo" selector as an element ID, so qualify it.
            ->waitFor('button#workbench-pay-button:enabled')
            ->click('#workbench-pay-button');

        $this->instruct($instruction);
        $browser->waitFor('[data-workbench-pay]:not([data-result="pending"])', self::PAYER_TIMEOUT);

        self::assertSame(
            'paid',
            $browser->attribute('[data-workbench-pay]', 'data-result'),
            'The browser SDK reported a payment error.',
        );
    }

    /**
     * Read an observation recorded by a live verification probe.
     *
     * @return array<string, mixed>
     */
    protected function probeObservation(string $name): array
    {
        return $this->readObservation(package_path('tools/probes/'.$name));
    }

    /**
     * @return array<string, mixed>
     */
    protected function readObservation(string $path): array
    {
        $observation = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($observation);

        return $observation;
    }

    /**
     * Merge an observation into a recorded observation file next to the tests.
     *
     * @param  array<string, mixed>  $observation
     */
    protected function recordObservation(string $name, array $observation): void
    {
        $path = __DIR__.'/'.$name;
        $merged = array_replace_recursive(is_file($path) ? $this->readObservation($path) : [], $observation);
        $lines = explode("\n", json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        foreach ($lines as &$line) {
            $spaces = strspn($line, ' ');
            $line = str_repeat("\t", intdiv($spaces, 4)).substr($line, $spaces);
        }

        self::assertNotFalse(file_put_contents($path, implode("\n", $lines).PHP_EOL, LOCK_EX));
    }

    private function publishCheckoutStubs(): void
    {
        $this->artisan('vendor:publish', [
            '--tag' => 'portone-stubs',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertExitCode(0);

        foreach (self::PUBLISHED_STUBS as $path) {
            $file = $this->app->basePath($path);
            self::assertFileExists($file);
            $this->createdFiles[] = $file;
        }

        // The app booted before the first publish, so the Workbench routes
        // file found nothing to include yet.
        if (! Route::has('portone.checkout')) {
            Route::middleware('web')->group(workbench_path('routes/web.php'));
            Route::getRoutes()->refreshNameLookups();
        }
    }

    /**
     * Stand in for the Application's Vite build of the published entry: the
     * Package configures no bundler, so the entry is copied as-is behind a
     * manifest and its SDK import is resolved by MapBrowserSdkImport.
     */
    private function buildCheckoutEntry(): void
    {
        $sdk = package_path('node_modules/@portone/browser-sdk/dist');
        self::assertFileExists($sdk.'/v2.js', 'Run npm ci to install @portone/browser-sdk.');

        $build = $this->app->publicPath('build');

        if (! is_dir($build)) {
            mkdir($build, 0777, true);
        }

        $entry = 'resources/js/portone-checkout.js';
        $sdkLink = $this->app->publicPath(dirname(ltrim(MapBrowserSdkImport::SDK_URL, '/')));

        if (is_link($sdkLink)) {
            unlink($sdkLink);
        }

        self::assertTrue(symlink($sdk, $sdkLink));
        self::assertTrue(copy($this->app->basePath($entry), $build.'/portone-checkout.js'));
        self::assertNotFalse(file_put_contents($build.'/manifest.json', json_encode([
            $entry => ['file' => 'portone-checkout.js', 'src' => $entry, 'isEntry' => true],
        ], JSON_THROW_ON_ERROR)));

        array_push($this->createdFiles, $sdkLink, $build.'/portone-checkout.js', $build.'/manifest.json');
    }

    private function migrateFreshDatabase(): void
    {
        $database = config('database.connections.sqlite.database');
        self::assertSame('sqlite', config('database.default'));
        self::assertIsString($database);

        if (! is_file($database)) {
            touch($database);
        }

        $this->artisan('migrate:fresh', [
            '--path' => [default_migration_path(), package_path('database/migrations')],
            '--realpath' => true,
            '--force' => true,
        ])->assertExitCode(0);
    }
}
