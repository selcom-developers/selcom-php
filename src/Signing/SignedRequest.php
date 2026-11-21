<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Signing;

/**
 * Everything a client puts on the wire for one request.
 */
final class SignedRequest
{
    /**
     * @param list<array{0: string, 1: bool|int|string}> $flattened Ordered (key, normalised value) pairs
     * @param string|null $query Percent-encoded query string for GET and DELETE
     * @param string|null $body Compact JSON object with flat keys for POST
     */
    public function __construct(
        public readonly array $flattened,
        public readonly string $signedFields,
        public readonly string $signingString,
        public readonly string $authorization,
        public readonly string $digestMethod,
        public readonly string $digest,
        public readonly string $timestamp,
        public readonly ?string $query,
        public readonly ?string $body,
    ) {
    }

    /**
     * The signing headers. Add Accept and, on POST, Content-Type yourself (the client does).
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return [
            'Authorization' => $this->authorization,
            'Digest-Method' => $this->digestMethod,
            'Digest' => $this->digest,
            'Timestamp' => $this->timestamp,
            'Signed-Fields' => $this->signedFields,
        ];
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return [
            'signedFields' => $this->signedFields,
            'timestamp' => $this->timestamp,
            'digest' => '[REDACTED]',
            'authorization' => '[REDACTED]',
        ];
    }
}
