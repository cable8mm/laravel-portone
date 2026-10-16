<?php

declare(strict_types=1);

namespace Tests\Unit;

use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\LaravelPortone\PortOneServiceProvider;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

class CheckoutStubPublishingTest extends TestCase
{
    /** @var list<string> */
    private array $publishedFiles = [];

    protected function getPackageProviders($app): array
    {
        return [PortOneServiceProvider::class];
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

    public function test_portone_stubs_are_published_to_the_application_paths(): void
    {
        $this->publishStubs();

        $this->assertFileExists($this->publishedPath('resources/views/portone/checkout.blade.php'));
        $this->assertFileExists($this->publishedPath('resources/views/portone/completed.blade.php'));
        $this->assertFileExists($this->publishedPath('resources/js/portone-checkout.js'));
        $this->assertFileExists($this->publishedPath('app/Http/Controllers/PortOneCheckoutController.php'));
        $this->assertFileExists($this->publishedPath('routes/portone.php'));
        $this->assertStringContainsString(
            "@vite('resources/js/portone-checkout.js')",
            file_get_contents($this->publishedPath('resources/views/portone/checkout.blade.php')),
        );
        $this->assertFalse(Route::has('portone.checkout'));
        $this->assertFalse(Route::has('portone.checkout.prepare'));
        $this->assertFalse(Route::has('portone.checkout.complete'));
        $this->assertFalse(Route::has('portone.checkout.completed'));
    }

    public function test_including_the_published_routes_registers_only_authenticated_checkout_routes(): void
    {
        $this->migratePackageTables();
        $this->publishStubs();
        $this->includePublishedRoutes();

        Route::get('/login', static fn () => response('login'))->name('login');
        $this->get(route('portone.checkout'))->assertRedirect(route('login'));

        $this->assertSame(0, Payment::query()->count());
        $this->assertTrue(Route::has('portone.checkout'));
        $this->assertTrue(Route::has('portone.checkout.prepare'));
        $this->assertTrue(Route::has('portone.checkout.complete'));
        $this->assertSame(['auth'], Route::getRoutes()->getByName('portone.checkout')->gatherMiddleware());
        $this->assertSame(['auth'], Route::getRoutes()->getByName('portone.checkout.prepare')->gatherMiddleware());
        $this->assertSame(['auth'], Route::getRoutes()->getByName('portone.checkout.complete')->gatherMiddleware());
        $this->assertSame(['auth'], Route::getRoutes()->getByName('portone.checkout.completed')->gatherMiddleware());
        $this->assertSame('portone/checkout/completed/{paymentId}', Route::getRoutes()->getByName('portone.checkout.completed')->uri());
    }

    public function test_authenticated_checkout_renders_the_product_initial_state_and_configuration(): void
    {
        $this->publishStubs();
        $this->loadPublishedController();
        $this->includePublishedRoutes();
        config(['portone.store_id' => 'store-123', 'portone.channel_key' => 'channel-456']);
        $this->actingAs(new GenericUser(['id' => 1]));
        $this->withoutVite();

        $response = $this->get(route('portone.checkout'));

        $response->assertOk();
        $response->assertSee('data-store-id="store-123"', false);
        $response->assertSee('data-channel-key="channel-456"', false);
        $response->assertSee('data-prepare-url="'.route('portone.checkout.prepare').'"', false);
        $response->assertDontSee('data-complete-url', false);
        $response->assertSee('data-csrf-token="'.csrf_token().'"', false);
        $response->assertSee('data-product="1"', false);
        $response->assertSee('<h1>Order A</h1>', false);
        $response->assertSee('<p>10,000 KRW</p>', false);
        $response->assertSee('data-state="initial"', false);
        $response->assertSee('id="portone-checkout-button"', false);
        $response->assertDontSee('type="button" disabled', false);
        $response->assertSee('<p id="portone-checkout-status" role="status">Idle</p>', false);
        $this->assertLessThan(
            strpos($response->getContent(), '<p id="portone-checkout-status"'),
            strpos($response->getContent(), '<button id="portone-checkout-button"'),
        );
    }

    public function test_missing_or_empty_checkout_configuration_renders_disabled_error_state(): void
    {
        $this->publishStubs();
        $this->loadPublishedController();
        $this->includePublishedRoutes();
        $this->actingAs(new GenericUser(['id' => 1]));
        $this->withoutVite();

        foreach ([
            ['store_id' => null, 'channel_key' => 'channel-456'],
            ['store_id' => 'store-123', 'channel_key' => ''],
        ] as $configuration) {
            config([
                'portone.store_id' => $configuration['store_id'],
                'portone.channel_key' => $configuration['channel_key'],
            ]);

            $response = $this->get(route('portone.checkout'));

            $response->assertOk();
            $response->assertSee('data-state="error"', false);
            $response->assertSee('type="button" disabled', false);
            $response->assertSee('Start payment</button>', false);
            $response->assertSee('<p id="portone-checkout-status" role="status">Error</p>', false);
        }
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
            'resources/views/portone/completed.blade.php',
            'resources/js/portone-checkout.js',
            'app/Http/Controllers/PortOneCheckoutController.php',
            'routes/portone.php',
        ] as $path) {
            $this->publishedFiles[] = $this->publishedPath($path);
        }
    }

    private function includePublishedRoutes(): void
    {
        $controller = $this->publishedPath('app/Http/Controllers/PortOneCheckoutController.php');

        if (is_file($controller)) {
            require_once $controller;
        }

        require $this->publishedPath('routes/portone.php');
    }

    private function loadPublishedController(): void
    {
        require_once $this->publishedPath('app/Http/Controllers/PortOneCheckoutController.php');
    }

    private function publishedPath(string $path): string
    {
        return $this->app->basePath($path);
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

        $this->assertTrue(Schema::hasTable('portone_payments'));
    }
}
