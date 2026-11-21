<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Result;

use SelcomDevelopers\Selcom\Amounts;
use SelcomDevelopers\Selcom\Status;

/**
 * One order, as returned by order-status (`data[0]`) and list-orders (one entry per order).
 */
final class OrderSummary
{
    public function __construct(
        public readonly ?string $orderId,
        public readonly ?string $creationDate,
        /** Whole units, or null when the gateway's text is not a whole amount. */
        public readonly ?int $amount,
        /** The gateway's text, unchanged. */
        public readonly ?string $paymentStatus,
        /** The typed verdict on this order, mapped as in docs/results.md. */
        public readonly Status $status,
        public readonly ?string $transid,
        public readonly ?string $channel,
        public readonly ?string $reference,
        /** The payer's number: published as `msisdn`, seen as `phone` in samples. */
        public readonly ?string $payerPhone,
    ) {
    }

    /**
     * @param array<string, mixed> $entry
     */
    public static function fromEntry(array $entry): self
    {
        $str = static fn (string $key): ?string => isset($entry[$key]) && is_scalar($entry[$key]) ? (string) $entry[$key] : null;
        $paymentStatus = $str('payment_status');

        return new self(
            $str('order_id'),
            $str('creation_date'),
            Amounts::tryParse($entry['amount'] ?? null),
            $paymentStatus,
            ResponseClassifier::paymentStatus($paymentStatus),
            $str('transid'),
            $str('channel'),
            $str('reference'),
            $str('msisdn') ?? $str('phone'),
        );
    }
}
