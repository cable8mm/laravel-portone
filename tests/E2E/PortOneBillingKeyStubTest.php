<?php

declare(strict_types=1);

namespace Tests\E2E;

use Cable8mm\LaravelPortone\BillingKeyRegistry;
use Cable8mm\LaravelPortone\Models\BillingKey;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use Workbench\App\Models\User;
use Workbench\Database\Factories\UserFactory;

/**
 * T-048: the published billing-key Blade page (EL-092, EL-093) registers a card through requestIssueBillingKey and
 * the redirect return reaches the Issued state; a second issuance whose KCP window the human closes reaches the
 * Failed state without touching the record (FR-155 to FR-160, AC-112). No webhook is needed: the completed action
 * looks the billing key up with PortOne itself (EL-073).
 */
class PortOneBillingKeyStubTest extends TestCase
{
    private const OBSERVATION = 'portone-billing-key-stub-observation.json';

    private const PAGE = '[data-portone-billing-key]';

    private const BUTTON = '#portone-billing-key-button';

    private const STATUS = '#portone-billing-key-status';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // EL-057 billing_channel_key (PORTONE_BILLING_CHANNEL_KEY); when it is unset the live test uses the KCP
        // test channel that issued billing keys in T-045 (PORTONE_E2E_CHANNEL_KEY).
        $app['config']->set('portone.billing_channel_key', self::env('PORTONE_BILLING_CHANNEL_KEY') ?? self::env('PORTONE_E2E_CHANNEL_KEY'));
    }

    public function test_registering_a_card_through_the_published_page_reaches_the_issued_state(): void
    {
        $user = UserFactory::new()->create();

        $this->browse(function (Browser $browser) use ($user): void {
            $run = $this->runIssuance($browser, $user, 'Register a test card in the KCP window and finish the registration.');

            $record = $user->billingKeys()->sole();
            self::assertSame('issued', $run['state'], 'The published page did not reach the Issued state; redirect '.json_encode($run['redirect'], JSON_UNESCAPED_UNICODE));
            $browser->assertPathIs(route('portone.billing-key.completed', ['issueId' => $record->issue_id], false));
            self::assertSame('Issued', $browser->text(self::STATUS));
            self::assertSame($record->issue_id, $browser->attribute(self::PAGE, 'data-issue-id'));
            self::assertSame('ISSUED', $record->status);
            self::assertNotNull($record->issued_at);

            // The plain billing key appears in no page, record, log, or recording (FR-130, FR-160).
            $plainKey = $this->app->make(BillingKeyRegistry::class)->plainKey($record);
            self::assertIsString($plainKey);
            self::assertStringNotContainsString($plainKey, (string) $browser->driver->getPageSource());
            self::assertStringNotContainsString($plainKey, (string) json_encode(DB::table('portone_billing_keys')->get()));
            self::assertStringNotContainsString($plainKey, (string) json_encode($record->toArray()));
            self::assertStringNotContainsString($plainKey, (string) json_encode($run['requests']));

            foreach (glob($this->app->storagePath('logs/*.log')) ?: [] as $log) {
                self::assertStringNotContainsString($plainKey, (string) file_get_contents($log));
            }

            // Leave no live billing key behind (EL-075).
            $deleted = $user->deleteBillingKey($record->issue_id, 'T-048 billing-key stub cleanup');
            self::assertSame('DELETED', $deleted->status);

            $observation = $this->scenario($run, $record) + ['recordStatusAfterCleanup' => $deleted->status];
            self::assertStringNotContainsString($plainKey, (string) json_encode($observation));
            $this->record('issued', $observation);
        });
    }

    public function test_closing_the_kcp_window_reaches_the_failed_state_without_touching_the_record(): void
    {
        $user = UserFactory::new()->create();

        $this->browse(function (Browser $browser) use ($user): void {
            $run = $this->runIssuance(
                $browser,
                $user,
                'Close the KCP card-registration window WITHOUT registering a card. If the shop page stays on "Loading" with no redirect, use the browser Back button.',
            );

            $record = $user->billingKeys()->sole();
            $this->record('closedWindow', $this->scenario($run, $record));

            self::assertNotSame('error', $run['state'], 'Closing the KCP window gave no redirect return, so the Error state was reached instead of Failed.');
            self::assertSame('failed', $run['state']);
            self::assertNotSame('3017', $run['redirect']['pgCode'] ?? null, 'KCP reported a blocked popup ([3017]), not a closed window.');
            $browser->assertPathIs(route('portone.billing-key.completed', ['issueId' => $record->issue_id], false));
            self::assertSame('Failed', $browser->text(self::STATUS));

            // A failure redirect touches no record (FR-159).
            self::assertSame('PENDING', $record->status);
            self::assertNull($record->billing_key);
        });
    }

    /**
     * Click the register button as the Payer and wait for the human to finish in the KCP window.
     *
     * @return array{state: string, redirect: array<string, mixed>, requests: list<array<string, mixed>>}
     */
    private function runIssuance(Browser $browser, User $user, string $instruction): array
    {
        $browser->loginAs($user)
            ->visit(route('portone.billing-key', absolute: false))
            ->waitFor(self::PAGE.'[data-state="initial"]');
        self::assertSame('Idle', $browser->text(self::STATUS));
        $browser->assertButtonEnabled(self::BUTTON);

        // Keep the script's requests (keys of the prepare response only) across the redirect.
        $browser->script(<<<'JS'
            sessionStorage.setItem("billingKeyRequests", "[]");
            const send = window.fetch;
            window.fetch = async (url, options) => {
                if (new URL(String(url), location.href).origin !== location.origin) {
                    return send(url, options);
                }

                const response = await send(url, options);
                let responseKeys = null;
                try { responseKeys = Object.keys(await response.clone().json()); } catch {}
                const requests = JSON.parse(sessionStorage.getItem("billingKeyRequests"));
                requests.push({ url: String(url), body: options?.body ?? null, status: response.status, responseKeys });
                sessionStorage.setItem("billingKeyRequests", JSON.stringify(requests));
                return response;
            };
        JS);

        $browser->click(self::BUTTON)->waitFor(self::PAGE.'[data-state="loading"]');
        self::assertSame('Loading', $browser->text(self::STATUS));
        $browser->assertButtonDisabled(self::BUTTON);

        $this->instruct($instruction);
        $browser->waitFor(
            self::PAGE.'[data-state="issued"], '.self::PAGE.'[data-state="failed"], '.self::PAGE.'[data-state="error"]',
            self::PAYER_TIMEOUT,
        );

        parse_str((string) parse_url($browser->driver->getCurrentURL(), PHP_URL_QUERY), $query);
        /** @var list<array<string, mixed>> $requests */
        $requests = $browser->script('return JSON.parse(sessionStorage.getItem("billingKeyRequests") ?? "[]");')[0] ?? [];

        self::assertCount(1, $requests);
        self::assertSame(static::applicationBaseUrl().route('portone.billing-key.prepare', absolute: false), $requests[0]['url']);
        self::assertSame(200, $requests[0]['status']);

        return [
            'state' => (string) $browser->attribute(self::PAGE, 'data-state'),
            // The redirect query without the billing key, which is never recorded.
            'redirect' => ['keys' => array_keys($query)] + array_intersect_key($query, array_flip(['code', 'message', 'pgCode', 'pgMessage'])),
            'requests' => $requests,
        ];
    }

    /**
     * @param  array{state: string, redirect: array<string, mixed>, requests: list<array<string, mixed>>}  $run
     * @return array<string, mixed>
     */
    private function scenario(array $run, BillingKey $record): array
    {
        return [
            'issueId' => $record->issue_id,
            'pageState' => $run['state'],
            'redirectQuery' => $run['redirect'],
            'prepareResponseKeys' => $run['requests'][0]['responseKeys'] ?? null,
            'recordStatus' => $record->status,
        ];
    }

    /**
     * @param  array<string, mixed>  $observation
     */
    private function record(string $scenario, array $observation): void
    {
        $this->recordObservation(self::OBSERVATION, [
            'recordedAt' => gmdate(DATE_ATOM),
            'channel' => 'NHN KCP test (PORTONE_BILLING_CHANNEL_KEY, or PORTONE_E2E_CHANNEL_KEY when unset)',
            'publishedStubs' => true,
            'secretsRecorded' => false,
            'scenarios' => [$scenario => $observation],
        ]);
    }

    private static function env(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
