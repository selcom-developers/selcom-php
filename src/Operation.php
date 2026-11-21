<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom;

/**
 * The 16 v1 operations. Values match the fixture file names in the spec.
 */
enum Operation: string
{
    case CheckoutCreateOrder = 'checkout.create-order';
    case CheckoutCreateOrderMinimal = 'checkout.create-order-minimal';
    case CheckoutWalletPayment = 'checkout.wallet-payment';
    case CheckoutCardPayment = 'checkout.card-payment';
    case CheckoutOrderStatus = 'checkout.order-status';
    case CheckoutCancelOrder = 'checkout.cancel-order';
    case CheckoutListOrders = 'checkout.list-orders';
    case CheckoutStoredCards = 'checkout.stored-cards';
    case CheckoutDeleteCard = 'checkout.delete-card';
    case UtilityPaymentProcess = 'utilitypayment.process';
    case UtilityPaymentLookup = 'utilitypayment.lookup';
    case UtilityPaymentQuery = 'utilitypayment.query';
    case WalletCashinProcess = 'walletcashin.process';
    case WalletCashinNameLookup = 'walletcashin.namelookup';
    case WalletCashinQuery = 'walletcashin.query';
    case VendorBalance = 'vendor.balance';

    public function method(): string
    {
        return match ($this) {
            self::CheckoutCreateOrder,
            self::CheckoutCreateOrderMinimal,
            self::CheckoutWalletPayment,
            self::CheckoutCardPayment,
            self::UtilityPaymentProcess,
            self::WalletCashinProcess,
            self::VendorBalance => 'POST',
            self::CheckoutCancelOrder,
            self::CheckoutDeleteCard => 'DELETE',
            self::CheckoutOrderStatus,
            self::CheckoutListOrders,
            self::CheckoutStoredCards,
            self::UtilityPaymentLookup,
            self::UtilityPaymentQuery,
            self::WalletCashinNameLookup,
            self::WalletCashinQuery => 'GET',
        };
    }

    public function path(): string
    {
        return '/v1/' . str_replace('.', '/', $this->value);
    }

    public function kind(): OperationKind
    {
        return match ($this) {
            self::CheckoutWalletPayment,
            self::CheckoutCardPayment,
            self::UtilityPaymentProcess,
            self::WalletCashinProcess => OperationKind::Payment,
            self::UtilityPaymentQuery,
            self::WalletCashinQuery => OperationKind::StatusQuery,
            self::CheckoutOrderStatus => OperationKind::OrderStatus,
            default => OperationKind::Other,
        };
    }

    /**
     * Creates, cancels and deletes: no money moves, but they are not idempotent,
     * so the client never retries them.
     */
    public function isWrite(): bool
    {
        return match ($this) {
            self::CheckoutCreateOrder,
            self::CheckoutCreateOrderMinimal,
            self::CheckoutCancelOrder,
            self::CheckoutDeleteCard => true,
            default => false,
        };
    }

    /**
     * Whether the client may repeat the request itself after a NOT_SENT error,
     * when the caller opted in. Never for payments or writes.
     */
    public function isRetryableWhenNotSent(): bool
    {
        return $this->kind() !== OperationKind::Payment && !$this->isWrite();
    }

    /** Checkout operations may use a second credential set. */
    public function isCheckout(): bool
    {
        return str_starts_with($this->value, 'checkout.');
    }
}
