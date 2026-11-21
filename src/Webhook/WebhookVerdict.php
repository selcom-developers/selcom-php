<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Webhook;

/**
 * The verdict on one webhook delivery (spec: docs/webhooks.md).
 *
 * A webhook is a hint, not proof: even on VALID, confirm with orderStatus before fulfilling.
 */
enum WebhookVerdict: string
{
    case Valid = 'VALID';
    /** No Digest header. Nothing to check; the orderStatus confirmation is the only protection. */
    case Unsigned = 'UNSIGNED';
    case Invalid = 'INVALID';
    case Stale = 'STALE';
    case Replay = 'REPLAY';

    /** The HTTP status to answer with. */
    public function httpStatus(): int
    {
        return match ($this) {
            self::Valid, self::Unsigned, self::Replay => 200,
            self::Invalid, self::Stale => 401,
        };
    }

    /** Whether to process the delivery (then confirm with orderStatus). REPLAY was already handled. */
    public function shouldProcess(): bool
    {
        return $this === self::Valid || $this === self::Unsigned;
    }
}
