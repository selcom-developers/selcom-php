<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Signing;

use SelcomDevelopers\Selcom\Exception\SigningException;

/**
 * Pure request signing (spec: docs/signing.md). No I/O and no clock: the timestamp
 * is an argument. On any error nothing is returned, so nothing can be sent.
 */
final class RequestSigner
{
    public const MAX_SAFE_INTEGER = 9007199254740991;

    private const KEY_PATTERN = '/^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)*$/D';

    private const METHODS = ['GET', 'POST', 'DELETE'];

    /**
     * @param string $method GET, POST or DELETE
     * @param array<int|string, mixed> $params Parameters in send order; nested arrays flatten to dotted keys
     * @param string $timestamp Used for the header and the signing string, character for character
     *
     * @throws SigningException
     */
    public static function sign(
        string $method,
        array $params,
        string $timestamp,
        string $apiKey,
        DigestSigner $digestSigner,
    ): SignedRequest {
        $method = strtoupper($method);
        if (!in_array($method, self::METHODS, true)) {
            throw new SigningException('UNSUPPORTED_METHOD', $method);
        }
        if ($apiKey === '') {
            throw new SigningException('EMPTY_API_KEY', 'refusing to build Authorization from an empty key');
        }
        Timestamp::assertValid($timestamp);

        $flat = self::flatten($params);
        $signedFields = implode(',', array_map(static fn (array $pair): string => $pair[0], $flat));
        $signingString = self::signingString($timestamp, $flat);
        $digest = $digestSigner->sign($signingString);
        if ($digest === '') {
            throw new SigningException('EMPTY_DIGEST', 'refusing to send an empty Digest');
        }

        return new SignedRequest(
            flattened: $flat,
            signedFields: $signedFields,
            signingString: $signingString,
            authorization: 'SELCOM ' . base64_encode($apiKey),
            digestMethod: $digestSigner->method(),
            digest: $digest,
            timestamp: $timestamp,
            query: $method === 'POST' ? null : self::encodeQuery($flat),
            body: $method === 'POST' ? self::encodeBody($flat) : null,
        );
    }

    /**
     * Flatten nested arrays to dotted keys in place, keeping order, and normalise every value.
     *
     * @param array<int|string, mixed> $params
     * @return list<array{0: string, 1: bool|int|string}>
     *
     * @throws SigningException
     */
    public static function flatten(array $params): array
    {
        $flat = [];
        $seen = [];
        self::walk('', $params, $flat, $seen);
        if ($flat === []) {
            throw new SigningException('EMPTY_PARAMS', 'there is nothing to sign');
        }

        return $flat;
    }

    /**
     * The text of one normalised value inside the signing string.
     */
    public static function valueText(bool|int|string $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            default => $value,
        };
    }

    /**
     * @param array<int|string, mixed> $object
     * @param list<array{0: string, 1: bool|int|string}> $flat
     * @param array<string, true> $seen
     */
    private static function walk(string $prefix, array $object, array &$flat, array &$seen): void
    {
        if ($prefix !== '' && $object === []) {
            throw new SigningException('EMPTY_OBJECT', $prefix . ' is an empty object; omit it');
        }
        if ($prefix !== '' && array_is_list($object)) {
            throw new SigningException('UNSUPPORTED_TYPE', $prefix . ': arrays cannot be signed (there is no flat form)');
        }
        foreach ($object as $rawKey => $value) {
            $rawKey = (string) $rawKey;
            if ($rawKey === '') {
                throw new SigningException('INVALID_KEY', 'empty key under ' . ($prefix === '' ? 'root' : $prefix));
            }
            $key = $prefix === '' ? $rawKey : $prefix . '.' . $rawKey;
            if (preg_match(self::KEY_PATTERN, $key) !== 1) {
                throw new SigningException('INVALID_KEY', '"' . $key . '" has characters outside [A-Za-z0-9_.-]');
            }
            if (is_array($value)) {
                if ($value !== [] && array_is_list($value)) {
                    throw new SigningException('UNSUPPORTED_TYPE', $key . ': arrays cannot be signed (there is no flat form)');
                }
                self::walk($key, $value, $flat, $seen);
                continue;
            }
            if (isset($seen[$key])) {
                throw new SigningException('DUPLICATE_KEY', $key . ' appears twice after flattening');
            }
            $seen[$key] = true;
            $flat[] = [$key, self::normalise($key, $value)];
        }
    }

    /**
     * Normalise one scalar: the value that is both signed and sent.
     *
     * @throws SigningException
     */
    public static function normalise(string $key, mixed $value): bool|int|string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value) || is_string($value)) {
            return $value;
        }
        if (is_int($value)) {
            if (abs($value) > self::MAX_SAFE_INTEGER) {
                throw new SigningException('NUMBER_OUT_OF_RANGE', $key . ': send integers above 2^53-1 as strings');
            }

            return $value;
        }
        if (is_float($value)) {
            if (!is_finite($value) || floor($value) !== $value) {
                throw new SigningException('NON_INTEGER_NUMBER', $key . ': ' . var_export($value, true) . ' has a fractional part');
            }
            if (abs($value) > (float) self::MAX_SAFE_INTEGER) {
                throw new SigningException('NUMBER_OUT_OF_RANGE', $key . ': send integers above 2^53-1 as strings');
            }

            return (int) $value;
        }

        throw new SigningException('UNSUPPORTED_TYPE', $key . ': ' . get_debug_type($value) . ' values cannot be signed');
    }

    /**
     * @param list<array{0: string, 1: bool|int|string}> $flat
     */
    public static function signingString(string $timestamp, array $flat): string
    {
        $out = 'timestamp=' . $timestamp;
        foreach ($flat as [$key, $value]) {
            $out .= '&' . $key . '=' . self::valueText($value);
        }

        return $out;
    }

    /**
     * @param list<array{0: string, 1: bool|int|string}> $flat
     */
    private static function encodeQuery(array $flat): string
    {
        $parts = [];
        foreach ($flat as [$key, $value]) {
            // rawurlencode is RFC 3986: only A-Z a-z 0-9 - . _ ~ stay as they are.
            $parts[] = rawurlencode($key) . '=' . rawurlencode(self::valueText($value));
        }

        return implode('&', $parts);
    }

    /**
     * Built by hand: a PHP array would turn a key such as "0" into a list index.
     *
     * @param list<array{0: string, 1: bool|int|string}> $flat
     */
    private static function encodeBody(array $flat): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        $parts = [];
        foreach ($flat as [$key, $value]) {
            try {
                $parts[] = json_encode($key, $flags) . ':' . json_encode($value, $flags);
            } catch (\JsonException $e) {
                throw new SigningException('INVALID_STRING', $key . ' is not valid UTF-8');
            }
        }

        return '{' . implode(',', $parts) . '}';
    }
}
