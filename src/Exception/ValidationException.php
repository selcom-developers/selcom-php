<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Exception;

/**
 * A request object was built with a missing or malformed field. Nothing was sent.
 */
final class ValidationException extends \InvalidArgumentException implements SelcomException
{
}
