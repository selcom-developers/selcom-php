<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Tests;

use PHPUnit\Framework\TestCase;
use SelcomDevelopers\Selcom\Redaction\Redactor;

final class RedactorTest extends TestCase
{
    public function testSecretsHeadersAndPii(): void
    {
        $r = new Redactor();

        self::assertSame(
            ['Authorization' => '[REDACTED]', 'Digest' => '[REDACTED]', 'Timestamp' => 't', 'Digest-Method' => 'HS256'],
            $r->redactHeaders(['Authorization' => 'SELCOM abc', 'Digest' => 'xyz', 'Timestamp' => 't', 'Digest-Method' => 'HS256']),
        );

        $out = $r->redactParams([
            'vendor' => 'TILL123',
            'pin' => '0000',
            'card_token' => 'CARDTOKEN-TEST-0001',
            'msisdn' => '255700000001',
            'buyer_email' => 'test.buyer@example.com',
            'buyer_name' => 'Test Buyer',
            'amount' => 8000,
            'billing' => ['firstname' => 'Test', 'lastname' => 'Buyer', 'phone' => '255700000001', 'city' => 'Dar es Salaam'],
            'shipping.phone' => '255700000002',
        ]);
        self::assertSame('TILL123', $out['vendor']);
        self::assertSame('[REDACTED]', $out['pin']);
        self::assertSame('[REDACTED]', $out['card_token']);
        self::assertSame('********0001', $out['msisdn']);
        self::assertSame('t***@example.com', $out['buyer_email']);
        self::assertSame('T***', $out['buyer_name']);
        self::assertSame(8000, $out['amount']);
        self::assertSame(
            ['firstname' => 'T***', 'lastname' => 'B***', 'phone' => '********0001', 'city' => 'Dar es Salaam'],
            $out['billing'],
        );
        self::assertSame('********0002', $out['shipping.phone']);
    }

    public function testUtilityRefIsAPhoneOnlyForWalletCashin(): void
    {
        $r = new Redactor();
        self::assertSame(
            '********0002',
            $r->redactParams(['utilitycode' => 'VMCASHIN', 'utilityref' => '255700000002'])['utilityref'],
        );
        self::assertSame(
            '01234567891',
            $r->redactParams(['utilitycode' => 'LUKU', 'utilityref' => '01234567891'])['utilityref'],
        );
    }

    public function testQueryStringAndJson(): void
    {
        $r = new Redactor();
        self::assertSame(
            'utilitycode=CASHIN&utilityref=%2A%2A%2A%2A%2A%2A%2A%2A0002&transid=TXN-1',
            $r->redactQuery('utilitycode=CASHIN&utilityref=255700000002&transid=TXN-1'),
        );
        self::assertSame(
            'gateway_buyer_uuid=GBU&buyer_userid=U1&pin=%5BREDACTED%5D',
            $r->redactQuery('gateway_buyer_uuid=GBU&buyer_userid=U1&pin=1234'),
        );
        self::assertSame(
            '{"phone":"********0001","data":[{"card_token":"[REDACTED]"}]}',
            $r->redactJson('{"phone":"255700000001","data":[{"card_token":"CARDTOKEN-TEST-0001"}]}'),
        );
        self::assertSame('[UNPARSEABLE BODY OMITTED]', $r->redactJson('<html>pin=1234</html>'));
    }

    public function testShortAndOddValues(): void
    {
        $r = new Redactor();
        self::assertSame('***', $r->redactParams(['phone' => '123'])['phone']);
        self::assertSame('[REDACTED]', $r->redactParams(['email' => 'no-at-sign'])['email']);
        self::assertSame('', $r->redactParams(['name' => ''])['name']);
        self::assertSame('Ä***', $r->redactParams(['name' => 'Ärnold'])['name']);
    }
}
