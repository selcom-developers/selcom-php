<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Tests;

use PHPUnit\Framework\TestCase;
use SelcomDevelopers\Selcom\Client;
use SelcomDevelopers\Selcom\Config;
use SelcomDevelopers\Selcom\Credentials;
use SelcomDevelopers\Selcom\Environment;
use SelcomDevelopers\Selcom\Exception\TransportException;
use SelcomDevelopers\Selcom\Exception\ValidationException;
use SelcomDevelopers\Selcom\Request\OrderStatusRequest;
use SelcomDevelopers\Selcom\Request\UtilityPaymentRequest;
use SelcomDevelopers\Selcom\Request\UtilityQueryRequest;
use SelcomDevelopers\Selcom\Request\VendorBalanceRequest;
use SelcomDevelopers\Selcom\Request\WalletCashinRequest;
use SelcomDevelopers\Selcom\Status;
use SelcomDevelopers\Selcom\Tests\Support\FakeTransport;
use SelcomDevelopers\Selcom\Tests\Support\FixedClock;
use SelcomDevelopers\Selcom\Tests\Support\Fixtures;
use SelcomDevelopers\Selcom\Transport\TransportFailure;
use SelcomDevelopers\Selcom\Transport\TransportResponse;
use SelcomDevelopers\Selcom\Operation;

final class ClientTest extends TestCase
{
    private const SUCCESS = '{"transid":"TXN-UTL-0001","reference":"REF1","resultcode":"000","result":"SUCCESS","message":"ok","data":[{"receipt":"RCPT-1"}]}';

    public function testPostIsSignedAndFlat(): void
    {
        $transport = (new FakeTransport())->queue(new TransportResponse(200, self::SUCCESS));
        $client = new Client(Fixtures::config(), $transport, FixedClock::at('2026-11-21T07:30:46+00:00'));

        $result = $client->utilityPayment(new UtilityPaymentRequest('TXN-UTL-0001', 'LUKU', '01234567891', 8000));

        self::assertSame(Status::Success, $result->status);
        self::assertSame('RCPT-1', $result->receipt());
        self::assertSame('TXN-UTL-0001', $result->transid);

        $sent = $transport->sent[0];
        self::assertSame('POST', $sent->method);
        self::assertSame('https://apigwtest.selcommobile.com/v1/utilitypayment/process', $sent->url);
        self::assertSame(
            '{"transid":"TXN-UTL-0001","utilitycode":"LUKU","utilityref":"01234567891","amount":8000,"vendor":"TILL123","pin":"0000"}',
            $sent->body,
        );
        // The clock said 07:30:46 UTC; the timestamp is generated in Dar es Salaam time, colon form.
        self::assertSame('2026-11-21T10:30:46+03:00', $sent->headers['Timestamp']);
        self::assertSame('transid,utilitycode,utilityref,amount,vendor,pin', $sent->headers['Signed-Fields']);
        self::assertSame('HS256', $sent->headers['Digest-Method']);
        self::assertSame('application/json', $sent->headers['Content-Type']);
        self::assertSame('selcom-php/0.1.0', $sent->headers['User-Agent']);
        self::assertSame(180.0, $sent->timeouts->readSeconds);
        self::assertSame(15.0, $sent->timeouts->connectSeconds);
        self::assertSame('[REDACTED]', $sent->redactedHeaders()['Digest']);
    }

    public function testGetGoesInTheQueryStringWithQueryTimeouts(): void
    {
        $transport = (new FakeTransport())->queue(new TransportResponse(200, self::SUCCESS));
        $client = new Client(Fixtures::config(), $transport, FixedClock::at('2026-11-21T10:30:46+03:00'));

        $client->utilityQuery(new UtilityQueryRequest('TXN-UTL-0001'));

        $sent = $transport->sent[0];
        self::assertSame('GET', $sent->method);
        self::assertSame('https://apigwtest.selcommobile.com/v1/utilitypayment/query?transid=TXN-UTL-0001', $sent->url);
        self::assertNull($sent->body);
        self::assertArrayNotHasKey('Content-Type', $sent->headers);
        self::assertSame(10.0, $sent->timeouts->readSeconds);
    }

