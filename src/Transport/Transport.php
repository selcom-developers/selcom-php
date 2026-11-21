<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Transport;

use SelcomDevelopers\Selcom\Exception\TransportException;

/**
 * The replaceable HTTP layer: plug in a custom client, a proxy, logging or a test fake.
 *
 * Contract:
 *  - Return a TransportResponse for every HTTP response, whatever the status.
 *  - Throw TransportException when no response was received, classified as NOT_SENT only when
 *    the request provably never left; when in doubt, UNKNOWN_OUTCOME.
 *  - Apply `$request->timeouts`. Never retry.
 */
interface Transport
{
    /**
     * @throws TransportException
     */
    public function send(TransportRequest $request): TransportResponse;
}
