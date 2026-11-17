<?php

declare(strict_types=1);

namespace Tests\Unit;

use Cable8mm\LaravelPortone\BillingKeyGateway;
use Cable8mm\LaravelPortone\Concerns\Billable;
use Cable8mm\LaravelPortone\GatewayBillingKey;
use Cable8mm\LaravelPortone\GatewayPayment;
use Cable8mm\LaravelPortone\Models\BillingKey;
use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\LaravelPortone\PaymentGateway;
use Cable8mm\LaravelPortone\PortOneServiceProvider;
use Cable8mm\PortonePhp\Exception\UnknownException;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Blade and the React stubs publish the same controller class, so each test loads it in its own process.
 *
 * Billing-key PortOne values are traced to the T-045 observation (tools/probes/portone-billing-key-observation.json)
 * and the T-048 observation (tests/E2E/portone-billing-key-stub-observation.json): a successful redirect query carries
 * only billingKey; a failure query carries billingKey (never issued), code, message, and pgCode and pgMessage when
 * the PG gives them; getBillingKeyInfo after issuance reports status ISSUED with customer.id and issueId equal to the
 * issuance values. The billing key is a placeholder because the observations redact it.
 */
#[RunTestsInSeparateProcesses]
class ReactCheckoutStubTest extends TestCase
{
    private const KEY = 'billing-key-00000000-0000-0000-0000-000000000001';

    private const PUBLISHED = [
        'resources/js/pages/portone/checkout.tsx',
        'resources/js/pages/portone/completed.tsx',
        'resources/js/pages/portone/billing-key.tsx',
        'app/Http/Controllers/PortOneCheckoutController.php',
        'routes/portone.php',
    ];

