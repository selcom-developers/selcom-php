<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Transport;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SelcomDevelopers\Selcom\Exception\TransportException;

/**
 * The default transport: any PSR-18 client plus PSR-17 factories.
 *
 * PSR-18 has no way to pass timeouts with a request, so they belong to the client. There are
 * three ways to get the spec's timeouts:
 *
 *  1. `Psr18Transport::discover()` builds a Guzzle client per timeout profile when Guzzle is
 *     installed, and otherwise uses whatever client php-http/discovery finds (its own timeouts).
 *  2. Pass a closure `fn (TimeoutProfile $p): ClientInterface` and build one client per profile.
 *  3. Pass a ready client that you have configured yourself.
 *
 * Responses of any HTTP status are returned, never thrown. A failed send is thrown as
 * TransportException, classified by FailureClassifier. Nothing is retried here.
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
     * @param ClientInterface|\Closure(TimeoutProfile): ClientInterface|null $client Null: use discovery
     */
    public function __construct(
        ClientInterface|\Closure|null $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->client = $client ?? self::defaultClientFactory();
        $this->requests = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streams = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    public static function discover(): self
    {
        return new self();
    }

    public function send(TransportRequest $request): TransportResponse
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
                $e,
            );
        } catch (\Throwable $e) {
            // Anything else after the request may have been written: the outcome is unknown.
            throw new TransportException(
                TransportFailure::UnknownOutcome,
                'The HTTP exchange failed: ' . $e::class,
                $e,
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
        $key = $timeouts->connectSeconds . '/' . $timeouts->readSeconds;

        return $this->built[$key] ??= ($this->client)($timeouts);
    }

    /**
     * @return \Closure(TimeoutProfile): ClientInterface
     */
    private static function defaultClientFactory(): \Closure
    {
        return static function (TimeoutProfile $timeouts): ClientInterface {
            if (class_exists(\GuzzleHttp\Client::class)) {
                // Guzzle's `timeout` is a total limit, so the read allowance is added to the connect one.
                return new \GuzzleHttp\Client([
                    'connect_timeout' => $timeouts->connectSeconds,
                    'timeout' => $timeouts->connectSeconds + $timeouts->readSeconds,
                    'allow_redirects' => false,
                    'http_errors' => false,
                ]);
            }

            return Psr18ClientDiscovery::find();
        };
    }
}
