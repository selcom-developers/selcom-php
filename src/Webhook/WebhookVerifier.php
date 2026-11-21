<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Webhook;

use Psr\Clock\ClockInterface;
use SelcomDevelopers\Selcom\Exception\ConfigurationException;
use SelcomDevelopers\Selcom\Signing\Hs256DigestSigner;
use SelcomDevelopers\Selcom\Signing\RequestSigner;
use SelcomDevelopers\Selcom\Signing\Timestamp;
use SelcomDevelopers\Selcom\SystemClock;

/**
 * Verifies checkout webhook deliveries (spec: docs/webhooks.md, v0.2.1).
 *
 * `verify($headers, $rawBody)` takes the raw body exactly as received, before any framework
 * parses or rewrites it. It never throws: any input it cannot use is INVALID.
 *
 * Which key Selcom signs webhooks with is an open question in the spec; the samples assume the
 * merchant's API secret. Until you have confirmed it in the sandbox, consider running in report-only
 * mode: log the verdict and always process, then rely on orderStatus.
 */
final class WebhookVerifier
{
    /** Inputs larger than these are INVALID without being parsed further. */
    private const MAX_BODY_BYTES = 1048576;
    private const MAX_SIGNED_FIELDS = 64;

    private const REQUIRED_FIELDS = ['order_id', 'transid'];

    private readonly Hs256DigestSigner $signer;
    private readonly ClockInterface $clock;
    private readonly ReplayStore $replayStore;

    public function __construct(
        #[\SensitiveParameter] string $secret,
        ?ClockInterface $clock = null,
        ?ReplayStore $replayStore = null,
        private readonly int $windowSeconds = 300,
    ) {
        if ($secret === '') {
            throw new ConfigurationException('The webhook secret must not be empty.');
        }
        if ($windowSeconds < 1) {
            throw new ConfigurationException('The webhook window must be at least one second.');
        }
        $this->signer = new Hs256DigestSigner($secret);
        $this->clock = $clock ?? new SystemClock();
        $this->replayStore = $replayStore ?? new InMemoryReplayStore($this->clock);
    }

    /**
     * @param array<string, string|list<string>> $headers Header names are matched case-insensitively
     */
    public function verify(#[\SensitiveParameter] array $headers, #[\SensitiveParameter] string $rawBody): WebhookVerdict
    {
        try {
            return $this->decide($headers, $rawBody);
        } catch (\Throwable) {
            return WebhookVerdict::Invalid;
        }
    }

    /** @return array<string, int> */
    public function __debugInfo(): array
    {
        return ['windowSeconds' => $this->windowSeconds];
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    private function decide(array $headers, string $rawBody): WebhookVerdict
    {
        // 1. No Digest header: nothing to check.
        $digest = self::header($headers, 'Digest');
        if ($digest === null) {
            return WebhookVerdict::Unsigned;
        }
        // 2. Empty digest, or a method this verifier is not configured for.
        if ($digest === '' || self::header($headers, 'Digest-Method') !== $this->signer->method()) {
            return WebhookVerdict::Invalid;
        }

        // 3. Timestamp format, body shape, Signed-Fields present.
        $timestamp = self::header($headers, 'Timestamp') ?? '';
        $signedFields = self::header($headers, 'Signed-Fields') ?? '';
        $moment = Timestamp::parse($timestamp);
        if ($moment === null || $signedFields === '' || strlen($rawBody) > self::MAX_BODY_BYTES) {
            return WebhookVerdict::Invalid;
        }
        $body = json_decode($rawBody, true);
        if (!is_array($body) || ($body !== [] && array_is_list($body))) {
            return WebhookVerdict::Invalid;
        }

        // 4. order_id and transid must always be signed.
        $names = explode(',', $signedFields);
        if (count($names) > self::MAX_SIGNED_FIELDS) {
            return WebhookVerdict::Invalid;
        }
        foreach (self::REQUIRED_FIELDS as $required) {
            if (!in_array($required, $names, true)) {
                return WebhookVerdict::Invalid;
            }
        }

        // 5 and 6. Values come from the body; normalisation is cheap and bounded, and runs
        // before the digest check. Names and values may not contain "&".
        $flat = [];
        foreach ($names as $field) {
            [$found, $value] = self::lookup($body, $field);
            if (!$found || str_contains($field, '&')) {
                return WebhookVerdict::Invalid;
            }
            $normalised = self::normalise($value);
            if ($normalised === null || str_contains(RequestSigner::valueText($normalised), '&')) {
                return WebhookVerdict::Invalid;
            }
            $flat[] = [$field, $normalised];
        }

        // 7. Constant-time digest comparison.
        $expected = $this->signer->sign(RequestSigner::signingString($timestamp, $flat));
        if (!hash_equals($expected, $digest)) {
            return WebhookVerdict::Invalid;
        }

        // 8. Window, in either direction.
        $now = $this->clock->now()->getTimestamp();
        $signedAt = $moment->getTimestamp();
        if (abs($now - $signedAt) > $this->windowSeconds) {
            return WebhookVerdict::Stale;
        }

        // 9. Replay: keep the digest until max(now, signed time) + window.
        if ($this->replayStore->seen($digest, max($now, $signedAt) + $this->windowSeconds)) {
            return WebhookVerdict::Replay;
        }

        return WebhookVerdict::Valid;
    }

    /**
     * The scalar that is signed, or null when the value cannot be used. Numbers must be whole
     * and within 2^53 - 1; this is decided on the decoded value, without expanding anything.
     */
    private static function normalise(mixed $value): bool|int|string|null
    {
        if ($value === null) {
            return '';
        }
        if (is_string($value) || is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return abs($value) > RequestSigner::MAX_SAFE_INTEGER ? null : $value;
        }
        if (is_float($value)) {
            // Zero is refused too: a float zero may be a number that underflowed while decoding.
            if (!is_finite($value) || $value === 0.0 || floor($value) !== $value
                || abs($value) > (float) RequestSigner::MAX_SAFE_INTEGER
            ) {
                return null;
            }

            return (int) $value;
        }

        return null;
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                if (is_array($value)) {
                    return $value[0] ?? '';
                }

                return $value;
            }
        }

        return null;
    }

    /**
     * A name is looked up flat first, then (for dotted names) by walking nested objects.
     *
     * @param array<int|string, mixed> $body
     * @return array{0: bool, 1: mixed}
     */
    private static function lookup(array $body, string $field): array
    {
        if (array_key_exists($field, $body)) {
            return [true, $body[$field]];
        }
        $node = $body;
        foreach (explode('.', $field) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return [false, null];
            }
            $node = $node[$part];
        }

        return [true, $node];
    }
}
