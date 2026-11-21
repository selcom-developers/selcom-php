<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Result;

use SelcomDevelopers\Selcom\Amounts;

/**
 * Result of utilityLookup and walletNameLookup: who a reference belongs to. Bill lookups may
 * add an amount due, an institution, a type and a description (read them from entry()).
 */
final class LookupResult extends Result
{
    public function name(): ?string
    {
        return $this->entryString('name');
    }

    /** The amount due on a bill lookup, in whole TZS, when present. */
    public function amountDue(): ?int
    {
        return Amounts::tryParse($this->entry()['amount'] ?? null);
    }
}
