<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SelcomDevelopers\Selcom\Client;
use SelcomDevelopers\Selcom\Currency;
use SelcomDevelopers\Selcom\Exception\InvalidResponseException;
use SelcomDevelopers\Selcom\Exception\ValidationException;
use SelcomDevelopers\Selcom\OperationKind;
use SelcomDevelopers\Selcom\Redaction\Redactor;
use SelcomDevelopers\Selcom\Request\CreateOrderMinimalRequest;
use SelcomDevelopers\Selcom\Request\UtilityPaymentRequest;
use SelcomDevelopers\Selcom\Request\WalletCashinRequest;
use SelcomDevelopers\Selcom\Result\ResponseClassifier;
use SelcomDevelopers\Selcom\Signing\Hs256DigestSigner;
use SelcomDevelopers\Selcom\Status;
use SelcomDevelopers\Selcom\Tests\Support\FakeTransport;
use SelcomDevelopers\Selcom\Tests\Support\FixedClock;
use SelcomDevelopers\Selcom\Tests\Support\Fixtures;
use SelcomDevelopers\Selcom\Transport\TimeoutProfile;
use SelcomDevelopers\Selcom\Transport\TransportRequest;
use SelcomDevelopers\Selcom\Transport\TransportResponse;
use SelcomDevelopers\Selcom\Webhook\InMemoryReplayStore;
use SelcomDevelopers\Selcom\Webhook\WebhookPayload;
use SelcomDevelopers\Selcom\Webhook\WebhookVerdict;
use SelcomDevelopers\Selcom\Webhook\WebhookVerifier;

final class InputAndOutputTest extends TestCase
{
    // ---- amounts -------------------------------------------------------------------------

    /** @return iterable<string, array{mixed, int}> */
    public static function goodAmounts(): iterable
    {
        yield 'int' => [8000, 8000];
        yield 'digit string' => ['8000', 8000];
        yield 'integral string' => ['8000.00', 8000];
        yield 'integral float' => [8000.0, 8000];
        yield 'largest' => [9007199254740991, 9007199254740991];
    }

    #[DataProvider('goodAmounts')]
    public function testWholeAmountsAreAccepted(mixed $given, int $expected): void
    {
        $request = new UtilityPaymentRequest('TXN-1', 'LUKU', 'ref', $given);
        self::assertSame($expected, $request->amount);
    }

    /** @return iterable<string, array{mixed}> */
    public static function badAmounts(): iterable
    {
        yield 'fractional float' => [8000.9];
        yield 'small fraction' => [0.5];
        yield 'fractional string' => ['8000.5'];
        yield 'comma string' => ['8,000'];
        yield 'exponent string' => ['8e3'];
        yield 'text' => ['abc'];
        yield 'empty' => [''];
        yield 'negative' => [-1];
        yield 'zero' => [0];
        yield 'bool' => [true];
        yield 'null' => [null];
        yield 'array' => [[8000]];
        yield 'infinite' => [INF];
        yield 'not a number' => [NAN];
        yield 'beyond 2^53-1 int' => [9007199254740992];
        yield 'beyond 2^53-1 float' => [1.0e16];
        yield 'beyond 2^53-1 string' => ['9007199254740992'];
        yield 'long string' => ['99999999999999999999'];
    }

