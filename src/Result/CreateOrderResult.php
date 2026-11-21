<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Result;

/**
 * Result of createOrder and createOrderMinimal.
 */
final class CreateOrderResult extends Result
{
    /** Null when the response carries no order data (for example on failure). */
    public function order(): ?CreatedOrder
    {
        return $this->data === [] ? null : CreatedOrder::fromEntry($this->entry());
    }
}
