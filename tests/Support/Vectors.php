<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Loads the copy of the shared spec vectors in tests/vectors (refresh with scripts/refresh-spec.sh).
 */
final class Vectors
{
    /**
     * @return array<string, mixed>
     */
    public static function load(string $relativePath): array
    {
        $file = __DIR__ . '/../vectors/' . $relativePath;
        $json = file_get_contents($file);
        Assert::assertIsString($json, $file);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        Assert::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return list<string> the response fixture files, as paths relative to tests/vectors
     */
    public static function responseFiles(): array
    {
        $files = glob(__DIR__ . '/../vectors/responses/*.json');
        Assert::assertIsArray($files);
        sort($files);

        return array_map(static fn (string $f): string => 'responses/' . basename($f), $files);
    }
}
