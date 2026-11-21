<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Webhook;

use SelcomDevelopers\Selcom\Amounts;
use SelcomDevelopers\Selcom\Exception\ValidationException;
use SelcomDevelopers\Selcom\Result\ResponseClassifier;

/**
 * The documented fields of a checkout webhook. Unknown fields are ignored (they stay in `$fields`).
 *
 * Nothing here is proof of payment. Confirm with orderStatus, and take the amount from there.
 */
final class WebhookPayload
{
    /**
     * @param array<string, mixed> $fields The whole decoded body
     */
    public function __construct(
        public readonly ?string $orderId,
        public readonly ?string $transid,
        public readonly ?string $reference,
        public readonly ?string $result,
        public readonly ?string $resultCode,
        public readonly ?string $paymentStatus,
        public readonly ?string $channel,
        /** Whole TZS, parsed strictly from text such as "10,000"; null when absent or not a whole amount. */
        public readonly ?int $amountTzs,
        public readonly ?string $phone,
        public readonly array $fields,
    ) {
    }

    /**
     * @throws ValidationException when the body is not a JSON object
     */
    public static function fromRawBody(string $rawBody): self
    {
        $body = json_decode($rawBody, true);
        if (!is_array($body) || ($body !== [] && array_is_list($body))) {
            throw new ValidationException('The webhook body is not a JSON object.');
        }
        /** @var array<string, mixed> $body */
        $str = static fn (string $key): ?string => isset($body[$key]) && is_scalar($body[$key]) ? (string) $body[$key] : null;

        return new self(
            $str('order_id'),
            $str('transid'),
            $str('reference'),
            $str('result'),
            ResponseClassifier::normaliseResultCode($body['resultcode'] ?? null),
            $str('payment_status'),
            $str('channel'),
            Amounts::tryParse($body['amount'] ?? null),
            $str('phone') ?? $str('msisdn'),
            $body,
        );
    }

    /** The delivery's key for deduplication: (order_id, transid). Null when either is missing. */
    public function dedupeKey(): ?string
    {
        return $this->orderId === null || $this->transid === null ? null : $this->orderId . '|' . $this->transid;
    }
}
