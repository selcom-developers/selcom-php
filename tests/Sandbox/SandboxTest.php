<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Tests\Sandbox;

use PHPUnit\Framework\TestCase;
use SelcomDevelopers\Selcom\Client;
use SelcomDevelopers\Selcom\Config;
use SelcomDevelopers\Selcom\Environment;
use SelcomDevelopers\Selcom\Credentials;
use SelcomDevelopers\Selcom\Request\OrderStatusRequest;

/**
 * Opt-in test against the real sandbox. It is skipped unless every variable below is set, so it
 * never runs in CI or in pull requests from forks. It sends one read-only order-status call.
 *
 *   SELCOM_SANDBOX_API_KEY, SELCOM_SANDBOX_API_SECRET, SELCOM_SANDBOX_VENDOR
 */
final class SandboxTest extends TestCase
{
    public function testOrderStatusForAnUnknownOrderGetsAVerdict(): void
    {
        $key = getenv('SELCOM_SANDBOX_API_KEY');
        $secret = getenv('SELCOM_SANDBOX_API_SECRET');
        $vendor = getenv('SELCOM_SANDBOX_VENDOR');
        if ($key === false || $secret === false || $vendor === false || $key === '' || $secret === '' || $vendor === '') {
            self::markTestSkipped('Set SELCOM_SANDBOX_API_KEY, SELCOM_SANDBOX_API_SECRET and SELCOM_SANDBOX_VENDOR to run.');
        }

        $client = new Client(new Config(Environment::Sandbox, new Credentials($key, $secret, $vendor)));
        $result = $client->orderStatus(new OrderStatusRequest('ORD-DOES-NOT-EXIST-' . bin2hex(random_bytes(4))));

        self::assertNotSame('', $result->raw);
        self::assertGreaterThan(0, $result->httpStatus);
    }
}
