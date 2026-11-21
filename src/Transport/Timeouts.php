<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Transport;

use SelcomDevelopers\Selcom\OperationKind;

/**
 * The two timeout profiles from the spec.
 *
 *  - query: status-query, order-status and other operations, 10 s connect / 10 s read.
 *  - payment: money-moving operations, 15 s connect / 180 s read. The long read timeout is
 *    deliberate: timing out early does not stop a payment, it only turns a known answer
 *    into an unknown one.
 */
final class Timeouts
{
    public readonly TimeoutProfile $query;
    public readonly TimeoutProfile $payment;

    public function __construct(?TimeoutProfile $query = null, ?TimeoutProfile $payment = null)
    {
        $this->query = $query ?? new TimeoutProfile(10.0, 10.0);
        $this->payment = $payment === null
            ? new TimeoutProfile(15.0, 180.0, true)
            : new TimeoutProfile($payment->connectSeconds, $payment->readSeconds, true);
    }

    public function forKind(OperationKind $kind): TimeoutProfile
    {
        return $kind === OperationKind::Payment ? $this->payment : $this->query;
    }
}
