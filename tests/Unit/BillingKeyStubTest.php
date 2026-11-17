<?php

declare(strict_types=1);

namespace Tests\Unit;

use Cable8mm\LaravelPortone\BillingKeyGateway;
use Cable8mm\LaravelPortone\Concerns\Billable;
use Cable8mm\LaravelPortone\GatewayBillingKey;
use Cable8mm\LaravelPortone\Models\BillingKey;
use Cable8mm\LaravelPortone\PortOneServiceProvider;
use Cable8mm\PortonePhp\Exception\UnknownException;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;

/**
 * T-048: the billing-key Blade stubs (EL-092, EL-093, FR-040, FR-155 to FR-160, ERR-022, AC-112).
 *
 * PortOne values are traced to the T-045 observation (tools/probes/portone-billing-key-observation.json):
 * a successful redirect query carries only billingKey; a failure query carries billingKey (never issued), code,
 * message, and pgCode and pgMessage when the PG gives them (issuances); getBillingKeyInfo after issuance reports
 * status ISSUED with customer.id and issueId equal to the issuance values (steps.billingKeyInfo).
 * The billing key is a placeholder because the observation redacts it.
 */
class BillingKeyStubTest extends TestCase
{
    private const KEY = 'billing-key-00000000-0000-0000-0000-000000000001';

    private const PUBLISHED = [
        'resources/views/portone/checkout.blade.php',
        'resources/views/portone/completed.blade.php',
        'resources/views/portone/billing-key.blade.php',
        'resources/js/portone-checkout.js',
        'resources/js/portone-billing-key.js',
        'app/Http/Controllers/PortOneCheckoutController.php',
        'routes/portone.php',
    ];

    private StubBillingKeyGateway $gateway;

