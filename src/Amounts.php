<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom;

/**
 * Strict parsing of amounts that arrive as text, for example `"10000"` or `"10,000"`.
 * Money is whole units: anything else is rejected, never rounded.
 */
final class Amounts
{
    private const PATTERN = '/^(?:\d{1,3}(?:,\d{3})+|\d+)(?:\.0+)?$/D';

    /**
     * Whole units from a response or callback field, or null when it is not a whole amount.
     */
    public static function tryParse(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $text = trim($value);
        if (preg_match(self::PATTERN, $text) !== 1) {
            return null;
        }
        $digits = str_replace(',', '', explode('.', $text, 2)[0]);
        // Stay well inside the PHP int range: more than 18 significant digits is not a real amount.
        if (strlen(ltrim($digits, '0')) > 18) {
            return null;
        }

        return (int) $digits;
    }

    /**
     * @throws \InvalidArgumentException when the value is not a whole amount
     */
    public static function parse(mixed $value): int
    {
        return self::tryParse($value) ?? throw new \InvalidArgumentException('Not a whole-unit amount.');
    }
}
