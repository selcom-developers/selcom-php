<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Result;

use SelcomDevelopers\Selcom\Amounts;

/**
 * Result of vendorBalance.
 */
final class BalanceResult extends Result
{
    /** The float balance in whole TZS, or null when absent or not a whole amount. */
    public function balance(): ?int
    {
        return Amounts::tryParse($this->entry()['balance'] ?? null);
    }
}
