<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Webhook;

/**
 * Remembers webhook digests until a given time. A cache with a time-to-live is enough; your own
 * deduplication on (order_id, transid) is the durable guard.
 */
interface ReplayStore
{
    /**
     * Record the digest until `$keepUntil` and report whether it was already recorded and still live.
     *
     * The verifier passes max(now, signed timestamp) + window. An entry is live through `$keepUntil`
     * itself. Must be atomic if deliveries can be handled concurrently (for example a cache `add`).
     *
     * @param int $keepUntil Unix time in seconds
     * @return bool true when the digest had been recorded and is still live (a replay)
     */
    public function seen(string $digest, int $keepUntil): bool;
}
