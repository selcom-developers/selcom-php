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
        /**
         * Use a new connection for every request and do not keep it for reuse. Payments and
         * writes use it: a custom client for that profile should disable connection reuse as well.
         */
        public readonly bool $freshConnection = false,
    ) {
        if (!($connectSeconds > 0.0) || !($readSeconds > 0.0)) {
            throw new ConfigurationException('Timeouts must be greater than zero.');
        }
    }
}
