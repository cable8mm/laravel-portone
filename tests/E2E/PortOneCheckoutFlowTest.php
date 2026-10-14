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

    private const COMPLETED = '[data-portone-completed]';

    public function test_clicking_checkout_and_completing_live_payment_reaches_the_completion_page(): void
    {
        $user = UserFactory::new()->create();

        $this->browse(function (Browser $browser) use ($user): void {
            $requests = $this->runCheckout(
                $browser,
                $user,
                'Complete the 10,000 KRW PortOne test payment in the browser window.',
            );

            $payment = Payment::query()->sole();

            // Success navigates to the completion page of the paid payment (FR-055, FR-066).
            $browser->assertPathIs(route('portone.checkout.completed', ['paymentId' => $payment->payment_id], false));
            self::assertSame('PAID', $browser->attribute(self::COMPLETED, 'data-status'));
            $browser->assertSeeIn(self::COMPLETED, 'Order A')
                ->assertSeeIn(self::COMPLETED, '10,000 KRW')
                ->assertSeeIn(self::COMPLETED, $payment->payment_id);

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
            self::assertSame(
                ['status' => 'PAID', 'redirectUrl' => static::applicationBaseUrl().route('portone.checkout.completed', ['paymentId' => $payment->payment_id], false)],
                json_decode((string) $requests[1]['response'], true, 512, JSON_THROW_ON_ERROR),
            );

            $this->recordScenario('completedPayment', [
                'paymentId' => $payment->payment_id,
                'totalAmount' => $payment->amount,
                'browserState' => 'success, then navigated to the completion page',
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
     * The script's requests are kept in sessionStorage so they survive the navigation to the completion page.
     *
     * @return list<array{url: string, body: string|null, status: int|null, response: string|null}> the requests the published script sent
     */
    private function runCheckout(Browser $browser, User $user, string $instruction): array
    {
        $browser->loginAs($user)
            ->visit(route('portone.checkout', absolute: false))
            ->waitFor(self::CHECKOUT.'[data-state="initial"]');
        self::assertSame('Idle', $browser->text(self::STATUS));

        $browser->script(<<<'JS'
            sessionStorage.setItem("checkoutRequests", "[]");
            const send = window.fetch;
            window.fetch = async (url, options) => {
                // The PortOne SDK also calls fetch; record only requests to this app.
                if (new URL(String(url), location.href).origin !== location.origin) {
                    return send(url, options);
                }

                const response = await send(url, options);
                const requests = JSON.parse(sessionStorage.getItem("checkoutRequests"));
                requests.push({ url: String(url), body: options?.body ?? null, status: response.status, response: await response.clone().text() });
                sessionStorage.setItem("checkoutRequests", JSON.stringify(requests));
                return response;
            };
        JS);

        $browser->click(self::BUTTON)->waitFor(self::CHECKOUT.'[data-state="loading"]');
        self::assertSame('Loading', $browser->text(self::STATUS));
        $browser->assertButtonDisabled(self::BUTTON);

        $this->instruct($instruction);
        // Success leaves the page, so wait for the completion page or the Error state.
        $browser->waitFor(self::COMPLETED.', '.self::CHECKOUT.'[data-state="error"]', self::PAYER_TIMEOUT);

        /** @var list<array{url: string, body: string|null, status: int|null, response: string|null}> $requests */
        $requests = $browser->script('return JSON.parse(sessionStorage.getItem("checkoutRequests"));')[0];

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
