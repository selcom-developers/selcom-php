<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Exception;

/**
 * The request cannot be signed, so it must not be sent.
 *
 * `$errorCode` is one of the codes in the spec's `rejects` vectors, for example
 * EMPTY_SECRET, NON_INTEGER_NUMBER or INVALID_TIMESTAMP.
 */
final class SigningException extends \InvalidArgumentException implements SelcomException
{
    public function __construct(public readonly string $errorCode, string $detail)
    {
        parent::__construct($errorCode . ': ' . $detail);
    }
}