    protected function getPackageProviders($app): array
    {
        return [PortOneServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('portone.store_id', 'store-123');
        $app['config']->set('portone.channel_key', 'channel-456');
        $app['config']->set('portone.billing_channel_key', 'billing-channel-789');
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [] as $migration) {
            $this->artisan('migrate', ['--path' => $migration, '--realpath' => true, '--force' => true])->assertExitCode(0);
        }

        $this->gateway = new StubBillingKeyGateway;
        $this->app->instance(BillingKeyGateway::class, $this->gateway);

        $this->artisan('vendor:publish', ['--tag' => 'portone-stubs', '--force' => true, '--no-interaction' => true])->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        $paths = array_map(fn (string $path): string => $this->app->basePath($path), self::PUBLISHED);

        parent::tearDown();

        foreach ($paths as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function test_portone_stubs_publish_the_billing_key_view_script_routes_and_actions(): void
    {
        foreach (self::PUBLISHED as $path) {
            $this->assertFileExists($this->app->basePath($path));
        }

        $root = dirname(__DIR__, 2);
        $this->assertFileEquals($root.'/stubs/resources/views/portone/billing-key.blade.php', $this->app->resourcePath('views/portone/billing-key.blade.php'));
        $this->assertFileEquals($root.'/stubs/resources/js/portone-billing-key.js', $this->app->resourcePath('js/portone-billing-key.js'));
        $this->assertStringContainsString(
            "@vite('resources/js/portone-billing-key.js')",
            (string) file_get_contents($this->app->resourcePath('views/portone/billing-key.blade.php')),
        );
        $this->assertFalse(Route::has('portone.billing-key'));

        $this->includePublishedRoutes();

        foreach (['portone.billing-key', 'portone.billing-key.prepare', 'portone.billing-key.completed'] as $name) {
            $this->assertTrue(Route::has($name));
            $this->assertSame(['auth'], Route::getRoutes()->getByName($name)->gatherMiddleware());
        }
        $this->assertSame(['GET', 'HEAD'], Route::getRoutes()->getByName('portone.billing-key')->methods());
        $this->assertSame(['POST'], Route::getRoutes()->getByName('portone.billing-key.prepare')->methods());
        $this->assertSame('portone/billing-key/completed/{issueId}', Route::getRoutes()->getByName('portone.billing-key.completed')->uri());
    }

    public function test_unauthenticated_requests_create_no_record(): void
    {
        $this->includePublishedRoutes();
        Route::get('/login', static fn () => response('login'))->name('login');

        $this->get(route('portone.billing-key'))->assertRedirect(route('login'));
        $this->post(route('portone.billing-key.prepare'))->assertRedirect(route('login'));
        $this->get(route('portone.billing-key.completed', ['issueId' => 'bk-1', 'billingKey' => self::KEY]))->assertRedirect(route('login'));

        $this->assertSame(0, BillingKey::query()->count());
        $this->assertSame([], $this->gateway->requests);
    }

    public function test_the_page_renders_the_initial_state_with_the_store_id_and_billing_channel_key(): void
    {
        $this->includePublishedRoutes();
        $this->actingAs($this->user());
        $this->withoutVite();

        $response = $this->get(route('portone.billing-key'));

        $response->assertOk();
        $response->assertSee('data-store-id="store-123"', false);
        $response->assertSee('data-channel-key="billing-channel-789"', false);
        $response->assertSee('data-prepare-url="'.route('portone.billing-key.prepare').'"', false);
        $response->assertSee('data-csrf-token="'.csrf_token().'"', false);
        $response->assertSee('data-state="initial"', false);
        $response->assertSee('<button id="portone-billing-key-button" type="button" >Register card</button>', false);
        $response->assertSee('<p id="portone-billing-key-status" role="status">Idle</p>', false);
        $this->assertLessThan(
            strpos($response->getContent(), '<p id="portone-billing-key-status"'),
            strpos($response->getContent(), '<button id="portone-billing-key-button"'),
        );
        $this->assertSame(0, BillingKey::query()->count());
    }

    public function test_missing_or_empty_store_id_or_billing_channel_key_renders_the_disabled_error_state(): void
    {
        $this->includePublishedRoutes();
        $this->actingAs($this->user());
        $this->withoutVite();

        foreach ([
            ['store_id' => null, 'billing_channel_key' => 'billing-channel-789'],
            ['store_id' => '', 'billing_channel_key' => 'billing-channel-789'],
            ['store_id' => 'store-123', 'billing_channel_key' => null],
            ['store_id' => 'store-123', 'billing_channel_key' => ''],
        ] as $configuration) {
            config([
                'portone.store_id' => $configuration['store_id'],
                'portone.billing_channel_key' => $configuration['billing_channel_key'],
            ]);

            $response = $this->get(route('portone.billing-key'));

            $response->assertOk();
            $response->assertSee('data-state="error"', false);
            $response->assertSee('<button id="portone-billing-key-button" type="button" disabled>Register card</button>', false);
            $response->assertSee('<p id="portone-billing-key-status" role="status">Error</p>', false);
        }
    }

    public function test_the_checkout_channel_key_is_not_used_for_billing_keys(): void
    {
        $this->includePublishedRoutes();
        $this->actingAs($this->user());
        $this->withoutVite();
        config(['portone.billing_channel_key' => null]);

        $this->get(route('portone.billing-key'))->assertSee('data-state="error"', false);
    }

    public function test_prepare_creates_a_pending_record_and_returns_the_browser_billing_key_request_values(): void
    {
        $this->includePublishedRoutes();
        $user = $this->user();
        $this->actingAs($user);

        $response = $this->postJson(route('portone.billing-key.prepare'), ['issueName' => 'Untrusted', 'customerId' => 'other']);

        $record = BillingKey::query()->sole();
        $response->assertOk();
        $response->assertExactJson([
            'storeId' => 'store-123',
            'channelKey' => 'billing-channel-789',
            'billingKeyMethod' => 'CARD',
            'issueId' => $record->issue_id,
            'issueName' => 'Card registration',
            'redirectUrl' => route('portone.billing-key.completed', ['issueId' => $record->issue_id]),
            'customer' => ['customerId' => $user->portOneId()],
            'forceRedirect' => true,
        ]);
        $this->assertSame(['PENDING', 'Card registration', $user->portOneId()], [$record->status, $record->issue_name, $record->customer_id]);
        $this->assertSame([], $this->gateway->requests);
    }

    public function test_completed_issues_the_key_and_renders_the_issued_state_without_the_key(): void
    {
        $this->includePublishedRoutes();
        $user = $this->user();
        $this->actingAs($user);
        $this->withoutVite();
        $record = $user->prepareBillingKey('Card registration');
        $this->gateway->infos[self::KEY] = new GatewayBillingKey('ISSUED', $record->customer_id, $record->issue_id);

        $response = $this->get(route('portone.billing-key.completed', ['issueId' => $record->issue_id, 'billingKey' => self::KEY]));

        $response->assertOk();
        $response->assertSee('data-state="issued"', false);
        $response->assertSee('data-issue-id="'.$record->issue_id.'"', false);
        $response->assertSee('<p id="portone-billing-key-status" role="status">Issued</p>', false);
        $response->assertDontSee('portone-billing-key-button', false);
        $response->assertDontSee(self::KEY, false);
        $this->assertSame('ISSUED', $record->fresh()->status);
        $this->assertSame([self::KEY], $this->gateway->requests);
    }

    public function test_a_failure_redirect_renders_the_failed_state_without_touching_any_record(): void
    {
        $this->includePublishedRoutes();
        $user = $this->user();
        $this->actingAs($user);
        $this->withoutVite();
        $record = $user->prepareBillingKey('Card registration');
        $before = $record->fresh()->getAttributes();

        foreach ([
            ['billingKey' => self::KEY, 'code' => 'FAILURE_TYPE_PG', 'message' => '[3017] 팝업 차단 상태', 'pgCode' => '3017', 'pgMessage' => '팝업 차단 상태'],
            ['billingKey' => self::KEY, 'code' => 'FAILURE_TYPE_PG', 'message' => 'KCP 스크립트 로드에 실패하였습니다.'],
            [],
        ] as $query) {
            $response = $this->get(route('portone.billing-key.completed', ['issueId' => $record->issue_id] + $query));

            $response->assertOk();
            $response->assertSee('data-state="failed"', false);
            $response->assertSee('<p id="portone-billing-key-status" role="status">Failed</p>', false);
            $response->assertDontSee(self::KEY, false);
        }

        $this->assertSame($before, $record->fresh()->getAttributes());
        $this->assertSame([], $this->gateway->requests);
    }

    public function test_completed_renders_the_failed_state_when_the_completion_raises(): void
    {
        $this->includePublishedRoutes();
        $user = $this->user();
        $this->actingAs($user);
        $this->withoutVite();
        $record = $user->prepareBillingKey('Card registration');

        // Issued for another customer: the record becomes FAILED (FR-121).
        $this->gateway->infos[self::KEY] = new GatewayBillingKey('ISSUED', 'another-customer', $record->issue_id);
        $this->get(route('portone.billing-key.completed', ['issueId' => $record->issue_id, 'billingKey' => self::KEY]))
            ->assertOk()->assertSee('data-state="failed"', false)->assertDontSee(self::KEY, false);
        $this->assertSame('FAILED', $record->fresh()->status);

        // An Issue ID of no record of this user.
        $this->get(route('portone.billing-key.completed', ['issueId' => 'bk-unknown', 'billingKey' => self::KEY]))
            ->assertOk()->assertSee('data-state="failed"', false);

        // A PortOne failure passes the Package unwrapped and the page shows Failed.
        $this->gateway->failure = new UnknownException('getBillingKeyInfo', 'PortOne returned an error response.', 502, null, null, 'PG_PROVIDER');
        $this->get(route('portone.billing-key.completed', ['issueId' => $record->issue_id, 'billingKey' => self::KEY]))
            ->assertOk()->assertSee('data-state="failed"', false);
        // The unknown Issue ID sent no request (ERR-023).
        $this->assertSame([self::KEY, self::KEY], $this->gateway->requests);
    }

    private function user(): BillingKeyStubUser
    {
        $user = new BillingKeyStubUser;
        $user->setRawAttributes(['id' => 101]);

        return $user;
    }

    private function includePublishedRoutes(): void
    {
        require_once $this->app->path('Http/Controllers/PortOneCheckoutController.php');
        require $this->app->basePath('routes/portone.php');
        Route::getRoutes()->refreshNameLookups();
    }
}

class BillingKeyStubUser extends Authenticatable
{
    use Billable;

    protected $table = 'users';

    public $timestamps = false;
}

/**
 * BillingKeyGateway test double answering getBillingKeyInfo with the T-045 recorded shape.
 */
final class StubBillingKeyGateway implements BillingKeyGateway
{
    /** @var list<string> billing keys looked up */
    public array $requests = [];

    /** @var array<string, GatewayBillingKey> */
    public array $infos = [];

    public ?\Throwable $failure = null;

    public function assertConfigured(): void {}

    public function billingKeyInfo(#[\SensitiveParameter] string $billingKey): GatewayBillingKey
    {
        $this->requests[] = $billingKey;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->infos[$billingKey] ?? throw new \LogicException('The test set no billing-key info for this key.');
    }

    public function deleteBillingKey(#[\SensitiveParameter] string $billingKey, string $reason): void
    {
        throw new \LogicException('Not used by the billing-key stubs.');
    }

    public function payWithBillingKey(string $paymentId, #[\SensitiveParameter] string $billingKey, string $orderName, int $amount): void
    {
        throw new \LogicException('Not used by the billing-key stubs.');
    }
}
