<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Redaction;

/**
 * Redaction for logs (spec: docs/client-requirements.md). Use it for anything you store or log:
 * headers, flat or nested JSON bodies, query strings and raw response bodies.
 *
 *  - Secrets (pin, secret, api_secret, api_key, Authorization, Digest, card_token): `[REDACTED]`.
 *  - Phone numbers (msisdn, phone, buyer_phone, and utilityref on wallet cash-in): all but the
 *    last 4 characters replaced by `*`, for example `********0001`.
 *  - E-mail (buyer_email, email): first character of the local part, then the domain: `t***@example.com`.
 *  - Names (buyer_name, firstname, lastname, name): the first character, then `***`.
 *
 * Keys are matched case-insensitively, and by their last segment, so `billing.phone` and
 * `shipping.firstname` are covered. A string that is not valid JSON or a query string is left alone
 * by redactBody(): pass structured data when you can.
 */
final class Redactor
{
    public const REDACTED = '[REDACTED]';

    private const SECRET_KEYS = ['pin', 'secret', 'api_secret', 'api_key', 'authorization', 'digest', 'card_token'];
    private const PHONE_KEYS = ['msisdn', 'phone', 'buyer_phone'];
    private const EMAIL_KEYS = ['buyer_email', 'email'];
    private const NAME_KEYS = ['buyer_name', 'firstname', 'lastname', 'name'];

    /**
     * Redact request or response headers.
     *
     * @param array<string, string|list<string>> $headers
     * @return array<string, string|list<string>>
     */
    public function redactHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            if (in_array(strtolower($name), self::SECRET_KEYS, true)) {
                $out[$name] = is_array($value) ? array_fill(0, count($value), self::REDACTED) : self::REDACTED;
            } else {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    /**
     * Redact a flat or nested parameter or body array.
     *
     * @param array<int|string, mixed> $data
     * @return array<int|string, mixed>
     */
    public function redactParams(array $data): array
    {
        $isCashin = $this->isCashin($data);
        $out = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $out[$key] = $this->redactParams($value);
                continue;
            }
            $out[$key] = $this->redactValue((string) $key, $value, $isCashin);
        }

        return $out;
    }

    /**
     * Redact a query string, keeping its order and encoding of the other fields.
     */
    public function redactQuery(string $query): string
    {
        $pairs = [];
        $isCashin = false;
        foreach (explode('&', $query) as $part) {
            if ($part === '') {
                continue;
            }
            $kv = explode('=', $part, 2);
            $pairs[] = [rawurldecode(str_replace('+', '%20', $kv[0])), rawurldecode(str_replace('+', '%20', $kv[1] ?? ''))];
            if (strtolower(end($pairs)[0]) === 'utilitycode' && self::isCashinCode(end($pairs)[1])) {
                $isCashin = true;
            }
        }
        $out = [];
        foreach ($pairs as [$key, $value]) {
            $redacted = $this->redactValue($key, $value, $isCashin);
            $out[] = rawurlencode($key) . '=' . rawurlencode(is_string($redacted) ? $redacted : '');
        }

        return implode('&', $out);
    }

    /**
     * Redact a JSON body (request or response). Returns the redacted JSON, or the placeholder
     * "[UNPARSEABLE BODY OMITTED]" when the text is not JSON, so nothing sensitive leaks through.
     */
    public function redactJson(string $json): string
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return '[UNPARSEABLE BODY OMITTED]';
        }

        $encoded = json_encode($this->redactParams($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $encoded === false ? '{}' : $encoded;
    }

    private function redactValue(string $key, mixed $value, bool $isCashin): mixed
    {
        $name = strtolower($key);
        $dot = strrpos($name, '.');
        if ($dot !== false) {
            $name = substr($name, $dot + 1);
        }

        if ($value === null) {
            return null;
        }
        if (in_array($name, self::SECRET_KEYS, true)) {
            return self::REDACTED;
        }
        if (!is_scalar($value)) {
            return $value;
        }
        $text = (string) $value;
        if (in_array($name, self::PHONE_KEYS, true) || ($isCashin && $name === 'utilityref')) {
            return self::phone($text);
        }
        if (in_array($name, self::EMAIL_KEYS, true)) {
            return self::email($text);
        }
        if (in_array($name, self::NAME_KEYS, true)) {
            return self::name($text);
        }

        return $value;
    }

    /**
     * @param array<int|string, mixed> $data
     */
    private function isCashin(array $data): bool
    {
        foreach ($data as $key => $value) {
            if (strtolower((string) $key) === 'utilitycode' && is_string($value) && self::isCashinCode($value)) {
                return true;
            }
        }

        return false;
    }

    private static function isCashinCode(string $code): bool
    {
        return str_ends_with(strtoupper(trim($code)), 'CASHIN');
    }

    private static function phone(string $text): string
    {
        $chars = self::chars($text);
        $count = count($chars);
        if ($count <= 4) {
            return str_repeat('*', $count);
        }

        return str_repeat('*', $count - 4) . implode('', array_slice($chars, -4));
    }

    private static function email(string $text): string
    {
        $at = strrpos($text, '@');
        if ($at === false || $at === 0) {
            return self::REDACTED;
        }

        return (self::chars($text)[0] ?? '') . '***' . substr($text, $at);
    }

    private static function name(string $text): string
    {
        $chars = self::chars($text);

        return $chars === [] ? '' : $chars[0] . '***';
    }

    /** @return list<string> */
    private static function chars(string $text): array
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return $chars === false ? str_split($text) : $chars;
    }
}
