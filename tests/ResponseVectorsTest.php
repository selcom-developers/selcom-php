<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SelcomDevelopers\Selcom\Client;
use SelcomDevelopers\Selcom\Exception\InvalidResponseException;
use SelcomDevelopers\Selcom\Operation;
use SelcomDevelopers\Selcom\Result\ResponseClassifier;
use SelcomDevelopers\Selcom\Tests\Support\FakeTransport;
use SelcomDevelopers\Selcom\Tests\Support\FixedClock;
use SelcomDevelopers\Selcom\Tests\Support\Fixtures;
use SelcomDevelopers\Selcom\Tests\Support\Vectors;
use SelcomDevelopers\Selcom\Transport\TransportResponse;

/**
 * Runs every scenario in vectors/responses (except the webhook samples) through the classifier
 * and, end to end, through the client with a fake transport.
 */
final class ResponseVectorsTest extends TestCase
{
    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function scenarios(): iterable
    {
        foreach (Vectors::responseFiles() as $file) {
            $fixture = Vectors::load($file);
            if (!isset($fixture['scenarios']) || !is_array($fixture['scenarios'])) {
                continue; // checkout.webhook.json holds deliveries: see WebhookVectorsTest
            }
            $operation = $fixture['operation'];
            self::assertIsString($operation);
            foreach ($fixture['scenarios'] as $scenario) {
                self::assertIsArray($scenario);
                $name = $scenario['name'] ?? null;
                self::assertIsString($name);
                /** @var array<string, mixed> $scenario */
                yield $operation . ' / ' . $name => [$operation, $scenario];
            }
        }
    }

    public function testScenarioCount(): void
    {
        self::assertCount(120, iterator_to_array(self::scenarios()));
    }

    /**
     * @param array<string, mixed> $scenario
     */
    #[DataProvider('scenarios')]
    public function testClassifier(string $operationName, array $scenario): void
    {
        $operation = Operation::from($operationName);
        [$status, $body] = self::input($scenario);
        self::assertIsInt($scenario['http_status']);

        if (isset($scenario['expected_error'])) {
            self::assertSame('INVALID_RESPONSE', $scenario['expected_error']);
            $this->expectException(InvalidResponseException::class);
            ResponseClassifier::classify($operation->kind(), $scenario['http_status'], $body);

            return;
        }
        [$typed] = ResponseClassifier::classify($operation->kind(), $scenario['http_status'], $body);
        self::assertSame($scenario['expected_status'], $typed->value);
        self::assertSame($status, $scenario['http_status']);
    }

    /**
     * @param array<string, mixed> $scenario
     */
    #[DataProvider('scenarios')]
    public function testThroughTheClient(string $operationName, array $scenario): void
    {
        $operation = Operation::from($operationName);
        [, $body] = self::input($scenario);
        self::assertIsInt($scenario['http_status']);
        $transport = (new FakeTransport())->queue(new TransportResponse($scenario['http_status'], $body));
        $client = new Client(Fixtures::config(), $transport, FixedClock::at('2026-11-21T10:30:46+03:00'));

        if (isset($scenario['expected_error'])) {
            $this->expectException(InvalidResponseException::class);
            $client->execute(Fixtures::request($operation));

            return;
        }

        $result = $client->execute(Fixtures::request($operation));
        self::assertSame($scenario['expected_status'], $result->status->value);
        self::assertSame($scenario['http_status'], $result->httpStatus);
        self::assertSame($body, $result->raw);
        self::assertSame($result->status->value !== 'PENDING' && $result->status->value !== 'AMBIGUOUS', $result->isFinal());
    }

    /**
     * @param array<string, mixed> $scenario
     * @return array{int, string}
     */
    private static function input(array $scenario): array
    {
        self::assertIsInt($scenario['http_status']);
        if (isset($scenario['raw_body'])) {
            self::assertIsString($scenario['raw_body']);

            return [$scenario['http_status'], $scenario['raw_body']];
        }

        return [$scenario['http_status'], json_encode($scenario['body'], JSON_THROW_ON_ERROR)];
    }
}
