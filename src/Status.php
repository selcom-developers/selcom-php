<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom;

/**
 * The five typed statuses every operation returns (spec: docs/results.md).
 */
enum Status: string
{
    /** It happened. */
    case Success = 'SUCCESS';
    /** Accepted, still processing. Wait for the webhook or query after 3 minutes or more. */
    case Pending = 'PENDING';
    /** Nobody knows yet. Query after 3 minutes or more. Never resubmit. */
    case Ambiguous = 'AMBIGUOUS';
    /** It did not happen. A new attempt is a new transaction with a new ID. */
    case Failed = 'FAILED';
    /** The gateway has no record of the ID. The only case where the same ID may be submitted again. */
    case NotFound = 'NOT_FOUND';

    public function isFinal(): bool
    {
        return match ($this) {
            self::Success, self::Failed, self::NotFound => true,
            self::Pending, self::Ambiguous => false,
        };
    }
}
