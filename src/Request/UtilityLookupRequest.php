<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Request;

use SelcomDevelopers\Selcom\Credentials;
use SelcomDevelopers\Selcom\Operation;

/**
 * utilitypayment.lookup: check a customer reference before paying. Not every utility code
 * supports lookup. The same transid may then be used for the payment that follows.
 */
final class UtilityLookupRequest implements OperationRequest
{
    public readonly string $utilityCode;
    public readonly string $utilityRef;
    public readonly string $transid;

    public function __construct(string $utilityCode, string $utilityRef, string $transid)
    {
        $this->utilityCode = Check::required('utilitycode', $utilityCode);
        $this->utilityRef = Check::required('utilityref', $utilityRef);
        $this->transid = Check::required('transid', $transid);
    }

    public function operation(): Operation
    {
        return Operation::UtilityPaymentLookup;
    }

    public function params(Credentials $credentials): array
    {
        return ['utilitycode' => $this->utilityCode, 'utilityref' => $this->utilityRef, 'transid' => $this->transid];
    }
}
