<?php

declare(strict_types=1);

namespace Tests\Unit;

use Cable8mm\LaravelPortone\Events\WebhookReceived;
use Cable8mm\LaravelPortone\GatewayPayment;
use Cable8mm\LaravelPortone\Jobs\ExpirePendingPayment;
use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\LaravelPortone\PaymentGateway;
use Cable8mm\LaravelPortone\PortOneServiceProvider;
use Cable8mm\PortonePhp\Webhook\WebhookTransaction;
use Cable8mm\PortonePhp\Webhook\WebhookTransactionData;
use DateTimeImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Console\QueuedCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;
use Orchestra\Workbench\WorkbenchServiceProvider;
use Workbench\App\Jobs\CompletePaymentFromWebhook;
use Workbench\App\Listeners\QueuePaymentCompletion;
use Workbench\App\Models\Product;
use Workbench\App\Models\User;
use Workbench\App\Models\WebhookLog;
use Workbench\Database\Factories\UserFactory;

use function Orchestra\Testbench\package_path;
use function Orchestra\Testbench\workbench_path;

class WorkbenchSampleShopTest extends TestCase
{
    use RefreshDatabase;
    use WithWorkbench;

    protected function getPackageProviders($app): array
    {
        // orchestra/workbench registers itself only through package discovery
        // under `composer serve`; it provides the /_workbench sign-in links.
        return [...parent::getPackageProviders($app), PortOneServiceProvider::class, WorkbenchServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('auth.providers.users.model', User::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_guide_is_shown_to_a_guest_without_signing_in(): void
    {
        config(['portone.store_id' => 'store-id-value', 'portone.channel_key' => null]);

        $this->get('/')
            ->assertOk()
            ->assertSee('href="'.url('/_workbench/login/test@example.com').'"', false)
            ->assertSee('POST /portone/webhook')
            ->assertSee('data-workbench-setting="PORTONE_STORE_ID"', false)
            ->assertDontSee('store-id-value');

        $this->assertGuest();
    }

    public function test_sign_in_link_signs_in_the_seeded_user_and_opens_the_shop(): void
    {
        $user = UserFactory::new()->create(['email' => 'test@example.com']);

        $this->get('/_workbench/login/test@example.com')->assertRedirect('/shop');

        $this->assertAuthenticatedAs($user);
    }

    public function test_shop_sends_a_guest_back_to_the_guide(): void
    {
        $this->get('/shop')->assertRedirect('/login');
        $this->get('/login')->assertRedirect('/');
    }

    public function test_product_pages_list_products_and_link_the_order_sheet_without_side_effects(): void
    {
        $this->includeSampleCheckoutRoutes();
        $product = $this->product();

        $this->actingAs(UserFactory::new()->create());

        $this->get('/shop')->assertOk()->assertSee('딸기 마카롱')->assertSee('1,000원');
        $this->get('/shop/products/'.$product->id)
            ->assertOk()
            ->assertSee('href="'.route('portone.checkout', ['product' => $product->id]).'"', false);

        $this->assertSame(0, Payment::query()->count());
    }

    public function test_order_sheet_shows_the_looked_up_product_without_creating_a_payment(): void
    {
        $this->includeSampleCheckoutRoutes();
        config(['portone.store_id' => 'store-123', 'portone.channel_key' => 'channel-456']);
        $product = $this->product();

        $this->actingAs(UserFactory::new()->create())
            ->get(route('portone.checkout', ['product' => $product->id]))
            ->assertOk()
            ->assertSee('data-product="'.$product->id.'"', false)
            ->assertSee('딸기 마카롱')
            ->assertSee('1,000 KRW')
            ->assertSee('data-state="initial"', false);

        $this->assertSame(0, Payment::query()->count());
    }

    public function test_prepare_charges_the_product_price_regardless_of_request_values(): void
    {
        $this->includeSampleCheckoutRoutes();
        $product = $this->product();
        $user = UserFactory::new()->create();

        $this->actingAs($user)
            ->postJson(route('portone.checkout.prepare'), [
                'product' => $product->id,
                'amount' => 1,
                'orderName' => 'Untrusted request value',
            ])
            ->assertOk()
            ->assertJson(['orderName' => '딸기 마카롱', 'totalAmount' => 1000]);

        $payment = Payment::query()->sole();
        $this->assertSame('PENDING', $payment->status);
        $this->assertSame(1000, $payment->amount);
        $this->assertSame('딸기 마카롱', $payment->order_name);
    }

    public function test_sample_completion_page_shows_a_paid_order(): void
    {
        $this->includeSampleCheckoutRoutes();
        $user = UserFactory::new()->create();
        $paid = $this->paidPayment($user, 10000);
        $pending = $user->preparePayment(1000, '딸기 마카롱');
        $this->actingAs($user);

        $this->get(route('portone.checkout.completed', ['paymentId' => $paid->payment_id]))
            ->assertOk()
            ->assertSee('data-portone-completed', false)
            ->assertSee('주문이 완료됐어요!')
            ->assertSee('10,000 KRW');
        $this->get(route('portone.checkout.completed', ['paymentId' => $pending->payment_id]))
            ->assertOk()
            ->assertSee('data-failed', false)
            ->assertSee('결제에 실패했어요');
        $this->assertSame('PENDING', $pending->fresh()->status);
    }

    public function test_prepare_rejects_an_unknown_product(): void
    {
        $this->includeSampleCheckoutRoutes();

        $this->actingAs(UserFactory::new()->create())
            ->postJson(route('portone.checkout.prepare'), ['product' => 999])
            ->assertNotFound();

        $this->assertSame(0, Payment::query()->count());
    }

    public function test_payment_history_lists_only_the_signed_in_users_payments_newest_first(): void
    {
        $user = UserFactory::new()->create();
        $older = $user->preparePayment(1000, '딸기 마카롱');
        $this->travel(1)->seconds();
        $newer = $user->preparePayment(3500, '곰돌이 쿠키');
        $other = UserFactory::new()->create()->preparePayment(6000, '조각 케이크');

        $this->actingAs($user);

        $this->get('/shop/payments')
            ->assertOk()
            ->assertSeeInOrder([$newer->payment_id, $older->payment_id])
            ->assertDontSee($other->payment_id);
        $this->get('/shop/payments/'.$other->payment_id)->assertNotFound();
    }

    public function test_partial_refund_from_the_payment_page(): void
    {
        $user = UserFactory::new()->create();
        $payment = $this->paidPayment($user, 10000);
        $gateway = $this->createMock(PaymentGateway::class);
        $gateway->expects($this->once())->method('cancel')->with($payment->payment_id, '단순 변심', 3000);
        $this->app->instance(PaymentGateway::class, $gateway);

        $this->actingAs($user)
            ->post('/shop/payments/'.$payment->payment_id.'/refund', ['reason' => '단순 변심', 'amount' => 3000])
            ->assertRedirect('/shop/payments/'.$payment->payment_id);

        $payment->refresh();
        $this->assertSame('PARTIALLY_CANCELLED', $payment->status);
        $this->assertSame(3000, $payment->cancelled_amount);
    }

    public function test_full_refund_when_the_amount_is_left_empty(): void
    {
        $user = UserFactory::new()->create();
        $payment = $this->paidPayment($user, 10000);
        $gateway = $this->createMock(PaymentGateway::class);
        $gateway->expects($this->once())->method('cancel')->with($payment->payment_id, '단순 변심', null);
        $this->app->instance(PaymentGateway::class, $gateway);

        $this->actingAs($user)
            ->post('/shop/payments/'.$payment->payment_id.'/refund', ['reason' => '단순 변심', 'amount' => ''])
            ->assertRedirect('/shop/payments/'.$payment->payment_id);

        $this->assertSame('CANCELLED', $payment->refresh()->status);
    }

    public function test_partial_cancellations_then_full_cancellation_of_the_remainder(): void
    {
        $user = UserFactory::new()->create();
        $payment = $this->paidPayment($user, 10000);
        $gateway = $this->createMock(PaymentGateway::class);
        $gateway->expects($this->exactly(3))->method('cancel');
        $this->app->instance(PaymentGateway::class, $gateway);
        $this->actingAs($user);
        $refund = '/shop/payments/'.$payment->payment_id.'/refund';

        $this->post($refund, ['reason' => '부분 반품', 'amount' => 3000]);
        $this->post($refund, ['reason' => '부분 반품', 'amount' => 2000]);
        $this->assertSame(['PARTIALLY_CANCELLED', 5000], [$payment->refresh()->status, $payment->cancelled_amount]);

        $this->post($refund, ['reason' => '단순 변심']);
        $this->assertSame(['CANCELLED', 10000], [$payment->refresh()->status, $payment->cancelled_amount]);

        $this->get('/shop/payments/'.$payment->payment_id)->assertOk()->assertSee('전액 취소된 결제예요.');
    }

    public function test_payment_page_offers_the_actions_of_each_status(): void
    {
        $user = UserFactory::new()->create();
        $pending = $user->preparePayment(1000, '딸기 마카롱');
        $paid = $this->paidPayment($user, 10000);
        $this->actingAs($user);

        $this->get('/shop/payments/'.$pending->payment_id)
            ->assertSee('data-complete', false)
            ->assertDontSee('data-cancel-full', false);
        $this->get('/shop/payments/'.$paid->payment_id)
            ->assertSee('data-cancel-full', false)
            ->assertSee('data-cancel-partial', false)
            ->assertDontSee('data-complete', false);
    }

    public function test_pending_payment_is_confirmed_with_portone(): void
    {
        $user = UserFactory::new()->create();
        // Values traced to tools/probes/portone-payment-lookup-observation.json (paidPayment).
        $payment = $user->preparePayment(100, '딸기 마카롱');
        $gateway = $this->createMock(PaymentGateway::class);
        $gateway->expects($this->once())->method('lookup')->with($payment->payment_id)->willReturn(new GatewayPayment('PAID', 100));
        $this->app->instance(PaymentGateway::class, $gateway);

        $this->actingAs($user)
            ->post('/shop/payments/'.$payment->payment_id.'/complete')
            ->assertRedirect('/shop/payments/'.$payment->payment_id);

        $this->assertSame('PAID', $payment->refresh()->status);
    }

    public function test_unpaid_payment_confirmation_returns_with_an_error(): void
    {
        $user = UserFactory::new()->create();
        // Values traced to tools/probes/portone-payment-lookup-observation.json (unpaidPayment).
        $payment = $user->preparePayment(100, '딸기 마카롱');
        $gateway = $this->createMock(PaymentGateway::class);
        $gateway->expects($this->once())->method('lookup')->with($payment->payment_id)->willReturn(new GatewayPayment('FAILED', 100));
        $this->app->instance(PaymentGateway::class, $gateway);

        $this->actingAs($user)
            ->from('/shop/payments/'.$payment->payment_id)
            ->post('/shop/payments/'.$payment->payment_id.'/complete')
            ->assertRedirect('/shop/payments/'.$payment->payment_id)
            ->assertSessionHasErrors('complete');

        $this->assertSame('PENDING', $payment->refresh()->status);
    }

    public function test_customer_page_shows_and_creates_the_portone_customer(): void
    {
        $user = UserFactory::new()->create();
        $this->actingAs($user);

        $this->get('/shop/customer')
            ->assertOk()
            ->assertSee('data-has-portone-id="false"', false)
            ->assertSee('null');

        $this->post('/shop/customer')->assertRedirect('/shop/customer');
        $customerId = $user->portOneId();
        $this->assertNotNull($customerId);

        $this->post('/shop/customer');
        $this->assertSame($customerId, $user->portOneId());

        $this->get('/shop/customer')
            ->assertSee('data-has-portone-id="true"', false)
            ->assertSee($customerId);
    }

    public function test_refund_of_an_unpaid_payment_returns_with_an_error(): void
    {
        $user = UserFactory::new()->create();
        $payment = $user->preparePayment(1000, '딸기 마카롱');
        $gateway = $this->createMock(PaymentGateway::class);
        $gateway->expects($this->never())->method('cancel');
        $this->app->instance(PaymentGateway::class, $gateway);

        $this->actingAs($user)
            ->from('/shop/payments/'.$payment->payment_id)
            ->post('/shop/payments/'.$payment->payment_id.'/refund', ['reason' => '단순 변심'])
            ->assertRedirect('/shop/payments/'.$payment->payment_id)
            ->assertSessionHasErrors('refund');

        $this->assertSame('PENDING', $payment->refresh()->status);
    }

    public function test_verified_webhooks_are_logged_and_listed(): void
    {
        event(new WebhookReceived(
            new WebhookTransaction('Transaction.Paid', new DateTimeImmutable, new WebhookTransactionData('payment-1', 'store-1', 'tx-1')),
            'webhook-1',
        ));

        $log = WebhookLog::query()->sole();
        $this->assertSame(['webhook-1', 'Transaction.Paid', 'payment-1'], [$log->webhook_id, $log->type, $log->payment_id]);

        $this->actingAs(UserFactory::new()->create())
            ->get('/shop/webhooks')
            ->assertOk()
            ->assertSee('data-webhook-log="webhook-1"', false);
    }

    public function test_the_workbench_app_schedules_the_expiry_check_and_pruning(): void
    {
        $events = collect($this->app->make(Schedule::class)->events())
            ->mapWithKeys(fn ($event): array => [(string) $event->command => $event->expression]);

        $this->assertSame('*/10 * * * *', $events->first(fn (string $expression, string $command): bool => str_contains($command, 'portone:expire-pending')));
        $this->assertSame('0 0 * * *', $events->first(fn (string $expression, string $command): bool => str_contains($command, 'model:prune') && str_contains($command, 'Payment')));
    }

    public function test_only_a_paid_webhook_queues_a_completion(): void
    {
        Queue::fake();
        // Webhook types traced to tests/E2E/portone-webhook-observation.json.
        $listener = new QueuePaymentCompletion;

        $listener->handle($this->webhook('Transaction.Paid', 'payment-1'));
        $listener->handle($this->webhook('Transaction.Cancelled', 'payment-2'));

        Queue::assertPushed(CompletePaymentFromWebhook::class, 1);
        Queue::assertPushed(CompletePaymentFromWebhook::class, fn (CompletePaymentFromWebhook $job): bool => $job->paymentId === 'payment-1');
    }

    public function test_webhook_completion_recovers_an_expired_payment(): void
    {
        $user = UserFactory::new()->create();
        // Values traced to tools/probes/portone-abandoned-payment-observation.json (retryAfterFailed: PAID 100).
        $payment = $user->preparePayment(100, '딸기 마카롱');
        $payment->forceFill(['status' => 'EXPIRED'])->save();
        $gateway = $this->createMock(PaymentGateway::class);
        $gateway->expects($this->once())->method('lookup')->with($payment->payment_id)->willReturn(new GatewayPayment('PAID', 100));
        $this->app->instance(PaymentGateway::class, $gateway);

        (new CompletePaymentFromWebhook($payment->payment_id))->handle();
        (new CompletePaymentFromWebhook('unknown-payment'))->handle();

        $this->assertSame('PAID', $payment->refresh()->status);
    }

    public function test_expire_now_button_queues_the_expiry_command(): void
    {
        $queue = Queue::fake();
        config(['portone.pending_expire_after' => 1]);
        $user = UserFactory::new()->create();
        $stale = $user->preparePayment(1000, '딸기 마카롱');
        Payment::query()->whereKey($stale->getKey())->update(['created_at' => now()->subMinutes(2)]);
        $user->preparePayment(3500, '곰돌이 쿠키');

        $this->actingAs($user)->post('/shop/payments/expire')->assertRedirect('/shop/payments');

        // The web request queues the command itself; the queue worker runs it.
        Queue::assertPushed(QueuedCommand::class, 1);
        Queue::assertNotPushed(ExpirePendingPayment::class);

        $queue->pushed(QueuedCommand::class)->first()->handle($this->app->make(Kernel::class));

        Queue::assertPushed(ExpirePendingPayment::class, 1);
        Queue::assertPushed(ExpirePendingPayment::class, fn (ExpirePendingPayment $job): bool => $job->paymentId === $stale->payment_id);
    }

    public function test_expired_payment_is_labelled_and_offers_confirmation(): void
    {
        $user = UserFactory::new()->create();
        $payment = $user->preparePayment(1000, '딸기 마카롱');
        $payment->forceFill(['status' => 'EXPIRED'])->save();
        $this->actingAs($user);

        $this->get('/shop/payments')->assertSee('data-status="EXPIRED"', false)->assertSee('만료');
        $this->get('/shop/payments/'.$payment->payment_id)
            ->assertSee('data-complete', false)
            ->assertDontSee('data-cancel-full', false);
    }

    public function test_phpunit_runs_exercise_only_the_published_checkout_stubs(): void
    {
        $this->assertFalse(Route::has('portone.checkout'));
    }

    public function test_workbench_checkout_copy_differs_from_the_stubs_only_at_the_product_lookup(): void
    {
        $stub = (string) file_get_contents(package_path('stubs/app/Http/Controllers/PortOneCheckoutController.php'));
        $expected = str_replace(
            [
                'namespace App\Http\Controllers;',
                "use Illuminate\Http\Request;\n",
                "        // Replace this sample product with your product from the database,\n"
                ."        // e.g. return Product::findOrFail(\$request->input('product'));\n"
                ."        return (object) ['id' => 1, 'name' => 'Order A', 'price' => 10000];",
            ],
            [
                'namespace Workbench\App\Http\Controllers;',
                "use Illuminate\Http\Request;\nuse Workbench\App\Models\Product;\n",
                "        return Product::findOrFail(\$request->input('product'));",
            ],
            $stub,
        );

        $this->assertNotSame($stub, $expected);
        $this->assertSame($expected, file_get_contents(workbench_path('app/Http/Controllers/PortOneCheckoutController.php')));
        $this->assertSame(
            // Pint orders the imports, so the Workbench controller import follows Route.
            str_replace(
                "use App\\Http\\Controllers\\PortOneCheckoutController;\nuse Illuminate\\Support\\Facades\\Route;",
                "use Illuminate\\Support\\Facades\\Route;\nuse Workbench\\App\\Http\\Controllers\\PortOneCheckoutController;",
                (string) file_get_contents(package_path('stubs/routes/portone.php')),
            ),
            file_get_contents(workbench_path('routes/portone.php')),
        );
        $this->assertFileEquals(package_path('stubs/resources/js/portone-checkout.js'), workbench_path('resources/js/portone-checkout.js'));
    }

    /**
     * Outside PHPUnit the Workbench routes include the sample checkout routes themselves.
     */
    private function includeSampleCheckoutRoutes(): void
    {
        Route::middleware('web')->group(workbench_path('routes/portone.php'));
        Route::getRoutes()->refreshNameLookups();
    }

    private function product(): Product
    {
        return Product::create(['emoji' => '🍓', 'name' => '딸기 마카롱', 'description' => '새콤달콤', 'price' => 1000]);
    }

    private function webhook(string $type, string $paymentId): WebhookReceived
    {
        return new WebhookReceived(
            new WebhookTransaction($type, new DateTimeImmutable, new WebhookTransactionData($paymentId, 'store-1', 'tx-1')),
            'webhook-'.$paymentId,
        );
    }

    private function paidPayment(User $user, int $amount): Payment
    {
        $payment = $user->preparePayment($amount, '디저트 선물 상자');
        $payment->forceFill(['status' => 'PAID', 'paid_at' => now()])->save();

        return $payment;
    }
}
