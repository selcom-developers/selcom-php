<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Signing;

use SelcomDevelopers\Selcom\Exception\SigningException;
use SelcomDevelopers\Selcom\SecretVault;

/**
 * HMAC-SHA256 keyed with the API secret, standard base64 with padding.
 * The secret is used as UTF-8 text; it is never hex-decoded.
 */
final class Hs256DigestSigner implements DigestSigner
{
    public function __construct(#[\SensitiveParameter] string $secret)
    {
        if ($secret === '') {
            throw new SigningException('EMPTY_SECRET', 'refusing to sign with an empty secret');
        }
        SecretVault::put($this, ['secret' => $secret]);
    }

    private function __clone()
    {
    }

    public function method(): string
    {
        return 'HS256';
    }

    public function sign(#[\SensitiveParameter] string $signingString): string
    {
        return base64_encode(hash_hmac('sha256', $signingString, SecretVault::get($this, 'secret') ?? '', true));
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['method' => 'HS256', 'secret' => '[REDACTED]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Hs256DigestSigner holds a secret and cannot be serialised.');
    }
}
