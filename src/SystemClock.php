<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom;

use Psr\Clock\ClockInterface;

/**
 * The real clock. Tests pass their own ClockInterface instead.
 */
final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now');
    }
}
