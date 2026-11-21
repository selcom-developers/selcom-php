<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Transport;

/**
 * What came back. The HTTP status does not decide the verdict: business errors are in the body.
 */
final class TransportResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }
}
