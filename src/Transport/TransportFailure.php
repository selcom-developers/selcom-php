<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Transport;

/**
 * What a transport error means for the request that was being sent.
 */
enum TransportFailure: string
{
    /**
     * DNS failure, connection refused, connect timeout, TLS handshake failure, proxy refusal:
     * the request provably never reached the gateway. Sending it again with the same ID is safe.
     */
    case NotSent = 'NOT_SENT';

    /**
     * Anything after the request may have been written: read or write timeout, connection reset,
     * truncated response, TLS error mid-stream. The gateway may have acted: treat as AMBIGUOUS,
     * query, never resend. Also the answer whenever the HTTP library cannot tell.
     */
    case UnknownOutcome = 'UNKNOWN_OUTCOME';
}
