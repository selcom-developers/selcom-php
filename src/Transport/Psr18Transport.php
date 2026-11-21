<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Transport;

use Http\Discovery\Psr17FactoryDiscovery;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SelcomDevelopers\Selcom\Exception\ConfigurationException;
use SelcomDevelopers\Selcom\Exception\TransportException;

/**
 * The default transport: any PSR-18 client plus PSR-17 factories.
 *
 * PSR-18 has no way to pass timeouts with a request, so they belong to the client, and no request
 * may run without the spec's timeouts. There are therefore three ways to build this transport:
 *
 *  1. `new Psr18Transport()` / `Psr18Transport::discover()`: builds a Guzzle client per timeout
 *     profile (Guzzle must be installed).
 *  2. `new Psr18Transport(fn (TimeoutProfile $p): ClientInterface => ...)`: you build one client
 *     per profile, applying `$p->connectSeconds`, `$p->readSeconds` and, when `$p->freshConnection`
 *     is true (the payment profile), a new connection per request with no reuse.
 *  3. `new Psr18Transport($client, clientManagesTimeouts: true)`: a ready client whose own timeouts
 *     and connection handling you have configured. The flag is required, so this is always a choice.
 *
 * The client you supply must not retry requests on its own (no retry middleware): a repeated POST
 * is a second payment attempt.
 *
 * Responses of any HTTP status are returned, never thrown. A failed send is thrown as
 * TransportException, classified by FailureClassifier, with a short summary as its cause: the
 * client's own exception is not chained, because its message and request carry request data.
 */
final class Psr18Transport implements Transport
{
    /** @var ClientInterface|\Closure(TimeoutProfile): ClientInterface */
    private ClientInterface|\Closure $client;

    /** @var array<string, ClientInterface> */
    private array $built = [];

    private readonly RequestFactoryInterface $requests;
    private readonly StreamFactoryInterface $streams;

    /**
     * @param ClientInterface|\Closure(TimeoutProfile): ClientInterface|null $client Null: a Guzzle client per profile
     * @param bool $clientManagesTimeouts Required `true` when `$client` is a ready client
     *
     * @throws ConfigurationException when no timeout-aware client can be set up
     */
    public function __construct(
        ClientInterface|\Closure|null $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        bool $clientManagesTimeouts = false,
    ) {
        if ($client instanceof ClientInterface && !$clientManagesTimeouts) {
            throw new ConfigurationException(
                'A ready PSR-18 client cannot be given the required timeouts. Pass a closure that builds a client '
                . 'per TimeoutProfile, or pass clientManagesTimeouts: true to confirm that the client applies '
                . 'the connect and read timeouts (and a fresh connection for payments) itself.'
            );
        }
        if ($client === null) {
            if (!class_exists(\GuzzleHttp\Client::class)) {
                throw new ConfigurationException(
                    'No client was given and Guzzle is not installed. Install guzzlehttp/guzzle, or pass a closure '
                    . 'that builds a PSR-18 client per TimeoutProfile.'
                );
            }
            $client = static fn (TimeoutProfile $p): ClientInterface => new \GuzzleHttp\Client(self::guzzleOptions($p));
        }
        $this->client = $client;
        $this->requests = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streams = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    /** Guzzle through the default route; see the class comment. */
    public static function discover(): self
    {
        return new self();
    }

    /**
     * Options of the default Guzzle client for a profile. Guzzle's `timeout` is a total limit, so
     * the read allowance is added to the connect one. Payment requests use a new connection each
     * time and do not leave it open for reuse.
     *
     * @return array<string, mixed>
     */
    public static function guzzleOptions(TimeoutProfile $timeouts): array
    {
        $options = [
            'connect_timeout' => $timeouts->connectSeconds,
            'timeout' => $timeouts->connectSeconds + $timeouts->readSeconds,
            'allow_redirects' => false,
            'http_errors' => false,
        ];
        if ($timeouts->freshConnection && defined('CURLOPT_FRESH_CONNECT') && defined('CURLOPT_FORBID_REUSE')) {
            $options['curl'] = [CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true];
        }

        return $options;
    }

    public function send(#[\SensitiveParameter] TransportRequest $request): TransportResponse
    {
        try {
            $psrRequest = $this->requests->createRequest($request->method, $request->url);
            foreach ($request->headers as $name => $value) {
                $psrRequest = $psrRequest->withHeader($name, $value);
            }
            if ($request->body !== null) {
                $psrRequest = $psrRequest->withBody($this->streams->createStream($request->body));
            }
            $response = $this->clientFor($request->timeouts)->sendRequest($psrRequest);
            $body = (string) $response->getBody();
        } catch (ClientExceptionInterface $e) {
            throw new TransportException(
                FailureClassifier::classify($e),
                'The HTTP client failed before a response arrived: ' . $e::class,
                FailureClassifier::summary($e),
            );
        } catch (\Throwable $e) {
            // Anything else after the request may have been written: the outcome is unknown.
            throw new TransportException(
                TransportFailure::UnknownOutcome,
                'The HTTP exchange failed: ' . $e::class,
                FailureClassifier::summary($e),
            );
        }

        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[(string) $name] = implode(', ', $values);
        }

        return new TransportResponse($response->getStatusCode(), $body, $headers);
    }

    private function clientFor(TimeoutProfile $timeouts): ClientInterface
    {
        if ($this->client instanceof ClientInterface) {
            return $this->client;
        }
        $key = $timeouts->connectSeconds . '/' . $timeouts->readSeconds . '/' . ($timeouts->freshConnection ? 'fresh' : 'reuse');

        return $this->built[$key] ??= ($this->client)($timeouts);
    }
}
