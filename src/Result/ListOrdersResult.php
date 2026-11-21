<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Result;

/**
 * Result of listOrders: one entry per order. Useful for reconciliation.
 */
final class ListOrdersResult extends Result
{
    /** @return list<OrderSummary> */
    public function orders(): array
    {
        return array_map(static fn (array $entry): OrderSummary => OrderSummary::fromEntry($entry), $this->data);
    }
}