    protected function getPackageProviders($app): array
    {
        return [PortOneServiceProvider::class, InertiaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // The billing-key registry encrypts billing keys with the application key.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
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

    public function test_react_stubs_are_published_to_the_application_paths(): void
    {
        $this->publishStubs();

        foreach (self::PUBLISHED as $path) {
            $this->assertFileExists($this->app->basePath($path));
        }
        $root = dirname(__DIR__, 2);
        $this->assertFileEquals($root.'/stubs/routes/portone.php', $this->app->basePath('routes/portone.php'));
        $this->assertFileEquals(
            $root.'/stubs/react/resources/js/pages/portone/billing-key.tsx',
            $this->app->resourcePath('js/pages/portone/billing-key.tsx'),
        );
        $this->assertFileEquals(
            $root.'/stubs/react/app/Http/Controllers/PortOneCheckoutController.php',
            $this->app->basePath('app/Http/Controllers/PortOneCheckoutController.php'),
        );
        $this->assertFileDoesNotExist($this->app->resourcePath('views/portone/checkout.blade.php'));
        $this->assertFileDoesNotExist($this->app->resourcePath('js/portone-checkout.js'));
        $this->assertFileDoesNotExist($this->app->resourcePath('views/portone/billing-key.blade.php'));
        $this->assertFileDoesNotExist($this->app->resourcePath('js/portone-billing-key.js'));
        $this->assertFalse(Route::has('portone.checkout'));
    }

    public function test_checkout_renders_the_inertia_page_with_the_blade_view_values(): void
    {
        $this->publishAndIncludeRoutes();
        config(['portone.store_id' => 'store-123', 'portone.channel_key' => 'channel-456']);
        $this->actingAs($this->billable(101));

        $this->inertia(route('portone.checkout'))
            ->assertJsonPath('component', 'portone/checkout')
            ->assertJsonPath('props.product', ['id' => 1, 'name' => 'Order A', 'price' => 10000])
            ->assertJsonPath('props.storeId', 'store-123')
            ->assertJsonPath('props.channelKey', 'channel-456')
            ->assertJsonPath('props.prepareUrl', route('portone.checkout.prepare'))
            ->assertJsonPath('props.csrfToken', csrf_token())
            ->assertJsonPath('props.missingConfiguration', false)
            ->assertJsonPath('props.state', 'initial')
            ->assertJsonPath('props.statusText', 'Idle');
    }

    public function test_missing_configuration_renders_the_error_state(): void
    {
        $this->publishAndIncludeRoutes();
        $this->actingAs($this->billable(101));

        foreach ([
            ['store_id' => null, 'channel_key' => 'channel-456'],
            ['store_id' => 'store-123', 'channel_key' => ''],
        ] as $configuration) {
            config([
                'portone.store_id' => $configuration['store_id'],
                'portone.channel_key' => $configuration['channel_key'],
            ]);

            $this->inertia(route('portone.checkout'))
                ->assertJsonPath('props.missingConfiguration', true)
                ->assertJsonPath('props.state', 'error')
                ->assertJsonPath('props.statusText', 'Error');
        }
    }

    public function test_prepare_returns_browser_payment_request_values_as_json(): void
    {
        $this->migratePackageTables();
        $this->publishAndIncludeRoutes();
        config(['portone.store_id' => 'store-123', 'portone.channel_key' => 'channel-456']);
        $billable = $this->billable(101);
        $this->actingAs($billable);

        $response = $this->postJson(route('portone.checkout.prepare'), ['product' => 1, 'amount' => 1]);

        $payment = Payment::query()->sole();
        $response->assertOk()->assertExactJson([
            'storeId' => 'store-123',
            'channelKey' => 'channel-456',
            'paymentId' => $payment->payment_id,
            'orderName' => 'Order A',
            'totalAmount' => 10000,
            'redirectUrl' => route('portone.checkout.completed', ['paymentId' => $payment->payment_id]),
            'customer' => ['customerId' => $billable->portOneId(), 'fullName' => '홍길동'],
            'forceRedirect' => true,
        ]);
        $this->assertSame('PENDING', $payment->status);
    }

    public function test_completed_completes_a_pending_payment_and_renders_the_paid_page(): void
    {
        $this->migratePackageTables();
        $this->publishAndIncludeRoutes();
        $gateway = $this->gatewayMock();
        $billable = $this->billable(101);
        $payment = $billable->preparePayment(100, 'Order A');
        $gateway->expects($this->once())
            ->method('lookup')
            ->with($payment->payment_id)
            ->willReturn(new GatewayPayment('PAID', 100));
        $this->actingAs($billable);

        $response = $this->inertia(route('portone.checkout.completed', ['paymentId' => $payment->payment_id]));

        $payment->refresh();
        $this->assertSame('PAID', $payment->status);
        $response->assertJsonPath('component', 'portone/completed')
            ->assertJsonPath('props.failed', false)
            ->assertJsonPath('props.payment', [
                'payment_id' => $payment->payment_id,
                'order_name' => 'Order A',
                'amount' => 100,
                'paid_at' => $payment->paid_at?->toDateTimeString(),
                'status' => 'PAID',
            ]);
    }

    public function test_completed_renders_failed_when_portone_reports_not_paid(): void
    {
        $this->migratePackageTables();
        $this->publishAndIncludeRoutes();
        $gateway = $this->gatewayMock();
        $billable = $this->billable(101);
        $payment = $billable->preparePayment(100, 'Order A');
        $gateway->expects($this->once())->method('lookup')->willReturn(new GatewayPayment('FAILED', 100));
        $this->actingAs($billable);

        $this->inertia(route('portone.checkout.completed', ['paymentId' => $payment->payment_id]))
            ->assertJsonPath('props.failed', true)
            ->assertJsonPath('props.payment.status', 'PENDING');

        $this->assertSame('PENDING', $payment->fresh()->status);
    }

    public function test_completed_renders_failed_for_a_redirect_error_without_touching_the_record(): void
    {
        $this->migratePackageTables();
        $this->publishAndIncludeRoutes();
        $gateway = $this->gatewayMock();
        $gateway->expects($this->never())->method('lookup');
        $billable = $this->billable(101);
        $payment = $billable->preparePayment(100, 'Order A');
        $this->actingAs($billable);

        $this->inertia(route('portone.checkout.completed', ['paymentId' => $payment->payment_id]).'?code=FAILURE_TYPE_PG&message=failed')
            ->assertJsonPath('props.failed', true)
            ->assertJsonPath('props.payment.payment_id', $payment->payment_id);

        $this->assertSame('PENDING', $payment->fresh()->status);
    }

    public function test_completed_is_not_found_for_unknown_or_other_payments(): void
    {
        $this->migratePackageTables();
        $this->publishAndIncludeRoutes();
        $others = $this->billable(202)->preparePayment(100, 'Order A');
        $others->forceFill(['status' => 'PAID', 'paid_at' => now()])->save();
        $this->actingAs($this->billable(101));

        foreach ([$others->payment_id, 'unknown-payment'] as $paymentId) {
            $this->inertia(route('portone.checkout.completed', ['paymentId' => $paymentId]))->assertNotFound();
        }
    }

    public function test_unauthenticated_requests_redirect_to_login(): void
    {
        $this->publishAndIncludeRoutes();
        Route::get('/login', static fn () => response('login'))->name('login');

        $this->get(route('portone.checkout'))->assertRedirect(route('login'));
        $this->post(route('portone.checkout.prepare'))->assertRedirect(route('login'));
        $this->get(route('portone.billing-key'))->assertRedirect(route('login'));
        $this->post(route('portone.billing-key.prepare'))->assertRedirect(route('login'));
        $this->get(route('portone.billing-key.completed', ['issueId' => 'bk-1', 'billingKey' => self::KEY]))->assertRedirect(route('login'));
    }

    public function test_billing_key_renders_the_inertia_page_with_the_blade_view_values(): void
    {
        $this->publishAndIncludeRoutes();
        config(['portone.store_id' => 'store-123', 'portone.channel_key' => 'channel-456', 'portone.billing_channel_key' => 'billing-channel-789']);
        $this->actingAs($this->billable(101));

        $this->inertia(route('portone.billing-key'))
            ->assertJsonPath('component', 'portone/billing-key')
            ->assertJsonPath('props.storeId', 'store-123')
            ->assertJsonPath('props.channelKey', 'billing-channel-789')
            ->assertJsonPath('props.prepareUrl', route('portone.billing-key.prepare'))
            ->assertJsonPath('props.billingKeyUrl', route('portone.billing-key'))
            ->assertJsonPath('props.csrfToken', csrf_token())
            ->assertJsonPath('props.missingConfiguration', false)
            ->assertJsonPath('props.state', 'initial')
            ->assertJsonPath('props.statusText', 'Idle')
            ->assertJsonMissingPath('props.issueId');
    }

    public function test_billing_key_with_missing_store_id_or_billing_channel_key_renders_the_error_state(): void
    {
        $this->publishAndIncludeRoutes();
        $this->actingAs($this->billable(101));

        foreach ([
            ['store_id' => null, 'billing_channel_key' => 'billing-channel-789'],
            ['store_id' => '', 'billing_channel_key' => 'billing-channel-789'],
            ['store_id' => 'store-123', 'billing_channel_key' => null],
            ['store_id' => 'store-123', 'billing_channel_key' => ''],
        ] as $configuration) {
            // The checkout channel key is never used for billing keys.
            config([
                'portone.store_id' => $configuration['store_id'],
                'portone.channel_key' => 'channel-456',
                'portone.billing_channel_key' => $configuration['billing_channel_key'],
            ]);

            $this->inertia(route('portone.billing-key'))
                ->assertJsonPath('props.missingConfiguration', true)
                ->assertJsonPath('props.state', 'error')
                ->assertJsonPath('props.statusText', 'Error');
        }
    }

    public function test_prepare_billing_key_returns_browser_billing_key_request_values_as_json(): void
    {
        $this->migratePackageTables();
        $this->publishAndIncludeRoutes();
        $gateway = $this->billingKeyGateway();
        config(['portone.store_id' => 'store-123', 'portone.billing_channel_key' => 'billing-channel-789']);
        $billable = $this->billable(101);
        $this->actingAs($billable);

        $response = $this->postJson(route('portone.billing-key.prepare'), ['issueName' => 'Untrusted', 'customerId' => 'other']);

        $record = BillingKey::query()->sole();
        $response->assertOk()->assertExactJson([
            'storeId' => 'store-123',
            'channelKey' => 'billing-channel-789',
            'billingKeyMethod' => 'CARD',
            'issueId' => $record->issue_id,
            'issueName' => 'Card registration',
            'redirectUrl' => route('portone.billing-key.completed', ['issueId' => $record->issue_id]),
            'customer' => ['customerId' => $billable->portOneId()],
            'forceRedirect' => true,
        ]);
        $this->assertSame(['PENDING', 'Card registration', $billable->portOneId()], [$record->status, $record->issue_name, $record->customer_id]);
        $this->assertSame([], $gateway->requests);
    }

    public function test_billing_key_completed_issues_the_key_and_renders_the_issued_page_without_the_key(): void
    {
        $this->migratePackageTables();
        $this->publishAndIncludeRoutes();
        $gateway = $this->billingKeyGateway();
        $billable = $this->billable(101);
        $record = $billable->prepareBillingKey('Card registration');
        $gateway->infos[self::KEY] = new GatewayBillingKey('ISSUED', $record->customer_id, $record->issue_id);
        $this->actingAs($billable);

        $redirect = $this->get(route('portone.billing-key.completed', ['issueId' => $record->issue_id, 'billingKey' => self::KEY]));

        // The page is rendered from the path without the redirect query, so its URL carries no billing key (FR-160).
        $redirect->assertRedirect(route('portone.billing-key.completed', ['issueId' => $record->issue_id]));
        $this->assertSame('ISSUED', $record->fresh()->status);
        $response = $this->inertia((string) $redirect->headers->get('Location'));
        $response->assertJsonPath('component', 'portone/billing-key')
            ->assertJsonPath('props.state', 'issued')
            ->assertJsonPath('props.statusText', 'Issued')
            ->assertJsonPath('props.issueId', $record->issue_id)
            ->assertJsonPath('props.billingKeyUrl', route('portone.billing-key'))
            ->assertJsonPath('url', '/portone/billing-key/completed/'.$record->issue_id);
        $this->assertStringNotContainsString(self::KEY, (string) $response->getContent());
        $this->assertStringNotContainsString(self::KEY, (string) json_encode(session()->all()));
        // A later visit without the result, such as a reload, shows Failed and sends no request.
        $this->inertia((string) $redirect->headers->get('Location'))->assertJsonPath('props.state', 'failed');
        $this->assertSame([self::KEY], $gateway->requests);
    }

    public function test_billing_key_failure_redirect_renders_the_failed_page_without_touching_any_record(): void
    {
        $this->migratePackageTables();
        $this->publishAndIncludeRoutes();
        $gateway = $this->billingKeyGateway();
        $billable = $this->billable(101);
        $record = $billable->prepareBillingKey('Card registration');
        $before = $record->fresh()->getAttributes();
        $this->actingAs($billable);

        foreach ([
            // T-048: closing the KCP window.
            ['billingKey' => self::KEY, 'code' => 'FAILURE_TYPE_PG', 'message' => '[3001] 사용자가 결제를 취소하였습니다.', 'pgCode' => '3001', 'pgMessage' => '사용자가 결제를 취소하였습니다.'],
            ['billingKey' => self::KEY, 'code' => 'FAILURE_TYPE_PG', 'message' => 'KCP 스크립트 로드에 실패하였습니다.'],
            [],
        ] as $query) {
            $response = $this->returnFromBillingKeyWindow($record->issue_id, $query);

            $response->assertJsonPath('component', 'portone/billing-key')
                ->assertJsonPath('props.state', 'failed')
                ->assertJsonPath('props.statusText', 'Failed')
                ->assertJsonPath('props.issueId', $record->issue_id)
                ->assertJsonPath('url', '/portone/billing-key/completed/'.$record->issue_id);
            $this->assertStringNotContainsString(self::KEY, (string) $response->getContent());
        }

        $this->assertSame($before, $record->fresh()->getAttributes());
        $this->assertSame([], $gateway->requests);
    }

    public function test_billing_key_completed_renders_the_failed_page_when_the_completion_raises(): void
    {
        $this->migratePackageTables();
        $this->publishAndIncludeRoutes();
        $gateway = $this->billingKeyGateway();
        $billable = $this->billable(101);
        $record = $billable->prepareBillingKey('Card registration');
        $this->actingAs($billable);

        // Issued for another customer: the record becomes FAILED (FR-121).
        $gateway->infos[self::KEY] = new GatewayBillingKey('ISSUED', 'another-customer', $record->issue_id);
        $this->returnFromBillingKeyWindow($record->issue_id)->assertJsonPath('props.state', 'failed');
        $this->assertSame('FAILED', $record->fresh()->status);

        // An Issue ID of no record of this user sends no request (ERR-023).
        $this->returnFromBillingKeyWindow('bk-unknown')->assertJsonPath('props.state', 'failed');

        $gateway->failure = new UnknownException('getBillingKeyInfo', 'PortOne returned an error response.', 502, null, null, 'PG_PROVIDER');
        $this->returnFromBillingKeyWindow($record->issue_id)->assertJsonPath('props.state', 'failed');
        $this->assertSame([self::KEY, self::KEY], $gateway->requests);
    }

    /**
     * @return TestResponse<Response>
     */
    private function inertia(string $url): TestResponse
    {
        return $this->get($url, ['X-Inertia' => 'true']);
    }

    /**
     * The redirect return of the billing-key window (a successful one by default), followed to the Inertia page.
     *
     * @param  array<string, string>  $query
     * @return TestResponse<Response>
     */
    private function returnFromBillingKeyWindow(string $issueId, array $query = ['billingKey' => self::KEY]): TestResponse
    {
        $location = route('portone.billing-key.completed', ['issueId' => $issueId]);

        if ($query === []) {
            return $this->inertia($location);
        }

        $this->get(route('portone.billing-key.completed', ['issueId' => $issueId] + $query))->assertRedirect($location);

        return $this->inertia($location);
    }

    private function publishStubs(): void
    {
        $this->artisan('vendor:publish', [
            '--tag' => 'portone-stubs-react',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertExitCode(0);
    }

    private function publishAndIncludeRoutes(): void
    {
        $this->publishStubs();

        require_once $this->app->basePath('app/Http/Controllers/PortOneCheckoutController.php');
        // An Application includes the route file in its web routes.
        Route::middleware('web')->group($this->app->basePath('routes/portone.php'));
        Route::getRoutes()->refreshNameLookups();
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

    private function gatewayMock(): PaymentGateway&MockObject
    {
        $gateway = $this->createMock(PaymentGateway::class);
        $this->app->instance(PaymentGateway::class, $gateway);

        return $gateway;
    }

    private function billingKeyGateway(): ReactBillingKeyGateway
    {
        $gateway = new ReactBillingKeyGateway;
        $this->app->instance(BillingKeyGateway::class, $gateway);

        return $gateway;
    }

    private function billable(int $key): ReactCheckoutBillableUser
    {
        $billable = new ReactCheckoutBillableUser;
        $billable->setRawAttributes(['id' => $key, 'name' => '홍길동']);

        return $billable;
    }
}

class ReactCheckoutBillableUser extends Authenticatable
{
    use Billable;
}

/**
 * BillingKeyGateway test double answering getBillingKeyInfo with the T-045 recorded shape.
 */
final class ReactBillingKeyGateway implements BillingKeyGateway
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
