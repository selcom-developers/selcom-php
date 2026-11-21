<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Transport;

/**
 * Decides whether a failed send provably never reached the gateway.
 *
 * PSR-18 does not say why a send failed, so this looks for evidence in the exception chain.
 * Only clear evidence of a connect-phase failure yields NOT_SENT. Everything else, including a
 * timeout that may have happened while reading, is UNKNOWN_OUTCOME.
 */
final class FailureClassifier
{
    /**
     * cURL errors that happen before any byte of the request is written:
     * 5/6 cannot resolve proxy/host, 7 cannot connect, 35 TLS connect error, 58/60/77 certificate
     * problems at handshake, 51/53/54/59 TLS setup failures, 64/66/90/91 TLS requirements.
     */
    private const CURL_NOT_SENT = [5, 6, 7, 35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 90, 91];

    private const CURL_TIMEOUT = 28;

    private const NOT_SENT_MESSAGE = '/could not resolve host|couldn\'t resolve host|name or service not known'
        . '|getaddrinfo|connection refused|failed to connect to|couldn\'t connect to server'
        . '|no route to host|network is unreachable/i';

    public static function classify(\Throwable $error): TransportFailure
    {
        for ($e = $error; $e !== null; $e = $e->getPrevious()) {
            $context = self::handlerContext($e);
            if ($context !== null) {
                $verdict = self::fromCurlContext($context);
                if ($verdict !== null) {
                    return $verdict;
                }
            }
            if (preg_match(self::NOT_SENT_MESSAGE, $e->getMessage()) === 1
                && preg_match('/timed? ?out|reset|aborted|broken pipe|empty reply/i', $e->getMessage()) !== 1
            ) {
                return TransportFailure::NotSent;
            }
            if (preg_match('/cURL error (\d+)/', $e->getMessage(), $m) === 1
                && in_array((int) $m[1], self::CURL_NOT_SENT, true)
            ) {
                return TransportFailure::NotSent;
            }
        }

        return TransportFailure::UnknownOutcome;
    }

    /**
     * Guzzle (and compatible clients) expose cURL details through getHandlerContext().
     *
     * @return array<mixed>|null
     */
    private static function handlerContext(\Throwable $e): ?array
    {
        if (!method_exists($e, 'getHandlerContext')) {
            return null;
        }
        $context = $e->getHandlerContext();

        return is_array($context) ? $context : null;
    }

    /**
     * @param array<mixed> $context
     */
    private static function fromCurlContext(array $context): ?TransportFailure
    {
        $errno = $context['errno'] ?? null;
        if (!is_int($errno)) {
            return null;
        }
        if (in_array($errno, self::CURL_NOT_SENT, true)) {
            return TransportFailure::NotSent;
        }
        if ($errno === self::CURL_TIMEOUT) {
            // A timeout is NOT_SENT only when the connection was never established.
            $connected = $context['pretransfer_time'] ?? $context['connect_time'] ?? null;
            if ((is_float($connected) || is_int($connected)) && (float) $connected === 0.0) {
                return TransportFailure::NotSent;
            }

            return TransportFailure::UnknownOutcome;
        }

        return TransportFailure::UnknownOutcome;
    }
}
