<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Exception;

/**
 * The replay store failed while a webhook delivery was being verified, so no verdict could be
 * reached. This is a server-side problem, not a bad delivery: answer 5xx so the gateway delivers
 * again.
 */
final class ReplayStoreException extends \RuntimeException implements SelcomException
{
}
