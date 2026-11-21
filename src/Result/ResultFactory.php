<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Result;

use SelcomDevelopers\Selcom\Exception\InvalidResponseException;
use SelcomDevelopers\Selcom\Operation;

/**
 * Builds the typed Result for an operation from the HTTP status and the raw body.
 */
final class ResultFactory
{
    /**
     * @param array<string, mixed> $sentParams The parameters that were sent, to fill in the transid or order_id
     *
     * @throws InvalidResponseException when there is no verdict and the operation moves no money
     */
    public static function create(Operation $operation, int $httpStatus, string $rawBody, array $sentParams = []): Result
    {
        [$status, $code, $body] = ResponseClassifier::classify($operation->kind(), $httpStatus, $rawBody);
        $body ??= [];

        $str = static fn (mixed $v): ?string => is_string($v) ? $v : (is_int($v) ? (string) $v : null);
        $data = [];
        if (isset($body['data']) && is_array($body['data']) && array_is_list($body['data'])) {
            foreach ($body['data'] as $entry) {
                if (is_array($entry)) {
                    /** @var array<string, mixed> $entry */
                    $data[] = $entry;
                }
            }
        }

        $class = match ($operation) {
            Operation::CheckoutCreateOrder, Operation::CheckoutCreateOrderMinimal => CreateOrderResult::class,
            Operation::CheckoutOrderStatus => OrderStatusResult::class,
            Operation::CheckoutListOrders => ListOrdersResult::class,
            Operation::CheckoutStoredCards => StoredCardsResult::class,
            Operation::UtilityPaymentLookup, Operation::WalletCashinNameLookup => LookupResult::class,
            Operation::UtilityPaymentProcess, Operation::UtilityPaymentQuery,
            Operation::WalletCashinProcess, Operation::WalletCashinQuery => PayoutResult::class,
            Operation::VendorBalance => BalanceResult::class,
            default => Result::class,
        };

        return new $class(
            $operation,
            $status,
            $code,
            $str($body['result'] ?? null),
            $str($body['message'] ?? null),
            $str($body['reference'] ?? null),
            $str($body['transid'] ?? null) ?? $str($sentParams['transid'] ?? null),
            $str($body['order_id'] ?? null) ?? $str($sentParams['order_id'] ?? null),
            $httpStatus,
            $data,
            $rawBody,
        );
    }
}
