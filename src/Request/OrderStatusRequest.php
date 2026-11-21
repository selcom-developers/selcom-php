<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Request;

use SelcomDevelopers\Selcom\Credentials;
use SelcomDevelopers\Selcom\Operation;

/**
 * checkout.order-status: the state of one order. The source of truth for checkout.
 */
final class OrderStatusRequest implements OperationRequest
{
    public readonly string $orderId;

    public function __construct(string $orderId)
    {
        $this->orderId = Check::required('order_id', $orderId);
    }

    public function operation(): Operation
    {
        return Operation::CheckoutOrderStatus;
    }

    public function params(Credentials $credentials): array
    {
        return ['order_id' => $this->orderId];
    }
}