    public function testPaymentsAreNeverRetriedEvenWhenNotSentAndRetriesAreOn(): void
    {
        $config = new Config(Environment::Sandbox, Fixtures::credentials(), maxNotSentRetries: 3);
        $transport = (new FakeTransport())
            ->queue(new TransportException(TransportFailure::NotSent, 'dns'))
            ->queue(new TransportResponse(200, self::SUCCESS));
        $client = new Client($config, $transport, FixedClock::at('2026-11-21T10:30:46+03:00'));

        try {
            $client->walletCashin(new WalletCashinRequest('TXN-CSH-0001', 'CASHIN', '255700000002', 2500));
            self::fail('expected a TransportException');
        } catch (TransportException $e) {
            self::assertSame(TransportFailure::NotSent, $e->failure);
        }
        self::assertCount(1, $transport->sent);
    }

    public function testWritesAreNeverRetried(): void
    {
        $config = new Config(Environment::Sandbox, Fixtures::credentials(), maxNotSentRetries: 3);
        foreach ([Operation::CheckoutCancelOrder, Operation::CheckoutDeleteCard, Operation::CheckoutCreateOrderMinimal] as $op) {
            $transport = (new FakeTransport())
                ->queue(new TransportException(TransportFailure::NotSent, 'refused'))
                ->queue(new TransportResponse(200, self::SUCCESS));
            $client = new Client($config, $transport, FixedClock::at('2026-11-21T10:30:46+03:00'));
            try {
                $client->execute(Fixtures::request($op));
                self::fail('expected a TransportException');
            } catch (TransportException) {
                self::assertCount(1, $transport->sent, $op->value);
            }
        }
    }

    public function testUnknownOutcomeIsNeverRetried(): void
    {
        $config = new Config(Environment::Sandbox, Fixtures::credentials(), maxNotSentRetries: 3);
        $transport = (new FakeTransport())
            ->queue(new TransportException(TransportFailure::UnknownOutcome, 'read timeout'))
            ->queue(new TransportResponse(200, self::SUCCESS));
        $client = new Client($config, $transport, FixedClock::at('2026-11-21T10:30:46+03:00'));

        $this->expectException(TransportException::class);
        try {
            $client->orderStatus(new OrderStatusRequest('ORD-1001'));
        } finally {
            self::assertCount(1, $transport->sent);
        }
    }

    public function testReadOnlyOperationRepeatsAfterNotSentOnlyWhenOptedInWithAFreshTimestamp(): void
    {
        $clock = FixedClock::at('2026-11-21T10:30:46+03:00');
        $body = '{"resultcode":"000","data":[{"order_id":"ORD-1001","payment_status":"COMPLETED","amount":"10,000","phone":"255700000001"}]}';

        // Opted out (the default): the error is raised after one send.
        $off = (new FakeTransport())->queue(new TransportException(TransportFailure::NotSent, 'dns'));
        try {
            (new Client(Fixtures::config(), $off, $clock))->orderStatus(new OrderStatusRequest('ORD-1001'));
            self::fail('expected a TransportException');
        } catch (TransportException) {
            self::assertCount(1, $off->sent);
        }

        // Opted in: one repeat, with a new timestamp.
        $on = (new FakeTransport())
            ->queue(new TransportException(TransportFailure::NotSent, 'dns'))
            ->queue(new TransportResponse(200, $body));
        $config = new Config(Environment::Sandbox, Fixtures::credentials(), maxNotSentRetries: 1);
        $on->afterSend = static fn () => $clock->advance(1);
        $client2 = new Client($config, $on, $clock);

        $result = $client2->orderStatus(new OrderStatusRequest('ORD-1001'));
        self::assertSame(Status::Success, $result->status);
        self::assertCount(2, $on->sent);
        self::assertSame('2026-11-21T10:30:46+03:00', $on->sent[0]->headers['Timestamp']);
        self::assertSame('2026-11-21T10:30:47+03:00', $on->sent[1]->headers['Timestamp']);
        self::assertNotSame($on->sent[0]->headers['Digest'], $on->sent[1]->headers['Digest']);

        $order = $result->order();
        self::assertNotNull($order);
        self::assertSame(10000, $order->amount);
        self::assertSame('255700000001', $order->payerPhone);
        self::assertSame(Status::Success, $order->status);
    }

