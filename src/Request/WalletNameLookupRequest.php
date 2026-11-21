<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Request;

use SelcomDevelopers\Selcom\Credentials;
use SelcomDevelopers\Selcom\Operation;

/**
 * walletcashin.namelookup: the registered name on a wallet before sending money to it.
 * Not every wallet code supports lookup. The transid may be reused for the cash-in that follows.
 */
final class WalletNameLookupRequest implements OperationRequest
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
        return Operation::WalletCashinNameLookup;
    }

    public function params(Credentials $credentials): array
    {
        return ['utilitycode' => $this->utilityCode, 'utilityref' => $this->utilityRef, 'transid' => $this->transid];
    }
}
