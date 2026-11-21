<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Request;

/**
 * Payment methods a checkout order accepts. Sent as one comma-separated string, not an array.
 */
enum PaymentMethod: string
{
    case All = 'ALL';
    case Masterpass = 'MASTERPASS';
    case Card = 'CARD';
    case MobileMoneyPull = 'MOBILEMONEYPULL';
}
