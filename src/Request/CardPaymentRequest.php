<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Request;

use SelcomDevelopers\Selcom\Credentials;
use SelcomDevelopers\Selcom\Operation;

/**
 * checkout.card-payment: charge a stored card for an existing order without the payment page.
 * Money-moving: never retried. Normally answered PENDING; confirm with orderStatus.
 */
final class CardPaymentRequest implements OperationRequest
{
    public readonly string $transid;
    public readonly string $orderId;
    public readonly string $cardToken;
    public readonly string $buyerUserId;
    public readonly string $gatewayBuyerUuid;

    /**
     * @param string $cardToken From storedCards (sensitive: never log it)
     * @param string $buyerUserId Same value as on the buyer's orders
     * @param string $gatewayBuyerUuid From the buyer's first order
     */
    public function __construct(string $transid, string $orderId, string $cardToken, string $buyerUserId, string $gatewayBuyerUuid)
    {
        $this->transid = Check::required('transid', $transid);
        $this->orderId = Check::required('order_id', $orderId);
        $this->cardToken = Check::required('card_token', $cardToken);
        $this->buyerUserId = Check::required('buyer_userid', $buyerUserId);
        $this->gatewayBuyerUuid = Check::required('gateway_buyer_uuid', $gatewayBuyerUuid);
    }

    public function operation(): Operation
    {
        return Operation::CheckoutCardPayment;
    }

    public function params(Credentials $credentials): array
    {
        return [
            'transid' => $this->transid,
            'vendor' => $credentials->vendor(),
            'order_id' => $this->orderId,
            'card_token' => $this->cardToken,
            'buyer_userid' => $this->buyerUserId,
            'gateway_buyer_uuid' => $this->gatewayBuyerUuid,
        ];
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['transid' => $this->transid, 'orderId' => $this->orderId, 'cardToken' => '[REDACTED]'];
    }
}
