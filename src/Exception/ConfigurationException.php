<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Exception;

/**
 * The configuration is incomplete or invalid. Raised at construction time,
 * never on the first request.
 */
final class ConfigurationException extends \InvalidArgumentException implements SelcomException
{
}
