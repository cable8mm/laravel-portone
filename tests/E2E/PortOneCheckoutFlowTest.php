<?php

declare(strict_types=1);

namespace Tests\E2E;

use Cable8mm\LaravelPortone\Models\Payment;
use Laravel\Dusk\Browser;
use Workbench\App\Models\User;
use Workbench\Database\Factories\UserFactory;

class PortOneCheckoutFlowTest extends TestCase
{
    private const CHECKOUT = '[data-portone-checkout]';

    private const BUTTON = '#portone-checkout-button';

    private const STATUS = '#portone-checkout-status';

    public function test_clicking_checkout_and_completing_live_payment_reaches_success(): void
    {
        $user = UserFactory::new()->create();

        $this->browse(function (Browser $browser) use ($user): void {
            $requests = $this->runCheckout(
                $browser,
                $user,
                'Complete the 10,000 KRW PortOne test payment in the browser window.',
            );

            self::assertSame('success', $browser->attribute(self::CHECKOUT, 'data-state'));
            self::assertSame('Success', $browser->text(self::STATUS));
            $browser->assertButtonDisabled(self::BUTTON);

            $payment = Payment::query()->sole();
            self::assertSame($payment->payment_id, $user->findPayment($payment->payment_id)?->payment_id);
            self::assertSame(10000, $payment->amount);
            self::assertSame('PAID', $payment->status);
            self::assertNotNull($payment->paid_at);

            self::assertCount(2, $requests);
            self::assertSame($this->servedUrl('portone.checkout.prepare'), $requests[0]['url']);
            self::assertSame($this->servedUrl('portone.checkout.complete'), $requests[1]['url']);
            self::assertSame(
                ['paymentId' => $payment->payment_id],
                json_decode((string) $requests[1]['body'], true, 512, JSON_THROW_ON_ERROR),
            );
            self::assertSame(200, $requests[1]['status']);

            $this->recordScenario('completedPayment', [
                'paymentId' => $payment->payment_id,
                'totalAmount' => $payment->amount,
                'browserState' => 'success',
                'browserButtonDisabled' => true,
                'sdkOutcome' => 'returned without an error code',
                'completeRouteStatus' => $requests[1]['status'],
                'paymentStatus' => $payment->status,
            ]);
        });
    }

    public function test_closing_live_payment_window_returns_to_error_without_completion(): void
    {
        $user = UserFactory::new()->create();

        $this->browse(function (Browser $browser) use ($user): void {
            $requests = $this->runCheckout(
                $browser,
                $user,
                'Close the PortOne payment window in the browser WITHOUT completing the payment.',
            );

            self::assertSame('error', $browser->attribute(self::CHECKOUT, 'data-state'));
            self::assertSame('Error', $browser->text(self::STATUS));
            $browser->assertButtonEnabled(self::BUTTON);

            self::assertCount(1, $requests);
            self::assertSame($this->servedUrl('portone.checkout.prepare'), $requests[0]['url']);

            $payment = Payment::query()->sole();
            self::assertSame($payment->payment_id, $user->findPayment($payment->payment_id)?->payment_id);
            self::assertSame('PENDING', $payment->status);

            $this->recordScenario('cancelledPayment', [
                'paymentId' => $payment->payment_id,
                'totalAmount' => $payment->amount,
                'browserState' => 'error',
                'browserButtonDisabled' => false,
                'sdkOutcome' => 'returned an error code',
                'completeRouteRequested' => false,
                'paymentStatus' => $payment->status,
            ]);
        });
    }

    /**
     * Click EL-026 as the Payer and wait for the human to finish in the PG window.
     *
     * @return list<array{url: string, body: string|null, status: int|null}> the requests the published script sent
     */
    private function runCheckout(Browser $browser, User $user, string $instruction): array
    {
        $browser->loginAs($user)
            ->visit(route('portone.checkout', absolute: false))
            ->waitFor(self::CHECKOUT.'[data-state="initial"]');
        self::assertSame('Idle', $browser->text(self::STATUS));

        $browser->script(<<<'JS'
            window.__checkoutRequests = [];
            const send = window.fetch;
            window.fetch = async (url, options) => {
                // The PortOne SDK also calls fetch; record only requests to this app.
                if (new URL(String(url), location.href).origin !== location.origin) {
                    return send(url, options);
                }

                const entry = { url: String(url), body: options?.body ?? null, status: null };
                window.__checkoutRequests.push(entry);
                const response = await send(url, options);
                entry.status = response.status;
                return response;
            };
        JS);

        $browser->click(self::BUTTON)->waitFor(self::CHECKOUT.'[data-state="loading"]');
        self::assertSame('Loading', $browser->text(self::STATUS));
        $browser->assertButtonDisabled(self::BUTTON);

        $this->instruct($instruction);
        $browser->waitFor(
            self::CHECKOUT.'[data-state="success"], '.self::CHECKOUT.'[data-state="error"]',
            self::PAYER_TIMEOUT,
        );

        /** @var list<array{url: string, body: string|null, status: int|null}> $requests */
        $requests = $browser->script('return window.__checkoutRequests;')[0];

        return $requests;
    }

    /**
     * The route's URL on the Dusk-served app, which is where the browser sends requests.
     */
    private function servedUrl(string $route): string
    {
        return static::applicationBaseUrl().route($route, absolute: false);
    }

    /**
     * @param  array<string, mixed>  $observation
     */
    private function recordScenario(string $scenario, array $observation): void
    {
        $this->recordObservation('portone-checkout-flow-observation.json', [
            'recordedAt' => gmdate(DATE_ATOM),
            'publishedStubs' => true,
            'scenarios' => [$scenario => $observation],
        ]);
    }
}
