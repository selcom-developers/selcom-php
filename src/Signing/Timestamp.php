<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Signing;

use SelcomDevelopers\Selcom\Exception\SigningException;

/**
 * Timestamp format `yyyy-MM-ddTHH:mm:ss+HH:MM` (also accepted: `+HHMM`).
 */
final class Timestamp
{
    public const PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:?\d{2}$/D';

    /** The generated form is always the colon form, truncated to whole seconds. */
    public static function format(\DateTimeInterface $time, \DateTimeZone $zone): string
    {
        $moment = \DateTimeImmutable::createFromInterface($time)->setTimezone($zone);

        return $moment->format('Y-m-d\TH:i:sP');
    }

    public static function isValid(string $timestamp): bool
    {
        return self::parse($timestamp) !== null;
    }

    /**
     * @throws SigningException INVALID_TIMESTAMP
     */
    public static function assertValid(string $timestamp): void
    {
        if (!self::isValid($timestamp)) {
            throw new SigningException(
                'INVALID_TIMESTAMP',
                '"' . $timestamp . '" is not yyyy-MM-ddTHH:mm:ss+HH:MM or +HHMM'
            );
        }
    }

    /** The instant a valid timestamp denotes, or null when it is malformed or impossible. */
    public static function parse(string $timestamp): ?\DateTimeImmutable
    {
        if (preg_match(self::PATTERN, $timestamp) !== 1) {
            return null;
        }
        $format = str_contains(substr($timestamp, 19), ':') ? 'Y-m-d\TH:i:sP' : 'Y-m-d\TH:i:sO';
        $moment = \DateTimeImmutable::createFromFormat($format, $timestamp);
        if ($moment === false) {
            return null;
        }
        // createFromFormat rolls impossible dates over (month 13, 31 February): reject them.
        if ($moment->format('Y-m-d\TH:i:s') !== substr($timestamp, 0, 19)) {
            return null;
        }

        return $moment;
    }
}
