<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Result;

/**
 * Result of utilityPayment, utilityQuery, walletCashin and walletCashinQuery.
 *
 * Money moved or not according to `$status`. PENDING and AMBIGUOUS are settled with the matching
 * query method after 3 minutes or more, using the same transid. Never resubmit under a new ID
 * while the outcome is unknown.
 */
final class PayoutResult extends Result
{
    /** The receipt or token on a successful payment, when the gateway sent one. */
    public function receipt(): ?string
    {
        return $this->entryString('receipt');
    }
}
