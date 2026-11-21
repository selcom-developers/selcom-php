<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom;

use SelcomDevelopers\Selcom\Exception\ConfigurationException;
use SelcomDevelopers\Selcom\Transport\TimeoutProfile;
use SelcomDevelopers\Selcom\Transport\Timeouts;

/**
 * Client configuration. Validated at construction, so a mistake fails at startup and not on
 * the first payment.
 *
 *  - The environment is explicit. There is no default, and no production default in particular.
 *  - A custom base URL (simulator, proxy) is its own explicit choice: withBaseUrl().
 *  - A merchant may use separate credentials for checkout: pass $checkoutCredentials.
 */
final class Config
{
    public const DEFAULT_TIMEZONE = 'Africa/Dar_es_Salaam';

    private ?string $customBaseUrl = null;

    /**
     * @param int $maxNotSentRetries Opt-in repeats of read-only operations after a NOT_SENT error.
     *                               Payments and writes are never repeated, whatever this says.
     */
    public function __construct(
        public readonly Environment $environment,
        public readonly Credentials $credentials,
        public readonly ?Credentials $checkoutCredentials = null,
        public readonly Timeouts $timeouts = new Timeouts(),
        public readonly \DateTimeZone $timezone = new \DateTimeZone(self::DEFAULT_TIMEZONE),
        public readonly int $maxNotSentRetries = 0,
    ) {
        if ($maxNotSentRetries < 0 || $maxNotSentRetries > 5) {
            throw new ConfigurationException('maxNotSentRetries must be between 0 and 5.');
        }
    }

    /**
     * Build from a plain array, as a framework config file would provide.
     *
     * Keys: environment (required), api_key, secret, vendor (all required), pin, checkout
     * (array with api_key, secret, vendor), base_url, timezone, max_not_sent_retries, timeouts
     * (array: query_connect, query_read, payment_connect, payment_read, in seconds).
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(#[\SensitiveParameter] array $config): self
    {
        $environment = Environment::fromName(self::optionalString($config, 'environment'));
        $credentials = new Credentials(
            self::optionalString($config, 'api_key') ?? '',
            self::optionalString($config, 'secret') ?? '',
            self::optionalString($config, 'vendor') ?? '',
            self::optionalString($config, 'pin'),
        );

        $checkout = null;
        if (isset($config['checkout'])) {
            if (!is_array($config['checkout'])) {
                throw new ConfigurationException('"checkout" must be an array.');
            }
            /** @var array<string, mixed> $c */
            $c = $config['checkout'];
            $checkout = new Credentials(
                self::optionalString($c, 'api_key') ?? '',
                self::optionalString($c, 'secret') ?? '',
                self::optionalString($c, 'vendor') ?? $credentials->vendor(),
            );
        }

        $timeouts = new Timeouts();
        if (isset($config['timeouts'])) {
            if (!is_array($config['timeouts'])) {
                throw new ConfigurationException('"timeouts" must be an array.');
            }
            /** @var array<string, mixed> $t */
            $t = $config['timeouts'];
            $timeouts = new Timeouts(
                new TimeoutProfile(
                    self::seconds($t, 'query_connect', $timeouts->query->connectSeconds),
                    self::seconds($t, 'query_read', $timeouts->query->readSeconds),
                ),
                new TimeoutProfile(
                    self::seconds($t, 'payment_connect', $timeouts->payment->connectSeconds),
                    self::seconds($t, 'payment_read', $timeouts->payment->readSeconds),
                ),
            );
        }

        try {
            $zone = new \DateTimeZone(self::optionalString($config, 'timezone') ?? self::DEFAULT_TIMEZONE);
        } catch (\Exception) {
            throw new ConfigurationException('Unknown timezone.');
        }

        $retries = $config['max_not_sent_retries'] ?? 0;
        if (!is_int($retries)) {
            throw new ConfigurationException('"max_not_sent_retries" must be an integer.');
        }

        $instance = new self($environment, $credentials, $checkout, $timeouts, $zone, $retries);
        $baseUrl = self::optionalString($config, 'base_url');

        return $baseUrl === null ? $instance : $instance->withBaseUrl($baseUrl);
    }

    /**
     * Point the client at a simulator or proxy instead of the environment's preset URL.
     */
    public function withBaseUrl(string $baseUrl): self
    {
        $parts = parse_url($baseUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['query']) || isset($parts['fragment'])
        ) {
            throw new ConfigurationException('The base URL must be an http(s) URL without query or fragment.');
        }
        $copy = clone $this;
        $copy->customBaseUrl = rtrim($baseUrl, '/');

        return $copy;
    }

    public function baseUrl(): string
    {
        return $this->customBaseUrl ?? $this->environment->baseUrl();
    }

    /** The credential set for an operation: checkout operations use the checkout set when there is one. */
    public function credentialsFor(Operation $operation): Credentials
    {
        return $operation->isCheckout() ? ($this->checkoutCredentials ?? $this->credentials) : $this->credentials;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'environment' => $this->environment->value,
            'baseUrl' => $this->baseUrl(),
            'credentials' => $this->credentials,
            'checkoutCredentials' => $this->checkoutCredentials,
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function optionalString(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new ConfigurationException('"' . $key . '" must be a string.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function seconds(array $config, string $key, float $default): float
    {
        $value = $config[$key] ?? $default;
        if (!is_int($value) && !is_float($value)) {
            throw new ConfigurationException('"timeouts.' . $key . '" must be a number of seconds.');
        }

        return (float) $value;
    }
}
