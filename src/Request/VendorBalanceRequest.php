<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Request;

use SelcomDevelopers\Selcom\Credentials;
use SelcomDevelopers\Selcom\Operation;

/**
 * vendor.balance: the float account balance. A POST because it carries the PIN; it moves no money.
 */
final class VendorBalanceRequest implements OperationRequest
{
    public readonly string $transid;

    /**
     * @param string $transid A unique ID
     */
    public function __construct(string $transid)
    {
        $this->transid = Check::required('transid', $transid);
    }

    public function operation(): Operation
    {
        return Operation::VendorBalance;
    }

    public function params(Credentials $credentials): array
    {
        return ['vendor' => $credentials->vendor(), 'pin' => $credentials->pin(), 'transid' => $this->transid];
    }
}
