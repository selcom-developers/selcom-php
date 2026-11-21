<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Result;

/**
 * `data[0]` of create-order and create-order-minimal.
 */
final class CreatedOrder
{
    public function __construct(
        public readonly ?string $gatewayBuyerUuid,
        public readonly ?string $paymentToken,
        public readonly ?string $qr,
        /** The hosted payment page URL, decoded from base64. Null when absent or not valid base64. */
        public readonly ?string $paymentGatewayUrl,
        /** The value exactly as the gateway sent it. */
        public readonly ?string $paymentGatewayUrlRaw,
    ) {
    }

    /**
     * @param array<string, mixed> $entry
     */
    public static function fromEntry(array $entry): self
    {
        $str = static fn (string $key): ?string => isset($entry[$key]) && is_scalar($entry[$key]) ? (string) $entry[$key] : null;
        $raw = $str('payment_gateway_url');
        $decoded = $raw === null ? false : base64_decode($raw, true);

        return new self(
            $str('gateway_buyer_uuid'),
            $str('payment_token'),
            $str('qr'),
            $decoded === false ? null : $decoded,
            $raw,
        );
    }
}
