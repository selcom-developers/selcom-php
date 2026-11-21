<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Request;

use SelcomDevelopers\Selcom\Credentials;
use SelcomDevelopers\Selcom\Operation;

/**
 * checkout.delete-card: remove a stored card.
 */
final class DeleteCardRequest implements OperationRequest
{
    public readonly string $id;
    public readonly string $gatewayBuyerUuid;

    /**
     * @param string|int $id The card's resource ID from storedCards
     */
    public function __construct(string|int $id, string $gatewayBuyerUuid)
    {
        $this->id = Check::required('id', (string) $id);
        $this->gatewayBuyerUuid = Check::required('gateway_buyer_uuid', $gatewayBuyerUuid);
    }

    public function operation(): Operation
    {
        return Operation::CheckoutDeleteCard;
    }

    public function params(Credentials $credentials): array
    {
        return ['id' => $this->id, 'gateway_buyer_uuid' => $this->gatewayBuyerUuid];
    }
}
