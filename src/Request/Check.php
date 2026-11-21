<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Request;

use SelcomDevelopers\Selcom\Exception\ValidationException;

/**
 * Field validation shared by the request classes. Nothing is invented or defaulted: a missing
 * required field is an error, never a placeholder.
 *
 * @internal
 */
final class Check
{
    public static function required(string $field, string $value): string
    {
        if (trim($value) === '') {
            throw new ValidationException($field . ' is required and must not be empty.');
        }

        return $value;
    }

    public static function optional(string $field, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (trim($value) === '') {
            throw new ValidationException($field . ' must be omitted or non-empty.');
        }

        return $value;
    }

    /** Whole units, strictly positive. */
    public static function amount(string $field, int $value): int
    {
        if ($value <= 0) {
            throw new ValidationException($field . ' must be a positive whole amount.');
        }
        if ($value > 9007199254740991) {
            throw new ValidationException($field . ' is too large.');
        }

        return $value;
    }

    public static function date(string $field, string $value): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new ValidationException($field . ' must be a date as YYYY-MM-DD.');
        }

        return $value;
    }

    /**
     * Set a key only when the value is not null, keeping the order of the calls.
     *
     * @param array<string, mixed> $params
     */
    public static function put(array &$params, string $key, string|int|null $value): void
    {
        if ($value !== null) {
            $params[$key] = $value;
        }
    }

    public static function url(string $field, ?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
        ) {
            throw new ValidationException($field . ' must be an http(s) URL.');
        }

        // The signature covers the base64 text that is sent, so encode before signing.
        return base64_encode($url);
    }
}
