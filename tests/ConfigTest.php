<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Tests;

use PHPUnit\Framework\TestCase;
use SelcomDevelopers\Selcom\Config;
use SelcomDevelopers\Selcom\Credentials;
use SelcomDevelopers\Selcom\Environment;
use SelcomDevelopers\Selcom\Exception\ConfigurationException;
use SelcomDevelopers\Selcom\Operation;
use SelcomDevelopers\Selcom\Tests\Support\Fixtures;

final class ConfigTest extends TestCase
{
    public function testThereIsNoDefaultEnvironment(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('no default');
        Config::fromArray(['api_key' => 'k', 'secret' => 's', 'vendor' => 'TILL123']);
    }

    public function testUnknownEnvironmentIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        Environment::fromName('staging');
    }

    public function testPresetUrls(): void
    {
        self::assertSame('https://apigw.selcommobile.com', Environment::Production->baseUrl());
        self::assertSame('https://apigwtest.selcommobile.com', Environment::Sandbox->baseUrl());
        self::assertSame('https://apigwtest.selcommobile.com', Fixtures::config()->baseUrl());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function incomplete(): iterable
    {
        yield 'no key' => [['environment' => 'sandbox', 'secret' => 's', 'vendor' => 'v']];
        yield 'no secret' => [['environment' => 'sandbox', 'api_key' => 'k', 'vendor' => 'v']];
        yield 'no vendor' => [['environment' => 'sandbox', 'api_key' => 'k', 'secret' => 's']];
        yield 'blank secret' => [['environment' => 'sandbox', 'api_key' => 'k', 'secret' => '  ', 'vendor' => 'v']];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('incomplete')]
    public function testMissingCredentialsFailAtConstruction(array $config): void
    {
        $this->expectException(ConfigurationException::class);
        Config::fromArray($config);
    }

    public function testFromArrayWithEverything(): void
    {
        $config = Config::fromArray([
            'environment' => 'production',
            'api_key' => 'k',
            'secret' => 's',
            'vendor' => 'TILL123',
            'pin' => '0000',
            'checkout' => ['api_key' => 'ck', 'secret' => 'cs'],
            'timezone' => 'Africa/Nairobi',
            'max_not_sent_retries' => 2,
            'timeouts' => ['payment_read' => 240, 'query_connect' => 5],
        ]);

        self::assertSame(Environment::Production, $config->environment);
        self::assertSame('ck', $config->credentialsFor(Operation::CheckoutOrderStatus)->apiKey());
        self::assertSame('TILL123', $config->credentialsFor(Operation::CheckoutOrderStatus)->vendor());
        self::assertSame('k', $config->credentialsFor(Operation::UtilityPaymentQuery)->apiKey());
        self::assertSame(240.0, $config->timeouts->payment->readSeconds);
        self::assertSame(15.0, $config->timeouts->payment->connectSeconds);
        self::assertSame(5.0, $config->timeouts->query->connectSeconds);
        self::assertSame(10.0, $config->timeouts->query->readSeconds);
        self::assertSame(2, $config->maxNotSentRetries);
        self::assertSame('Africa/Nairobi', $config->timezone->getName());
    }

    public function testDefaultTimeoutsMatchTheSpec(): void
    {
        $t = Fixtures::config()->timeouts;
        self::assertSame([10.0, 10.0], [$t->query->connectSeconds, $t->query->readSeconds]);
        self::assertSame([15.0, 180.0], [$t->payment->connectSeconds, $t->payment->readSeconds]);
    }

    public function testBaseUrlOverrideIsExplicitAndValidated(): void
    {
        $custom = Fixtures::config()->withBaseUrl('http://localhost:8080/');
        self::assertSame('http://localhost:8080', $custom->baseUrl());
        self::assertSame('https://apigwtest.selcommobile.com', Fixtures::config()->baseUrl());

        $this->expectException(ConfigurationException::class);
        Fixtures::config()->withBaseUrl('ftp://example.com');
    }

    public function testTooManyRetriesAreRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        new Config(Environment::Sandbox, Fixtures::credentials(), maxNotSentRetries: 6);
    }

    public function testSecretsAreMaskedInOutputAndNotSerialisable(): void
    {
        $config = Fixtures::config();
        $dump = print_r($config, true) . print_r($config->credentials, true);
        ob_start();
        var_dump($config);
        $dump .= (string) ob_get_clean();

        foreach (['test-secret-0001', 'test-key-0001', '0000'] as $secret) {
            self::assertStringNotContainsString($secret, $dump);
        }
        self::assertStringContainsString('[REDACTED]', $dump);

        $this->expectException(\LogicException::class);
        serialize($config->credentials);
    }

    public function testPinIsRequiredOnlyWhenAPayoutAsksForIt(): void
    {
        $credentials = new Credentials('k', 's', 'v');
        self::assertFalse($credentials->hasPin());
        $this->expectException(\SelcomDevelopers\Selcom\Exception\ValidationException::class);
        $credentials->pin();
    }
}
