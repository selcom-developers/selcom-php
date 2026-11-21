<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Request;

use SelcomDevelopers\Selcom\Credentials;
use SelcomDevelopers\Selcom\Operation;

/**
 * checkout.cancel-order: cancel an unpaid order. A successful cancel proves the order was never
 * paid and never will be, which makes it the safe way to close an order as failed. A failure can
 * mean the order was already paid: check orderStatus before treating it as unpaid.
 */
final class CancelOrderRequest implements OperationRequest
{
    public readonly string $orderId;

    public function __construct(string $orderId)
    {
        $this->orderId = Check::required('order_id', $orderId);
    }

    public function operation(): Operation
    {
        return Operation::CheckoutCancelOrder;
    }

    public function params(Credentials $credentials): array
    {
        return ['order_id' => $this->orderId];
    }
}
