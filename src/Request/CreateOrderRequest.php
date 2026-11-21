<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Request;

use SelcomDevelopers\Selcom\Credentials;
use SelcomDevelopers\Selcom\Currency;
use SelcomDevelopers\Selcom\Exception\ValidationException;
use SelcomDevelopers\Selcom\Operation;

/**
 * checkout.create-order: an order payable by any enabled method, cards included. The response
 * links to the hosted payment page. Send the full billing group for any order that may be paid
 * by card. URLs are given in plain form and encoded to base64 for you.
 */
final class CreateOrderRequest implements OperationRequest
{
    public readonly string $orderId;
    public readonly string $buyerEmail;
    public readonly string $buyerName;
    public readonly string $buyerPhone;
    public readonly int $amount;
    /** @var list<PaymentMethod> */
    public readonly array $paymentMethods;
    public readonly int $noOfItems;
    public readonly ?string $buyerUserId;
    public readonly ?string $gatewayBuyerUuid;
    public readonly ?string $redirectUrl;
    public readonly ?string $cancelUrl;
    public readonly ?string $webhookUrl;
    public readonly ?string $buyerRemarks;
    public readonly ?string $merchantRemarks;
    public readonly ?string $headerColour;
    public readonly ?string $linkColour;
    public readonly ?string $buttonColour;
    public readonly ?int $expiryMinutes;

    /**
     * @param int $amount Whole units of $currency
     * @param string $buyerPhone MSISDN in international form, 255...
     * @param list<PaymentMethod> $paymentMethods At least one
     * @param string|null $buyerUserId The buyer's ID in your system; null for guest checkout
     * @param string|null $gatewayBuyerUuid Returned on the buyer's first order; send it on later orders
     */
    public function __construct(
        string $orderId,
        string $buyerEmail,
        string $buyerName,
        string $buyerPhone,
        int $amount,
        public readonly Currency $currency,
        array $paymentMethods,
        public readonly Address $billing,
        int $noOfItems,
        ?string $buyerUserId = null,
        ?string $gatewayBuyerUuid = null,
        ?string $redirectUrl = null,
        ?string $cancelUrl = null,
        ?string $webhookUrl = null,
        public readonly ?Address $shipping = null,
        ?string $buyerRemarks = null,
        ?string $merchantRemarks = null,
        ?string $headerColour = null,
        ?string $linkColour = null,
        ?string $buttonColour = null,
        ?int $expiryMinutes = null,
    ) {
        $this->orderId = Check::required('order_id', $orderId);
        $this->buyerEmail = Check::required('buyer_email', $buyerEmail);
        $this->buyerName = Check::required('buyer_name', $buyerName);
        $this->buyerPhone = Check::required('buyer_phone', $buyerPhone);
        $this->amount = Check::amount('amount', $amount);
        if ($paymentMethods === []) {
            throw new ValidationException('payment_methods needs at least one method.');
        }
        $this->paymentMethods = array_values($paymentMethods);
        $this->noOfItems = Check::amount('no_of_items', $noOfItems);
        $this->buyerUserId = Check::optional('buyer_userid', $buyerUserId);
        $this->gatewayBuyerUuid = Check::optional('gateway_buyer_uuid', $gatewayBuyerUuid);
        $this->redirectUrl = Check::url('redirect_url', $redirectUrl);
        $this->cancelUrl = Check::url('cancel_url', $cancelUrl);
        $this->webhookUrl = Check::url('webhook', $webhookUrl);
        $this->buyerRemarks = Check::optional('buyer_remarks', $buyerRemarks);
        $this->merchantRemarks = Check::optional('merchant_remarks', $merchantRemarks);
        $this->headerColour = Check::optional('header_colour', $headerColour);
        $this->linkColour = Check::optional('link_colour', $linkColour);
        $this->buttonColour = Check::optional('button_colour', $buttonColour);
        $this->expiryMinutes = $expiryMinutes === null ? null : Check::amount('expiry', $expiryMinutes);
    }

    public function operation(): Operation
    {
        return Operation::CheckoutCreateOrder;
    }

    public function params(Credentials $credentials): array
    {
        $params = ['vendor' => $credentials->vendor(), 'order_id' => $this->orderId];
        $params['buyer_email'] = $this->buyerEmail;
        $params['buyer_name'] = $this->buyerName;
        Check::put($params, 'buyer_userid', $this->buyerUserId);
        $params['buyer_phone'] = $this->buyerPhone;
        Check::put($params, 'gateway_buyer_uuid', $this->gatewayBuyerUuid);
        $params['amount'] = $this->amount;
        $params['currency'] = $this->currency->value;
        $params['payment_methods'] = implode(',', array_map(static fn (PaymentMethod $m): string => $m->value, $this->paymentMethods));
        Check::put($params, 'redirect_url', $this->redirectUrl);
        Check::put($params, 'cancel_url', $this->cancelUrl);
        Check::put($params, 'webhook', $this->webhookUrl);
        $params['billing'] = $this->billing->toParams();
        if ($this->shipping !== null) {
            $params['shipping'] = $this->shipping->toParams();
        }
        Check::put($params, 'buyer_remarks', $this->buyerRemarks);
        Check::put($params, 'merchant_remarks', $this->merchantRemarks);
        $params['no_of_items'] = $this->noOfItems;
        Check::put($params, 'header_colour', $this->headerColour);
        Check::put($params, 'link_colour', $this->linkColour);
        Check::put($params, 'button_colour', $this->buttonColour);
        Check::put($params, 'expiry', $this->expiryMinutes);

        return $params;
    }
}
