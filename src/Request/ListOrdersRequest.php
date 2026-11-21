<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Request;

use SelcomDevelopers\Selcom\Credentials;
use SelcomDevelopers\Selcom\Operation;

/**
 * checkout.list-orders: the vendor's orders created in a date range. Useful for reconciliation.
 */
final class ListOrdersRequest implements OperationRequest
{
    public readonly string $fromDate;
    public readonly string $toDate;

    /**
     * @param string $fromDate YYYY-MM-DD
     * @param string $toDate YYYY-MM-DD
     */
    public function __construct(string $fromDate, string $toDate)
    {
        $this->fromDate = Check::date('fromdate', $fromDate);
        $this->toDate = Check::date('todate', $toDate);
    }

    public function operation(): Operation
    {
        return Operation::CheckoutListOrders;
    }

    public function params(Credentials $credentials): array
    {
        return ['fromdate' => $this->fromDate, 'todate' => $this->toDate];
    }
}
