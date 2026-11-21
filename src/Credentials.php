<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom;

use SelcomDevelopers\Selcom\Exception\ConfigurationException;
use SelcomDevelopers\Selcom\Exception\ValidationException;

/**
 * API key, secret and vendor (till) ID, plus the float PIN that payout operations need.
 *
 * The secret and PIN never appear in var_dump(), print_r(), serialisation or exception messages.
 */
final class Credentials
{
    private readonly string $apiKey;
    private readonly string $secret;
    private readonly string $vendor;
    private readonly ?string $pin;

    /**
     * @throws ConfigurationException when the key, secret or vendor is missing
     */
    public function __construct(string $apiKey, string $secret, string $vendor, ?string $pin = null)
    {
        foreach (['apiKey' => $apiKey, 'secret' => $secret, 'vendor' => $vendor] as $name => $value) {
            if (trim($value) === '') {
                throw new ConfigurationException('The ' . $name . ' is required and must not be empty.');
            }
        }
        if ($pin !== null && trim($pin) === '') {
            throw new ConfigurationException('The pin must be omitted or non-empty.');
        }
        $this->apiKey = $apiKey;
        $this->secret = $secret;
        $this->vendor = $vendor;
        $this->pin = $pin;
    }

    public function apiKey(): string
    {
        return $this->apiKey;
    }

    public function secret(): string
    {
        return $this->secret;
    }

    public function vendor(): string
    {
        return $this->vendor;
    }

    public function hasPin(): bool
    {
        return $this->pin !== null;
    }

    /**
     * @throws ValidationException when no PIN is configured (payout operations need one)
     */
    public function pin(): string
    {
        return $this->pin ?? throw new ValidationException(
            'This operation spends from the float account and needs the float PIN, but none is configured.'
        );
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return [
            'apiKey' => '[REDACTED]',
            'secret' => '[REDACTED]',
            'vendor' => $this->vendor,
            'pin' => $this->pin === null ? 'none' : '[REDACTED]',
        ];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Credentials hold secrets and cannot be serialised.');
    }
}
