<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Exception;

use SelcomDevelopers\Selcom\Transport\TransportFailure;

/**
 * No HTTP response was received.
 *
 * Check `$failure`: NOT_SENT means the request provably never reached the gateway
 * (sending it again with the same ID is safe); UNKNOWN_OUTCOME means the gateway
 * may have acted (treat as AMBIGUOUS: query, never resend).
 */
final class TransportException extends \RuntimeException implements SelcomException
{
    public function __construct(
        public readonly TransportFailure $failure,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function isNotSent(): bool
    {
        return $this->failure === TransportFailure::NotSent;
    }
}