    #[DataProvider('badAmounts')]
    public function testOtherAmountsAreRefusedNeverTruncated(mixed $given): void
    {
        foreach ([
            static fn () => new UtilityPaymentRequest('TXN-1', 'LUKU', 'ref', $given),
            static fn () => new WalletCashinRequest('TXN-1', 'CASHIN', '255700000002', $given),
            static fn () => new CreateOrderMinimalRequest('O', 'e@example.com', 'n', '255700000001', $given, Currency::TZS, 1),
        ] as $build) {
            try {
                $build();
                self::fail('the amount must be refused');
            } catch (ValidationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    // ---- masked output -------------------------------------------------------------------

    public function testObjectsShowNoSecretsInAnyPrintedForm(): void
    {
        $secrets = ['test-secret-0001', 'test-key-0001', '0000'];
        $objects = [
            Fixtures::credentials(),
            Fixtures::config(),
            new Hs256DigestSigner('test-secret-0001'),
            new WebhookVerifier('test-secret-0001'),
            new Client(Fixtures::config(), new FakeTransport()),
        ];
        foreach ($objects as $object) {
            ob_start();
            var_dump($object);
            $out = (string) ob_get_clean();
            $out .= print_r($object, true) . var_export($object, true) . print_r((array) $object, true);
            foreach ($secrets as $secret) {
                self::assertStringNotContainsString($secret, $out, $object::class);
            }
        }
        self::assertSame('****0001', Fixtures::credentials()->maskedApiKey());
    }

    public function testExceptionsAndSignedRequestsShowNoSecrets(): void
    {
        $transport = (new FakeTransport())->queue(new TransportResponse(502, '<html>255700000001 test-key-0001</html>'));
        $client = new Client(Fixtures::config(), $transport, FixedClock::at('2026-11-21T10:30:46+03:00'));
        try {
            $client->vendorBalance(new \SelcomDevelopers\Selcom\Request\VendorBalanceRequest('TXN-BAL-0001'));
            self::fail('expected InvalidResponseException');
        } catch (InvalidResponseException $e) {
            $text = print_r($e, true) . $e->getMessage();
            self::assertStringNotContainsString('255700000001', $text);
            self::assertStringContainsString('255700000001', $e->rawBody());
        }
    }

    public function testTransportRequestAndResultPrintMaskedData(): void
    {
        $request = new TransportRequest(
            'POST',
            'https://apigwtest.selcommobile.com/v1/walletcashin/process',
            ['Authorization' => 'SELCOM abc', 'Digest' => 'xyz=', 'Accept' => 'application/json'],
            '{"transid":"TXN-1","utilitycode":"CASHIN","utilityref":"255700000002","pin":"0000","msisdn":"255700000001"}',
            new TimeoutProfile(15.0, 180.0, true),
        );
        $out = print_r($request, true);
        foreach (['SELCOM abc', 'xyz=', '"0000"', '255700000002', '255700000001'] as $value) {
            self::assertStringNotContainsString($value, $out);
        }
        self::assertStringContainsString('********0002', (string) $request->redactedBody());
        self::assertStringContainsString('"pin":"[REDACTED]"', (string) $request->redactedBody());

        $lookup = new TransportRequest(
            'GET',
            'https://apigwtest.selcommobile.com/v1/walletcashin/namelookup?utilitycode=VMCASHIN&utilityref=255700000002&transid=TXN-1',
            [],
            null,
            new TimeoutProfile(10.0, 10.0),
        );
        self::assertStringNotContainsString('255700000002', $lookup->redactedUrl());

        $transport = (new FakeTransport())->queue(new TransportResponse(200, '{"resultcode":"000","data":[{"name":"Test Buyer","phone":"255700000001","card_token":"CARDTOKEN-TEST-0001"}]}'));
        $client = new Client(Fixtures::config(), $transport, FixedClock::at('2026-11-21T10:30:46+03:00'));
        $result = $client->execute(new \SelcomDevelopers\Selcom\Request\UtilityLookupRequest('LUKU', '01234567891', 'TXN-1'));
        $printed = print_r($result, true);
        foreach (['Test Buyer', '255700000001', 'CARDTOKEN-TEST-0001'] as $value) {
            self::assertStringNotContainsString($value, $printed);
        }
    }

    public function testRedactorMasksUtilityRefOnAnyWalletCashinPath(): void
    {
        $r = new Redactor();
        self::assertSame('********0002', $r->redactParams(['utilityref' => '255700000002'], '/v1/walletcashin/query')['utilityref']);
        self::assertSame('01234567891', $r->redactParams(['utilityref' => '01234567891'], '/v1/utilitypayment/lookup')['utilityref']);
        self::assertStringContainsString('%2A%2A%2A%2A%2A%2A%2A%2A0002', $r->redactQuery('utilityref=255700000002', '/v1/walletcashin/namelookup'));
    }

    public function testWebhookPayloadPrintsMaskedData(): void
    {
        $payload = WebhookPayload::fromRawBody('{"order_id":"ORD-1","transid":"TXN-1","amount":"10,000","phone":"255700000001"}');
        self::assertStringNotContainsString('255700000001', print_r($payload, true));
        self::assertSame(10000, $payload->amountTzs);
    }

    public function testWebhookPayloadDoesNotThrowOnAnUnusableAmount(): void
    {
        foreach (['"amount":"ten"', '"amount":1e999', '"amount":[1]', '"amount":{"a":1}', '"amount":-5', '"amount":true'] as $field) {
            $payload = WebhookPayload::fromRawBody('{"order_id":"ORD-1",' . $field . '}');
            self::assertNull($payload->amountTzs, $field);
        }
    }

    // ---- order status shape ----------------------------------------------------------------

    public function testOrderStatusDataShape(): void
    {
        $kind = OperationKind::OrderStatus;
        self::assertSame(Status::NotFound, ResponseClassifier::classify($kind, 200, '{"resultcode":"000","data":[]}')[0]);
        self::assertSame(Status::Ambiguous, ResponseClassifier::classify($kind, 200, '{"resultcode":"000","data":{}}')[0]);
        self::assertSame(Status::Ambiguous, ResponseClassifier::classify($kind, 200, '{"resultcode":"000","data":null}')[0]);
        self::assertSame(Status::Ambiguous, ResponseClassifier::classify($kind, 200, '{"resultcode":"000","data":"x"}')[0]);
        self::assertSame(Status::Success, ResponseClassifier::classify($kind, 200, '{"resultcode":"000","data":[{"payment_status":"COMPLETED"}]}')[0]);
    }

    // ---- webhook robustness ----------------------------------------------------------------

    /**
     * @param array<string, bool|int|float|string> $fields
     * @return array{array<string, string>, string}
     */
    private static function signedDelivery(array $fields, string $names = 'transid,order_id,reference', string $at = '2026-11-21T10:30:46+03:00'): array
    {
        $body = json_encode($fields, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        $flat = [];
        foreach (explode(',', $names) as $name) {
            $value = $fields[$name];
            $flat[] = [$name, is_float($value) ? (int) $value : $value];
        }
        $digest = (new Hs256DigestSigner('test-secret-0001'))->sign(\SelcomDevelopers\Selcom\Signing\RequestSigner::signingString($at, $flat));

        return [
            ['Digest' => $digest, 'Digest-Method' => 'HS256', 'Timestamp' => $at, 'Signed-Fields' => $names],
            $body,
        ];
    }

    public function testWebhookWithUnusableNumbersIsInvalidAndNeverThrows(): void
    {
        $verifier = new WebhookVerifier('test-secret-0001', FixedClock::at('2026-11-21T10:31:00+03:00'));
        $headers = [
            'Digest' => 'abc=',
            'Digest-Method' => 'HS256',
            'Timestamp' => '2026-11-21T10:30:46+03:00',
            'Signed-Fields' => 'transid,order_id,reference',
        ];
        foreach (['1e100000000', '1e-999999999', '-1e100000000', '12345678901234567890123', '1.5', '0.0', '1e400'] as $number) {
            $body = '{"transid":"T","order_id":"O","reference":' . $number . '}';
            self::assertSame(WebhookVerdict::Invalid, $verifier->verify($headers, $body), $number);
        }
        self::assertSame(WebhookVerdict::Invalid, $verifier->verify($headers, '{"transid":"T","order_id":"O","reference":' . str_repeat('9', 200000) . '}'));
        self::assertSame(WebhookVerdict::Invalid, $verifier->verify($headers, str_repeat('[', 100000)));
        self::assertSame(WebhookVerdict::Invalid, $verifier->verify(['Digest' => 'a', 'Digest-Method' => 'HS256', 'Timestamp' => '2026-11-21T10:30:46+03:00', 'Signed-Fields' => str_repeat('a,', 100000)], '{}'));
        self::assertSame(WebhookVerdict::Invalid, $verifier->verify($headers, str_repeat(' ', 2000000) . '{}'));
    }

    public function testWebhookSignedFieldRules(): void
    {
        $clock = FixedClock::at('2026-11-21T10:31:00+03:00');
        $verifier = new WebhookVerifier('test-secret-0001', $clock);

        [$headers, $body] = self::signedDelivery(['transid' => 'TXN-1', 'order_id' => 'ORD-1', 'reference' => 'REF-1']);
        self::assertSame(WebhookVerdict::Valid, $verifier->verify($headers, $body));

        [$headers, $body] = self::signedDelivery(['transid' => 'TXN-2', 'order_id' => 'ORD-1', 'reference' => 'REF&1']);
        self::assertSame(WebhookVerdict::Invalid, $verifier->verify($headers, $body));

        [$headers, $body] = self::signedDelivery(['transid' => 'TXN-3', 'order_id' => 'ORD-1', 'reference' => 'REF-1'], 'transid,reference');
        self::assertSame(WebhookVerdict::Invalid, $verifier->verify($headers, $body));

        [$headers, $body] = self::signedDelivery(['transid' => 'TXN-4', 'order_id' => 'ORD-1', 'reference' => 8000.0]);
        self::assertSame(WebhookVerdict::Valid, $verifier->verify($headers, $body));
    }

    public function testReplayEntriesAreKeptUntilTheSignedTimePlusTheWindow(): void
    {
        // A delivery dated 200 s ahead of this server's clock: kept until its time + 300 s.
        $clock = FixedClock::at('2026-11-21T10:31:00+03:00');
        $verifier = new WebhookVerifier('test-secret-0001', $clock, windowSeconds: 300);
        [$headers, $body] = self::signedDelivery(['transid' => 'TXN-1', 'order_id' => 'ORD-1'], 'transid,order_id', '2026-11-21T10:34:20+03:00');

        $first = $verifier->verify($headers, $body);
        $clock->advance(299);   // 10:35:59, still inside the window of the signed time
        $second = $verifier->verify($headers, $body);
        $clock->advance(120);   // 10:37:59: 10:34:20 + 300 s = 10:39:20 is not yet reached
        $third = $verifier->verify($headers, $body);
        $clock->advance(100);   // 10:39:39: past the window, now stale
        $fourth = $verifier->verify($headers, $body);
        self::assertSame(
            ['VALID', 'REPLAY', 'REPLAY', 'STALE'],
            [$first->value, $second->value, $third->value, $fourth->value],
        );
    }

    public function testReplayStoreBoundary(): void
    {
        $clock = FixedClock::at('2026-11-21T10:30:00+03:00');
        $store = new InMemoryReplayStore($clock);
        $keepUntil = $clock->now()->getTimestamp() + 300;

        self::assertFalse($store->seen('d', $keepUntil));
        $clock->advance(300);                       // exactly at the keep-until time: still live
        self::assertTrue($store->seen('d', $keepUntil));
        $clock->advance(1);                         // one second later: gone
        self::assertFalse($store->seen('d', $keepUntil + 1));
    }
}
