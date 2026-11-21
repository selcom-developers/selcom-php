<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Result;

/**
 * Result of orderStatus. This is the source of truth for checkout: webhooks and push responses
 * are only hints until this confirms them.
 *
 * `$status` is the verdict on the order (from `payment_status`), not on the query. A FAILED here
 * describes the latest attempt; an order that has not expired can still be paid by a later one.
 * To close an order as failed for good, cancel it: a completed order cannot be cancelled.
 */
final class OrderStatusResult extends Result
{
    /** Null when the response carries no order (NOT_FOUND, or a failed query). */
    public function order(): ?OrderSummary
    {
        return $this->data === [] ? null : OrderSummary::fromEntry($this->entry());
    }
}
