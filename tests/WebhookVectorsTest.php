<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Tests;

use PHPUnit\Framework\TestCase;
use SelcomDevelopers\Selcom\Tests\Support\FixedClock;
use SelcomDevelopers\Selcom\Tests\Support\Vectors;
use SelcomDevelopers\Selcom\Webhook\WebhookPayload;
use SelcomDevelopers\Selcom\Webhook\WebhookVerdict;
use SelcomDevelopers\Selcom\Webhook\WebhookVerifier;

/**
 * Runs the webhook samples in order through one replay store, with the secret, clock and window
 * from the fixture's `verification` block.
 */
final class WebhookVectorsTest extends TestCase
{
    public function testSamplesInOrder(): void
    {
        $fixture = Vectors::load('responses/checkout.webhook.json');
        $verification = $fixture['verification'];
        self::assertIsArray($verification);
        self::assertIsString($verification['secret']);
        self::assertIsString($verification['now']);
        self::assertIsInt($verification['window_seconds']);
        $samples = $fixture['samples'];
        self::assertIsArray($samples);
        self::assertCount(15, $samples);

        $verifier = new WebhookVerifier(
            $verification['secret'],
            FixedClock::at($verification['now']),
            windowSeconds: $verification['window_seconds'],
        );

        foreach ($samples as $sample) {
            self::assertIsArray($sample);
            self::assertIsString($sample['name']);
            self::assertIsArray($sample['headers']);
            self::assertIsString($sample['raw_body']);
            /** @var array<string, string> $headers */
            $headers = $sample['headers'];

            $verdict = $verifier->verify($headers, $sample['raw_body']);
            self::assertSame($sample['expected_verification'], $verdict->value, $sample['name']);

            $payload = WebhookPayload::fromRawBody($sample['raw_body']);
            self::assertSame($sample['expected_amount_tzs'], $payload->amountTzs, $sample['name']);
        }
    }

    public function testHeaderNamesAreCaseInsensitiveAndListsAreAccepted(): void
    {
        $fixture = Vectors::load('responses/checkout.webhook.json');
        $verification = $fixture['verification'];
        $samples = $fixture['samples'];
        self::assertIsArray($verification);
        self::assertIsArray($samples);
        $first = $samples[0];
        self::assertIsArray($first);
        self::assertIsArray($first['headers']);
        self::assertIsString($first['raw_body']);
        self::assertIsString($verification['secret']);
        self::assertIsString($verification['now']);

        $lower = [];
        foreach ($first['headers'] as $name => $value) {
            self::assertIsString($value);
            $lower[strtolower((string) $name)] = [$value];
        }
        $verifier = new WebhookVerifier($verification['secret'], FixedClock::at($verification['now']));
        self::assertSame(WebhookVerdict::Valid, $verifier->verify($lower, $first['raw_body']));
    }

    public function testVerdictResponseTable(): void
    {
        self::assertSame(200, WebhookVerdict::Valid->httpStatus());
        self::assertSame(200, WebhookVerdict::Unsigned->httpStatus());
        self::assertSame(200, WebhookVerdict::Replay->httpStatus());
        self::assertSame(401, WebhookVerdict::Invalid->httpStatus());
        self::assertSame(401, WebhookVerdict::Stale->httpStatus());
        self::assertTrue(WebhookVerdict::Valid->shouldProcess());
        self::assertTrue(WebhookVerdict::Unsigned->shouldProcess());
        self::assertFalse(WebhookVerdict::Replay->shouldProcess());
        self::assertFalse(WebhookVerdict::Invalid->shouldProcess());
        self::assertFalse(WebhookVerdict::Stale->shouldProcess());
    }

    public function testGarbageIsInvalidNotAnException(): void
    {
        $verifier = new WebhookVerifier('s', FixedClock::at('2026-11-21T10:31:00+03:00'));
        $headers = [
            'Digest' => 'abc',
            'Digest-Method' => 'HS256',
            'Timestamp' => '2026-11-21T10:30:46+03:00',
            'Signed-Fields' => 'a',
        ];
        self::assertSame(WebhookVerdict::Invalid, $verifier->verify($headers, 'not json'));
        self::assertSame(WebhookVerdict::Invalid, $verifier->verify($headers, '[1,2]'));
        self::assertSame(WebhookVerdict::Invalid, $verifier->verify($headers, '{"a":[1]}'));
        self::assertSame(WebhookVerdict::Invalid, $verifier->verify($headers, '{"a":1.5}'));
    }
}
