<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SelcomDevelopers\Selcom\Exception\SigningException;
use SelcomDevelopers\Selcom\Signing\Hs256DigestSigner;
use SelcomDevelopers\Selcom\Signing\RequestSigner;
use SelcomDevelopers\Selcom\Tests\Support\Vectors;

/**
 * Runs every case and every reject in the shared signing vectors against the pure signer.
 */
final class SigningVectorsTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function cases(): iterable
    {
        $vectors = Vectors::load('signing.json');
        $cases = $vectors['cases'];
        self::assertIsArray($cases);
        foreach ($cases as $case) {
            self::assertIsArray($case);
            $id = $case['id'] ?? null;
            self::assertIsString($id);
            /** @var array<string, mixed> $case */
            yield $id => [$case];
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function rejects(): iterable
    {
        $vectors = Vectors::load('signing.json');
        $rejects = $vectors['rejects'];
        self::assertIsArray($rejects);
        foreach ($rejects as $reject) {
            self::assertIsArray($reject);
            $id = $reject['id'] ?? null;
            self::assertIsString($id);
            /** @var array<string, mixed> $reject */
            yield $id => [$reject];
        }
    }

    public function testVectorCounts(): void
    {
        self::assertCount(20, iterator_to_array(self::cases()));
        self::assertCount(11, iterator_to_array(self::rejects()));
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('cases')]
    public function testCase(array $case): void
    {
        $expected = $case['expected'];
        self::assertIsArray($expected);
        self::assertIsString($case['method']);
        self::assertIsArray($case['params']);
        self::assertIsString($case['timestamp']);
        self::assertIsString($case['api_key']);
        self::assertIsString($case['secret']);

        $signed = RequestSigner::sign(
            $case['method'],
            $case['params'],
            $case['timestamp'],
            $case['api_key'],
            new Hs256DigestSigner($case['secret']),
        );

        self::assertSame($expected['flattened'], array_map(static fn (array $p): array => [$p[0], $p[1]], $signed->flattened));
        self::assertSame($expected['signed_fields'], $signed->signedFields);
        self::assertSame($expected['signing_string'], $signed->signingString);
        self::assertSame($expected['digest'], $signed->digest);
        self::assertSame($expected['authorization'], $signed->authorization);
        self::assertSame($expected['digest_method'], $signed->digestMethod);
        self::assertSame($case['timestamp'], $signed->headers()['Timestamp']);
        self::assertSame($expected['signed_fields'], $signed->headers()['Signed-Fields']);

        if (isset($expected['query'])) {
            self::assertSame($expected['query'], $signed->query);
        }
        if (isset($expected['body'])) {
            self::assertIsString($expected['body']);
            self::assertNotNull($signed->body);
            // Same keys in the same order with the same values and JSON types; bytes may differ.
            $want = json_decode($expected['body'], true, 512, JSON_THROW_ON_ERROR);
            $got = json_decode($signed->body, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($want);
            self::assertIsArray($got);
            self::assertSame(array_keys($want), array_keys($got));
            self::assertSame($want, $got);
        }
        if ($case['method'] === 'POST') {
            self::assertNull($signed->query);
        } else {
            self::assertNull($signed->body);
        }
    }

    /**
     * @param array<string, mixed> $reject
     */
    #[DataProvider('rejects')]
    public function testReject(array $reject): void
    {
        self::assertIsString($reject['method']);
        self::assertIsArray($reject['params']);
        self::assertIsString($reject['timestamp']);
        self::assertIsString($reject['api_key']);
        self::assertIsString($reject['secret']);
        self::assertIsString($reject['error']);

        try {
            RequestSigner::sign(
                $reject['method'],
                $reject['params'],
                $reject['timestamp'],
                $reject['api_key'],
                new Hs256DigestSigner($reject['secret']),
            );
            self::fail('The signer must refuse ' . get_debug_type($reject['id']));
        } catch (SigningException $e) {
            self::assertSame($reject['error'], $e->errorCode);
        }
    }

    public function testBothOffsetFormsAreAcceptedAndColonFormIsGenerated(): void
    {
        $signer = new Hs256DigestSigner('s');
        $colon = RequestSigner::sign('GET', ['a' => '1'], '2026-11-21T10:30:46+03:00', 'k', $signer);
        $basic = RequestSigner::sign('GET', ['a' => '1'], '2026-11-21T10:30:46+0300', 'k', $signer);
        self::assertSame('timestamp=2026-11-21T10:30:46+03:00&a=1', $colon->signingString);
        self::assertSame('timestamp=2026-11-21T10:30:46+0300&a=1', $basic->signingString);
    }

    public function testImpossibleDateIsRejected(): void
    {
        $this->expectException(SigningException::class);
        RequestSigner::sign('GET', ['a' => '1'], '2026-13-45T10:30:46+03:00', 'k', new Hs256DigestSigner('s'));
    }

    public function testNumericLookingKeyKeepsItsPlaceInTheBody(): void
    {
        $signed = RequestSigner::sign('POST', ['23' => 'x', 'b' => 'y'], '2026-11-21T10:30:46+03:00', 'k', new Hs256DigestSigner('s'));
        self::assertSame('{"23":"x","b":"y"}', $signed->body);
        self::assertSame('23,b', $signed->signedFields);
    }
}
