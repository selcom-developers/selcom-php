<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Result;

use SelcomDevelopers\Selcom\Operation;
use SelcomDevelopers\Selcom\Status;

/**
 * What every operation returns: the typed status, the gateway's code and text, the HTTP status,
 * the decoded data and the raw body.
 *
 * Operation-specific subclasses add typed accessors for `data`. The raw values always stay in
 * `$data`; `$raw` is the body exactly as received, for audit. Redact before logging.
 */
class Result
{
    /**
     * @param list<array<string, mixed>> $data The decoded `data` array, raw values kept
     */
    final public function __construct(
        public readonly Operation $operation,
        public readonly Status $status,
        public readonly ?string $resultCode,
        public readonly ?string $result,
        public readonly ?string $message,
        public readonly ?string $reference,
        public readonly ?string $transid,
        public readonly ?string $orderId,
        public readonly int $httpStatus,
        public readonly array $data,
        public readonly string $raw,
    ) {
    }

    /** SUCCESS, FAILED or NOT_FOUND. */
    public function isFinal(): bool
    {
        return $this->status->isFinal();
    }

    public function isSuccess(): bool
    {
        return $this->status === Status::Success;
    }

    /**
     * The first `data` entry, or an empty array.
     *
     * @return array<string, mixed>
     */
    public function entry(): array
    {
        return $this->data[0] ?? [];
    }

    protected function entryString(string $key): ?string
    {
        $value = $this->entry()[$key] ?? null;

        return is_string($value) ? $value : (is_int($value) ? (string) $value : null);
    }
}
