<?php

declare(strict_types=1);

namespace Tests\Unit;

use Cable8mm\LaravelPortone\PaymentResponseRedactor;
use Cable8mm\LaravelPortone\PortOneGateway;
use Cable8mm\LaravelPortone\PortOneServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;

/**
 * T-030: the raw PortOne payment response and the portone:payment command.
 *
 * The response follows the documented PortOne V2 payment shape (customer, method, cashReceipt);
 * the live response of a paid test payment is recorded by tests/E2E/PortOnePaymentCommandTest.
 */
class ShowPaymentCommandTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PortOneServiceProvider::class];
    }

    public function test_command_prints_the_response_with_personal_card_and_account_data_redacted(): void
    {
        $this->fakeGateway();

        $this->assertSame(0, Artisan::call('portone:payment', ['paymentId' => 'payment-1']));
        $printed = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $expected = self::response();
        $expected['customer'] = [
            'id' => 'customer-1',
            'name' => '[REDACTED]',
            'birthYear' => '[REDACTED]',
            'gender' => '[REDACTED]',
            'email' => '[REDACTED]',
            'phoneNumber' => '[REDACTED]',
            'address' => '[REDACTED]',
            'zipcode' => '[REDACTED]',
        ];
        $expected['method']['card']['name'] = '[REDACTED]';
        $expected['method']['card']['number'] = '[REDACTED]';
        $expected['method']['card']['bin'] = '[REDACTED]';
        $expected['method']['approvalNumber'] = '[REDACTED]';
        $expected['cashReceipt']['customerIdentityNumber'] = '[REDACTED]';
        $expected['virtualAccount']['accountNumber'] = '[REDACTED]';
        $expected['virtualAccount']['remitteeName'] = '[REDACTED]';
        $expected['origin']['userAgent'] = '[REDACTED]';
        $expected['origin']['ipAddress'] = '[REDACTED]';
        $expected['pgResponse'] = '[REDACTED]';
        $expected['receiptUrl'] = '[REDACTED]';
        $expected['webhooks'] = '[REDACTED]';
        $this->assertSame($expected, $printed);
    }

    public function test_command_prints_the_response_unchanged_with_raw(): void
    {
        $this->fakeGateway();

        $this->assertSame(0, Artisan::call('portone:payment', ['paymentId' => 'payment-1', '--raw' => true]));

        $this->assertSame(self::response(), json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_redactor_keeps_every_key_and_leaves_the_input_untouched(): void
    {
        $response = self::response();

        $redacted = (new PaymentResponseRedactor)->redact($response);

        $this->assertSame(self::response(), $response);
        $this->assertSame(array_keys($response), array_keys($redacted));
        $this->assertSame('PAID', $redacted['status']);
        $this->assertSame(['total' => 100, 'paid' => 100], $redacted['amount']);
    }

    private function fakeGateway(): void
    {
        $this->app->instance(PortOneGateway::class, new class extends PortOneGateway
        {
            public function paymentResponse(string $paymentId): array
            {
                return ShowPaymentCommandTest::response() + ['id' => $paymentId];
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public static function response(): array
    {
        return [
            'status' => 'PAID',
            'id' => 'payment-1',
            'orderName' => 'Order A',
            'amount' => ['total' => 100, 'paid' => 100],
            'customer' => [
                'id' => 'customer-1',
                'name' => 'Hong Gildong',
                'birthYear' => '1990',
                'gender' => 'MALE',
                'email' => 'buyer@example.test',
                'phoneNumber' => '01000000000',
                'address' => ['type' => 'ONE_LINE', 'oneLine' => 'Seoul'],
                'zipcode' => '00000',
            ],
            'method' => [
                'type' => 'PaymentMethodCard',
                'card' => ['issuer' => 'KCP', 'name' => 'Test card', 'number' => '12345678****000*', 'bin' => '123456'],
                'approvalNumber' => '00000000',
            ],
            'cashReceipt' => ['type' => 'PERSONAL', 'customerIdentityNumber' => '01000000000'],
            'virtualAccount' => ['bank' => 'KOOKMIN', 'accountNumber' => '00000000000000', 'remitteeName' => 'Shop'],
            // Shapes of the live response recorded by tests/E2E/portone-payment-command-observation.json.
            'origin' => ['platformType' => 'PC', 'userAgent' => 'Mozilla/5.0', 'url' => 'http://127.0.0.1:8001', 'ipAddress' => '192.0.2.1'],
            'pgResponse' => '{"card_no":"000000000000000","app_no":"00000000"}',
            'receiptUrl' => 'https://receipt.example.test/bill?tno=1',
            'webhooks' => [['paymentStatus' => 'PAID', 'url' => 'https://webhook.example.test/portone/webhook']],
        ];
    }
}
