<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Request;

use SelcomDevelopers\Selcom\Credentials;
use SelcomDevelopers\Selcom\Currency;
use SelcomDevelopers\Selcom\Operation;

/**
 * checkout.create-order-minimal: an order for non-card payment (mobile-wallet push, or a token or
 * QR code the buyer pays with). The first step of a USSD push; the second is wallet-payment.
 */
final class CreateOrderMinimalRequest implements OperationRequest
{
    public readonly string $orderId;
    public readonly string $buyerEmail;
    public readonly string $buyerName;
    public readonly string $buyerPhone;
    public readonly int $amount;
    public readonly int $noOfItems;
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
     * @param mixed $amount An int, a string of digits or an integral float Whole units of $currency; a fraction is an error, never rounded
     * @param string $buyerPhone MSISDN in international form, 255...
     */
    public function __construct(
        string $orderId,
        string $buyerEmail,
        string $buyerName,
        string $buyerPhone,
        mixed $amount,
        public readonly Currency $currency,
        int $noOfItems,
        ?string $redirectUrl = null,
        ?string $cancelUrl = null,
        ?string $webhookUrl = null,
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
        $this->noOfItems = Check::amount('no_of_items', $noOfItems);
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
        return Operation::CheckoutCreateOrderMinimal;
    }

    public function params(Credentials $credentials): array
    {
        $params = [
            'vendor' => $credentials->vendor(),
            'order_id' => $this->orderId,
            'buyer_email' => $this->buyerEmail,
            'buyer_name' => $this->buyerName,
            'buyer_phone' => $this->buyerPhone,
            'amount' => $this->amount,
            'currency' => $this->currency->value,
        ];
        Check::put($params, 'redirect_url', $this->redirectUrl);
        Check::put($params, 'cancel_url', $this->cancelUrl);
        Check::put($params, 'webhook', $this->webhookUrl);
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
