<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom;

use SelcomDevelopers\Selcom\Exception\ConfigurationException;
use SelcomDevelopers\Selcom\Exception\ValidationException;

/**
 * API key, secret and vendor (till) ID, plus the float PIN that payout operations need.
 *
 * The key, secret and PIN are held outside the object, so dumps, exports, array casts,
 * serialisation and exception messages show none of them. The key shows only its last 4 characters.
 */
final class Credentials
{
    private readonly string $vendor;
    private readonly string $keyHint;
    private readonly bool $pinSet;

    /**
     * @throws ConfigurationException when the key, secret or vendor is missing
     */
    public function __construct(
        #[\SensitiveParameter] string $apiKey,
        #[\SensitiveParameter] string $secret,
        string $vendor,
        #[\SensitiveParameter] ?string $pin = null,
    ) {
        foreach (['apiKey' => $apiKey, 'secret' => $secret, 'vendor' => $vendor] as $name => $value) {
            if (trim($value) === '') {
                throw new ConfigurationException('The ' . $name . ' is required and must not be empty.');
            }
        }
        if ($pin !== null && trim($pin) === '') {
            throw new ConfigurationException('The pin must be omitted or non-empty.');
        }
        $this->vendor = $vendor;
        $this->keyHint = self::mask($apiKey);
        $this->pinSet = $pin !== null;
        SecretVault::put($this, ['apiKey' => $apiKey, 'secret' => $secret, 'pin' => $pin]);
    }

    private function __clone()
    {
    }

    public function apiKey(): string
    {
        return SecretVault::get($this, 'apiKey') ?? '';
    }

    public function secret(): string
    {
        return SecretVault::get($this, 'secret') ?? '';
    }

    public function vendor(): string
    {
        return $this->vendor;
    }

    public function hasPin(): bool
    {
        return $this->pinSet;
    }

    /**
     * @throws ValidationException when no PIN is configured (payout operations need one)
     */
    public function pin(): string
    {
        return SecretVault::get($this, 'pin') ?? throw new ValidationException(
            'This operation spends from the float account and needs the float PIN, but none is configured.'
        );
    }

    /** The API key reduced to its last 4 characters, for logs and dumps. */
    public function maskedApiKey(): string
    {
        return $this->keyHint;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return [
            'apiKey' => $this->keyHint,
            'secret' => '[REDACTED]',
            'vendor' => $this->vendor,
            'pin' => $this->pinSet ? '[REDACTED]' : 'none',
        ];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Credentials hold secrets and cannot be serialised.');
    }

    private static function mask(string $apiKey): string
    {
        return '****' . substr($apiKey, -4);
    }
}
