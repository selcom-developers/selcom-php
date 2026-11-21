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
    /** @var array<string, int> digest => last second the entry is live (unix time) */
    private array $liveThrough = [];

    private readonly ClockInterface $clock;

    public function __construct(?ClockInterface $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
    }

    public function seen(string $digest, int $keepUntil): bool
    {
        $now = $this->clock->now()->getTimestamp();
        foreach ($this->liveThrough as $key => $last) {
            if ($last < $now) {
                unset($this->liveThrough[$key]);
            }
        }
        if (isset($this->liveThrough[$digest])) {
            return true;
        }
        $this->liveThrough[$digest] = $keepUntil;

        return false;
    }
}
