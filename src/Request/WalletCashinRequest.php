<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Request;

use SelcomDevelopers\Selcom\Credentials;
use SelcomDevelopers\Selcom\Operation;

/**
 * walletcashin.process: credit a mobile wallet from the float. Money-moving: never retried.
 * Amounts are whole TZS.
 */
final class WalletCashinRequest implements OperationRequest
{
    public readonly string $transid;
    public readonly string $utilityCode;
    public readonly string $utilityRef;
    public readonly int $amount;
    public readonly ?string $msisdn;

    /**
     * @param string $transid Your unique ID
     * @param string $utilityCode The destination wallet, for example VMCASHIN or AMCASHIN; or CASHIN to
     *                            let the gateway route by the number's current network
     * @param string $utilityRef The receiving wallet's phone number
     * @param int $amount Whole TZS
     * @param string|null $msisdn The sender's or initiator's phone number
     */
    public function __construct(string $transid, string $utilityCode, string $utilityRef, int $amount, ?string $msisdn = null)
    {
        $this->transid = Check::required('transid', $transid);
        $this->utilityCode = Check::required('utilitycode', $utilityCode);
        $this->utilityRef = Check::required('utilityref', $utilityRef);
        $this->amount = Check::amount('amount', $amount);
        $this->msisdn = Check::optional('msisdn', $msisdn);
    }

    public function operation(): Operation
    {
        return Operation::WalletCashinProcess;
    }

    public function params(Credentials $credentials): array
    {
        $params = [
            'transid' => $this->transid,
            'utilitycode' => $this->utilityCode,
            'utilityref' => $this->utilityRef,
            'amount' => $this->amount,
            'vendor' => $credentials->vendor(),
            'pin' => $credentials->pin(),
        ];
        Check::put($params, 'msisdn', $this->msisdn);

        return $params;
    }
}
