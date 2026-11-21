<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Request;

use SelcomDevelopers\Selcom\Credentials;
use SelcomDevelopers\Selcom\Operation;

/**
 * checkout.stored-cards: the cards the gateway has stored for a buyer.
 */
final class StoredCardsRequest implements OperationRequest
{
    public readonly string $gatewayBuyerUuid;
    public readonly string $buyerUserId;

    /**
     * @param string $buyerUserId Same value as on the buyer's orders
     */
    public function __construct(string $gatewayBuyerUuid, string $buyerUserId)
    {
        $this->gatewayBuyerUuid = Check::required('gateway_buyer_uuid', $gatewayBuyerUuid);
        $this->buyerUserId = Check::required('buyer_userid', $buyerUserId);
    }

    public function operation(): Operation
    {
        return Operation::CheckoutStoredCards;
    }

    public function params(Credentials $credentials): array
    {
        return ['gateway_buyer_uuid' => $this->gatewayBuyerUuid, 'buyer_userid' => $this->buyerUserId];
    }
}
