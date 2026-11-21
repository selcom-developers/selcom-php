<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Request;

use SelcomDevelopers\Selcom\Credentials;
use SelcomDevelopers\Selcom\Operation;

/**
 * utilitypayment.query: the verdict on an earlier utilityPayment, by transid. Wait at least
 * 3 minutes after the original request. NOT_FOUND means the gateway never recorded the transid:
 * only then may the same transid be submitted again.
 */
final class UtilityQueryRequest implements OperationRequest
{
    public readonly string $transid;

    public function __construct(string $transid)
    {
        $this->transid = Check::required('transid', $transid);
    }

    public function operation(): Operation
    {
        return Operation::UtilityPaymentQuery;
    }

    public function params(Credentials $credentials): array
    {
        return ['transid' => $this->transid];
    }
}
