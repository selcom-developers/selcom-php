<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Exception;

/**
 * A response arrived but carries no verdict, on an operation that moves no money
 * (an HTML error page from a proxy, an empty or truncated body).
 *
 * The raw body is kept for diagnosis. Redact it before logging.
 */
final class InvalidResponseException extends \RuntimeException implements SelcomException
{
    public const CODE = 'INVALID_RESPONSE';

    public function __construct(
        public readonly int $httpStatus,
        public readonly string $rawBody,
        string $detail = 'the response has no usable resultcode',
    ) {
        parent::__construct(self::CODE . ': ' . $detail . ' (HTTP ' . $httpStatus . ')');
    }

    public function errorCode(): string
    {
        return self::CODE;
    }
}
