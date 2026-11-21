<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom;

/**
 * Checkout orders carry their currency explicitly. Payouts are TZS only and carry none.
 */
enum Currency: string
{
    case TZS = 'TZS';
    case USD = 'USD';
}
