<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Request;

use SelcomDevelopers\Selcom\Credentials;
use SelcomDevelopers\Selcom\Operation;

/**
 * checkout.wallet-payment: push a USSD prompt to the buyer's mobile wallet for an existing order.
 * Money-moving: never retried. Normally answered PENDING; confirm with orderStatus.
 */
final class WalletPaymentRequest implements OperationRequest
{
    public readonly string $transid;
    public readonly string $orderId;
    public readonly string $msisdn;

    /**
     * @param string $transid Your unique ID for this payment attempt
     * @param string $msisdn The wallet to charge, 255...
     */
    public function __construct(string $transid, string $orderId, string $msisdn)
    {
        $this->transid = Check::required('transid', $transid);
        $this->orderId = Check::required('order_id', $orderId);
        $this->msisdn = Check::required('msisdn', $msisdn);
    }

    public function operation(): Operation
    {
        return Operation::CheckoutWalletPayment;
    }

    public function params(Credentials $credentials): array
    {
        return ['transid' => $this->transid, 'order_id' => $this->orderId, 'msisdn' => $this->msisdn];
    }
}
