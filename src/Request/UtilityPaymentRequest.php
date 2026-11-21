<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Request;

use SelcomDevelopers\Selcom\Credentials;
use SelcomDevelopers\Selcom\Operation;

/**
 * utilitypayment.process: pay a bill or buy a prepaid service from the float. Money-moving:
 * never retried, not even after a timeout. Amounts are whole TZS.
 */
final class UtilityPaymentRequest implements OperationRequest
{
    public readonly string $transid;
    public readonly string $utilityCode;
    public readonly string $utilityRef;
    public readonly int $amount;
    public readonly ?string $msisdn;

    /**
     * @param string $transid Your unique ID; also the key for the status query
     * @param string $utilityCode Which service, for example LUKU or TOP
     * @param string $utilityRef The customer's reference: meter number, phone number, control number
     * @param int $amount Whole TZS
     * @param string|null $msisdn The end customer's phone number
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
        return Operation::UtilityPaymentProcess;
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
