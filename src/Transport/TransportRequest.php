<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Transport;

use SelcomDevelopers\Selcom\Redaction\Redactor;

/**
 * One HTTP request, fully built and signed. A custom Transport only has to send it.
 *
 * Printing it (var_dump, print_r) shows only masked data. Use the redacted accessors for logs.
 */
final class TransportRequest
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        #[\SensitiveParameter] public readonly array $headers,
        #[\SensitiveParameter] public readonly ?string $body,
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
            $out[$name] = in_array(strtolower($name), ['authorization', 'digest'], true) ? Redactor::REDACTED : $value;
        }

        return $out;
    }

    /** The body safe to log: secrets and personal data masked. Null when there is no body. */
    public function redactedBody(): ?string
    {
        return $this->body === null ? null : (new Redactor())->redactJson($this->body, $this->path());
    }

    /** The URL safe to log: the query string is masked. */
    public function redactedUrl(): string
    {
        $parts = explode('?', $this->url, 2);

        return isset($parts[1]) ? $parts[0] . '?' . (new Redactor())->redactQuery($parts[1], $this->path()) : $this->url;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'method' => $this->method,
            'url' => $this->redactedUrl(),
            'headers' => $this->redactedHeaders(),
            'body' => $this->redactedBody(),
            'timeouts' => $this->timeouts,
        ];
    }

    private function path(): string
    {
        $path = parse_url($this->url, PHP_URL_PATH);

        return is_string($path) ? $path : '';
    }
}
