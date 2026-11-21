<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Webhook;

use Psr\Clock\ClockInterface;
use SelcomDevelopers\Selcom\Exception\ConfigurationException;
use SelcomDevelopers\Selcom\Exception\SigningException;
use SelcomDevelopers\Selcom\Signing\Hs256DigestSigner;
use SelcomDevelopers\Selcom\Signing\RequestSigner;
use SelcomDevelopers\Selcom\Signing\Timestamp;
use SelcomDevelopers\Selcom\SystemClock;

/**
 * Verifies checkout webhook deliveries (spec: docs/webhooks.md).
 *
 * `verify($headers, $rawBody)` takes the raw body exactly as received, before any framework
 * parses or rewrites it. It never throws on bad input: bad input is INVALID.
 *
 * Which key Selcom signs webhooks with is an open question in the spec; the samples assume the
 * merchant's API secret. Until you have confirmed it in the sandbox, consider running in report-only
 * mode: log the verdict and always process, then rely on orderStatus.
 */
final class WebhookVerifier
{
    private readonly ClockInterface $clock;
    private readonly ReplayStore $replayStore;

    public function __construct(
        private readonly string $secret,
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
        $this->clock = $clock ?? new SystemClock();
        $this->replayStore = $replayStore ?? new InMemoryReplayStore($this->clock);
    }

    /**
     * @param array<string, string|list<string>> $headers Header names are matched case-insensitively
     */
    public function verify(array $headers, string $rawBody): WebhookVerdict
    {
        $digest = self::header($headers, 'Digest');
        if ($digest === null) {
            return WebhookVerdict::Unsigned;
        }
        if ($digest === '' || self::header($headers, 'Digest-Method') !== 'HS256') {
            return WebhookVerdict::Invalid;
        }

        $timestamp = self::header($headers, 'Timestamp') ?? '';
        $signedFields = self::header($headers, 'Signed-Fields') ?? '';
        $moment = Timestamp::parse($timestamp);
        $body = json_decode($rawBody, true);
        if ($moment === null || !is_array($body) || ($body !== [] && array_is_list($body)) || $signedFields === '') {
            return WebhookVerdict::Invalid;
        }

        $flat = [];
        foreach (explode(',', $signedFields) as $field) {
            [$found, $value] = self::lookup($body, $field);
            if (!$found) {
                return WebhookVerdict::Invalid;
            }
            try {
                $flat[] = [$field, RequestSigner::normalise($field, $value)];
            } catch (SigningException) {
                return WebhookVerdict::Invalid;
            }
        }

        $expected = (new Hs256DigestSigner($this->secret))->sign(RequestSigner::signingString($timestamp, $flat));
        if (!hash_equals($expected, $digest)) {
            return WebhookVerdict::Invalid;
        }

        if (abs($this->clock->now()->getTimestamp() - $moment->getTimestamp()) > $this->windowSeconds) {
            return WebhookVerdict::Stale;
        }
        if ($this->replayStore->seen($digest, $this->windowSeconds)) {
            return WebhookVerdict::Replay;
        }

        return WebhookVerdict::Valid;
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
