<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Result;

use SelcomDevelopers\Selcom\Exception\InvalidResponseException;
use SelcomDevelopers\Selcom\OperationKind;
use SelcomDevelopers\Selcom\Status;

/**
 * Turns a gateway response into one of the five typed statuses (spec: docs/results.md).
 *
 * A pure function of the operation kind and the response. It classifies by `resultcode` only:
 * the `result` and `message` texts are for people. The HTTP status never decides the verdict.
 */
final class ResponseClassifier
{
    /** `payment_status` values of an order, after trimming and upper-casing. */
    private const PAYMENT_STATUS = [
        'COMPLETED' => Status::Success,
        'COMPLETE' => Status::Success,
        'PENDING' => Status::Pending,
        'INPROGRESS' => Status::Pending,
        'CANCELLED' => Status::Failed,
        'CANCELED' => Status::Failed,
        'CANCEL' => Status::Failed,
        'USERCANCELLED' => Status::Failed,
        'USERCANCELED' => Status::Failed,
        'REJECTED' => Status::Failed,
        'FAILED' => Status::Failed,
    ];

    /**
     * @return array{0: Status, 1: string|null, 2: array<string, mixed>|null} status, normalised resultcode, decoded body
     *
     * @throws InvalidResponseException when there is no verdict and the operation moves no money
     */
    public static function classify(OperationKind $kind, int $httpStatus, string $rawBody): array
    {
        $body = self::decode($rawBody);
        $code = $body === null ? null : self::normaliseResultCode($body['resultcode'] ?? null);

        if ($body === null || $code === null) {
            if ($kind === OperationKind::Other) {
                throw new InvalidResponseException($httpStatus, $rawBody);
            }

            // Something answered, but not with a verdict: the money may have moved.
            return [Status::Ambiguous, $code, $body];
        }

        return [self::statusFor($kind, $code, $body), $code, $body];
    }

    /** Trim; a JSON number becomes three digits (0 becomes "000"); anything else is no code. */
    public static function normaliseResultCode(mixed $value): ?string
    {
        if (is_int($value)) {
            return $value >= 0 ? sprintf('%03d', $value) : null;
        }
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function statusFor(OperationKind $kind, string $code, array $body): Status
    {
        if ($code === '000') {
            return $kind === OperationKind::OrderStatus ? self::orderStatus($body) : Status::Success;
        }
        if ($code === '111' || $code === '927') {
            return Status::Pending;
        }
        if ($code === '999') {
            return Status::Ambiguous;
        }
        if ($code === '404' && ($kind === OperationKind::StatusQuery || $kind === OperationKind::OrderStatus)) {
            return Status::NotFound;
        }
        if ($kind === OperationKind::OrderStatus) {
            // The query failed, which says nothing about the order.
            return Status::Ambiguous;
        }

        return Status::Failed;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function orderStatus(array $body): Status
    {
        $data = $body['data'] ?? null;
        if (!is_array($data) || ($data !== [] && !array_is_list($data))) {
            return Status::Ambiguous;
        }
        if ($data === []) {
            return Status::NotFound;
        }
        $entry = $data[0];
        $status = is_array($entry) ? ($entry['payment_status'] ?? null) : null;

        return self::paymentStatus(is_string($status) ? $status : null);
    }

    /**
     * The typed verdict for an order's `payment_status` text (case-insensitive, trimmed).
     * Unknown or missing values are AMBIGUOUS.
     */
    public static function paymentStatus(?string $paymentStatus): Status
    {
        if ($paymentStatus === null) {
            return Status::Ambiguous;
        }

        return self::PAYMENT_STATUS[strtoupper(trim($paymentStatus))] ?? Status::Ambiguous;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decode(string $rawBody): ?array
    {
        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
