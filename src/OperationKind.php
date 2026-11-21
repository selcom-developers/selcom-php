<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom;

/**
 * The kind decides how a response is classified, which timeout profile is used
 * and whether anything may ever be retried (spec: docs/api-v1.md).
 */
enum OperationKind: string
{
    case Payment = 'payment';
    case StatusQuery = 'status-query';
    case OrderStatus = 'order-status';
    case Other = 'other';
}
