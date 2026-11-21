<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Webhook;

use Psr\Clock\ClockInterface;
use SelcomDevelopers\Selcom\SystemClock;

/**
 * A per-process replay store. Fine for tests and single-process workers; use a shared cache
 * (Redis, APCu) behind the ReplayStore interface for PHP-FPM, where each request is a new process.
 */
final class InMemoryReplayStore implements ReplayStore
{
    /** @var array<string, int> digest => expiry (unix seconds) */
    private array $expires = [];

    private readonly ClockInterface $clock;

    public function __construct(?ClockInterface $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
    }

    public function seen(string $digest, int $ttlSeconds): bool
    {
        $now = $this->clock->now()->getTimestamp();
        foreach ($this->expires as $key => $expiry) {
            if ($expiry <= $now) {
                unset($this->expires[$key]);
            }
        }
        if (isset($this->expires[$digest])) {
            return true;
        }
        $this->expires[$digest] = $now + $ttlSeconds;

        return false;
    }
}
