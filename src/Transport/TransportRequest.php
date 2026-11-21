<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Transport;

/**
 * One HTTP request, fully built and signed. A custom Transport only has to send it.
 */
final class TransportRequest
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly ?string $body,
        public readonly TimeoutProfile $timeouts,
    ) {
    }

    /**
     * Headers safe to log: Authorization and Digest are replaced.
     *
     * @return array<string, string>
     */
    public function redactedHeaders(): array
    {
        $out = [];
        foreach ($this->headers as $name => $value) {
            $out[$name] = in_array(strtolower($name), ['authorization', 'digest'], true) ? '[REDACTED]' : $value;
        }

        return $out;
    }
}
