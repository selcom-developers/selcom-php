<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Webhook;

/**
 * Remembers webhook digests for the length of the verification window. A cache with a
 * time-to-live is enough; your own deduplication on (order_id, transid) is the durable guard.
 */
interface ReplayStore
{
    /**
     * Record the digest and report whether it was already recorded and still live.
     *
     * Must be atomic if deliveries can be handled concurrently (for example a cache `add`).
     *
     * @return bool true when the digest had been seen within its time-to-live (a replay)
     */
    public function seen(string $digest, int $ttlSeconds): bool;
}