    public function testPayoutWithoutPinFailsBeforeAnythingIsSent(): void
    {
        $config = new Config(Environment::Sandbox, new Credentials('k', 's', 'TILL123'));
        $transport = new FakeTransport();
        $client = new Client($config, $transport, FixedClock::at('2026-11-21T10:30:46+03:00'));

        $this->expectException(ValidationException::class);
        try {
            $client->vendorBalance(new VendorBalanceRequest('TXN-BAL-0001'));
        } finally {
            self::assertSame([], $transport->sent);
        }
    }

    public function testCheckoutUsesTheCheckoutCredentialsWhenConfigured(): void
    {
        $config = new Config(
            Environment::Production,
            Fixtures::credentials(),
            new Credentials('checkout-key', 'checkout-secret', 'CHECKOUTTILL'),
        );
        $transport = (new FakeTransport())->queue(new TransportResponse(200, '{"resultcode":"000","data":[{"payment_status":"PENDING"}]}'));
        $client = new Client($config, $transport, FixedClock::at('2026-11-21T10:30:46+03:00'));

        $client->orderStatus(new OrderStatusRequest('ORD-1001'));

        self::assertSame('SELCOM ' . base64_encode('checkout-key'), $transport->sent[0]->headers['Authorization']);
        self::assertStringStartsWith('https://apigw.selcommobile.com/', $transport->sent[0]->url);
    }

    public function testTypedAccessors(): void
    {
        $transport = (new FakeTransport())->queue(new TransportResponse(200, '{"resultcode":"000","data":[{"balance":"1000000"}]}'));
        $client = new Client(Fixtures::config(), $transport, FixedClock::at('2026-11-21T10:30:46+03:00'));
        self::assertSame(1000000, $client->vendorBalance(new VendorBalanceRequest('TXN-BAL-0001'))->balance());

        $transport = (new FakeTransport())->queue(new TransportResponse(200, '{"resultcode":"000","data":[{"gateway_buyer_uuid":"GBU-1","payment_token":"8","qr":"Q","payment_gateway_url":"aHR0cHM6Ly9jaGVja291dC5leGFtcGxlLmNvbS9wYXkvT1JELTEwMDU="}]}'));
        $client = new Client(Fixtures::config(), $transport, FixedClock::at('2026-11-21T10:30:46+03:00'));
        $order = $client->createOrder(Fixtures::createOrder())->order();
        self::assertNotNull($order);
        self::assertSame('https://checkout.example.com/pay/ORD-1005', $order->paymentGatewayUrl);
        self::assertSame('aHR0cHM6Ly9jaGVja291dC5leGFtcGxlLmNvbS9wYXkvT1JELTEwMDU=', $order->paymentGatewayUrlRaw);

        // The webhook URL travels as base64 text, and that text is what is signed.
        $sentBody = $transport->sent[0]->body;
        self::assertIsString($sentBody);
        self::assertStringContainsString('"webhook":"' . base64_encode('https://shop.example.com/selcom/webhook/token') . '"', $sentBody);
        self::assertStringContainsString('"billing.firstname":"Test"', $sentBody);
        self::assertStringNotContainsString('"billing":{', $sentBody);
    }
}
