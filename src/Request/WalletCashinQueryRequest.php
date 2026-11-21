<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Request;

use SelcomDevelopers\Selcom\Credentials;
use SelcomDevelopers\Selcom\Operation;

/**
 * walletcashin.query: the verdict on an earlier walletCashin, by transid. Same rules as
 * UtilityQueryRequest.
 */
final class WalletCashinQueryRequest implements OperationRequest
{
    public readonly string $transid;

    public function __construct(string $transid)
    {
        $this->transid = Check::required('transid', $transid);
    }

    public function operation(): Operation
    {
        return Operation::WalletCashinQuery;
    }

    public function params(Credentials $credentials): array
    {
        return ['transid' => $this->transid];
    }
}
