<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Exception;

use SelcomDevelopers\Selcom\SecretVault;

/**
 * A response arrived but carries no verdict, on an operation that moves no money
 * (an HTML error page from a proxy, an empty or truncated body).
 *
 * The raw body is kept for diagnosis through rawBody(), outside the exception's printed state.
 */
final class InvalidResponseException extends \RuntimeException implements SelcomException
{
    public const CODE = 'INVALID_RESPONSE';

    public function __construct(
        public readonly int $httpStatus,
        #[\SensitiveParameter] string $rawBody,
        string $detail = 'the response has no usable resultcode',
    ) {
        parent::__construct(self::CODE . ': ' . $detail . ' (HTTP ' . $httpStatus . ')');
        SecretVault::put($this, ['rawBody' => $rawBody]);
    }

    /** The body as received, for diagnosis. It may hold personal data: redact before logging. */
    public function rawBody(): string
    {
        return SecretVault::get($this, 'rawBody') ?? '';
    }

    public function errorCode(): string
    {
        return self::CODE;
    }
}
