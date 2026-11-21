<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Transport;

use SelcomDevelopers\Selcom\Exception\ConfigurationException;

/**
 * Connect and read timeouts in seconds. No operation runs without one.
 */
final class TimeoutProfile
{
    public function __construct(
        public readonly float $connectSeconds,
        public readonly float $readSeconds,
    ) {
        if (!($connectSeconds > 0.0) || !($readSeconds > 0.0)) {
            throw new ConfigurationException('Timeouts must be greater than zero.');
        }
    }
}
