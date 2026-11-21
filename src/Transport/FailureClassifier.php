<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Transport;

/**
 * Decides whether a failed send provably never reached the gateway.
 *
 * PSR-18 does not say why a send failed, so this looks for evidence in the exception chain.
 * Only a failure in the connect phase (name lookup, refused connection, handshake or certificate
 * failure, a connect timeout) yields NOT_SENT. Everything else, including a timeout that may have
 * happened while reading, a reset, a record-level TLS error and anything unrecognised, is
 * UNKNOWN_OUTCOME.
 */
final class FailureClassifier
{
    /**
     * cURL errors that can only happen before any byte of the request is written:
     * 5/6 cannot resolve proxy/host, 7 cannot connect, 35 TLS handshake error, 51/58/59/60/64/66/77
     * certificate and TLS setup problems, 90/91 pinned key and status checks.
     */
    private const CURL_CONNECT_PHASE = [5, 6, 7, 35, 51, 58, 59, 60, 64, 66, 77, 90, 91];

    private const CURL_TIMEOUT = 28;

    /** Messages that name a connect-phase failure and nothing later. */
    private const CONNECT_MESSAGE = '/could not resolve host|couldn\'t resolve host|name or service not known'
        . '|getaddrinfo|connection refused|failed to connect to|couldn\'t connect to server/i';

    /** cURL counters of request bytes: both are zero when nothing of the request was written. */
    private const SENT_KEYS = ['request_size', 'size_upload'];

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
            $message = $e->getMessage();
            if (preg_match('/cURL error (\d+)/', $message, $m) === 1
                && in_array((int) $m[1], self::CURL_CONNECT_PHASE, true)
            ) {
                return TransportFailure::NotSent;
            }
            if (preg_match(self::CONNECT_MESSAGE, $message) === 1
                && preg_match('/timed? ?out|reset|aborted|broken pipe|empty reply|bad record|decrypt/i', $message) !== 1
            ) {
                return TransportFailure::NotSent;
            }
        }

        return TransportFailure::UnknownOutcome;
    }

    /**
     * A short description of the failure that is safe to keep: the exception classes and the cURL
     * error number, never the message or the request.
     */
    public static function summary(\Throwable $error): \RuntimeException
    {
        $classes = [];
        $errno = null;
        for ($e = $error; $e !== null; $e = $e->getPrevious()) {
            $classes[] = $e::class;
            $context = self::handlerContext($e);
            if ($errno === null && $context !== null && is_int($context['errno'] ?? null)) {
                $errno = $context['errno'];
            }
            if ($errno === null && preg_match('/cURL error (\d+)/', $e->getMessage(), $m) === 1) {
                $errno = (int) $m[1];
            }
        }

        return new \RuntimeException(
            'Underlying error: ' . implode(' <- ', $classes) . ($errno === null ? '' : ', cURL errno ' . $errno)
        );
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
        if (self::sentSomething($context)) {
            return TransportFailure::UnknownOutcome;
        }
        if (in_array($errno, self::CURL_CONNECT_PHASE, true)) {
            return TransportFailure::NotSent;
        }
        if ($errno === self::CURL_TIMEOUT) {
            // A timeout is a connect timeout only when cURL reports that no request bytes were
            // written: both counters are present and zero. Missing counters prove nothing.
            if (self::reportsNothingSent($context)) {
                return TransportFailure::NotSent;
            }
        }

        return TransportFailure::UnknownOutcome;
    }

    /**
     * @param array<mixed> $context
     */
    private static function sentSomething(array $context): bool
    {
        foreach (self::SENT_KEYS as $key) {
            $value = $context[$key] ?? null;
            if ((is_float($value) || is_int($value)) && (float) $value > 0.0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $context
     */
    private static function reportsNothingSent(array $context): bool
    {
        foreach (self::SENT_KEYS as $key) {
            $value = $context[$key] ?? null;
            if (!(is_float($value) || is_int($value)) || (float) $value !== 0.0) {
                return false;
            }
        }

        return true;
    }
}
