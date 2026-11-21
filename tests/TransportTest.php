<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SelcomDevelopers\Selcom\Exception\TransportException;
use SelcomDevelopers\Selcom\Transport\FailureClassifier;
use SelcomDevelopers\Selcom\Transport\Psr18Transport;
use SelcomDevelopers\Selcom\Transport\TimeoutProfile;
use SelcomDevelopers\Selcom\Transport\TransportFailure;
use SelcomDevelopers\Selcom\Transport\TransportRequest;

final class TransportTest extends TestCase
{
    private function request(?TimeoutProfile $profile = null): TransportRequest
    {
        return new TransportRequest(
            'POST',
            'https://apigwtest.selcommobile.com/v1/vendor/balance',
            ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'Digest' => 'x'],
            '{"vendor":"TILL123"}',
            $profile ?? new TimeoutProfile(10.0, 10.0),
        );
    }

    private function guzzle(MockHandler $mock): GuzzleClient
    {
        return new GuzzleClient(['handler' => HandlerStack::create($mock), 'http_errors' => false]);
    }

    public function testAnyHttpStatusIsReturnedNotThrown(): void
    {
        $mock = new MockHandler([new Response(502, ['X-Test' => 'a'], '<html>Bad Gateway</html>')]);
        $factory = new HttpFactory();
        $transport = new Psr18Transport($this->guzzle($mock), $factory, $factory);

        $response = $transport->send($this->request());

        self::assertSame(502, $response->status);
        self::assertSame('<html>Bad Gateway</html>', $response->body);
        self::assertSame('a', $response->headers['X-Test']);

        $sent = $mock->getLastRequest();
        self::assertNotNull($sent);
        self::assertSame('POST', $sent->getMethod());
        self::assertSame('{"vendor":"TILL123"}', (string) $sent->getBody());
        self::assertSame('application/json', $sent->getHeaderLine('Content-Type'));
    }

    public function testTheClientIsBuiltPerTimeoutProfile(): void
    {
        $seen = [];
        $factory = new HttpFactory();
        $transport = new Psr18Transport(
            function (TimeoutProfile $p) use (&$seen): ClientInterface {
                $seen[] = [$p->connectSeconds, $p->readSeconds];

                return $this->guzzle(new MockHandler([new Response(200, [], '{}'), new Response(200, [], '{}')]));
            },
            $factory,
            $factory,
        );

        $transport->send($this->request(new TimeoutProfile(15.0, 180.0)));
        $transport->send($this->request(new TimeoutProfile(15.0, 180.0)));
        $transport->send($this->request(new TimeoutProfile(10.0, 10.0)));

        self::assertSame([[15.0, 180.0], [10.0, 10.0]], $seen);
    }

    /** @return iterable<string, array{\Throwable, TransportFailure}> */
    public static function failures(): iterable
    {
        $req = new Request('GET', 'https://example.com');
        yield 'curl 6 resolve' => [new ConnectException('x', $req, null, ['errno' => 6]), TransportFailure::NotSent];
        yield 'curl 7 refused' => [new ConnectException('x', $req, null, ['errno' => 7]), TransportFailure::NotSent];
        yield 'curl 35 tls connect' => [new ConnectException('x', $req, null, ['errno' => 35]), TransportFailure::NotSent];
        yield 'curl 28 before connect' => [new ConnectException('x', $req, null, ['errno' => 28, 'pretransfer_time' => 0.0]), TransportFailure::NotSent];
        yield 'curl 28 after connect' => [new ConnectException('x', $req, null, ['errno' => 28, 'pretransfer_time' => 0.31]), TransportFailure::UnknownOutcome];
        yield 'curl 28 no timings' => [new ConnectException('x', $req, null, ['errno' => 28]), TransportFailure::UnknownOutcome];
        yield 'curl 56 recv failure' => [new ConnectException('x', $req, null, ['errno' => 56]), TransportFailure::UnknownOutcome];
        yield 'curl 52 empty reply' => [new ConnectException('x', $req, null, ['errno' => 52]), TransportFailure::UnknownOutcome];
        yield 'message: cURL error 6' => [new \RuntimeException('cURL error 6: Could not resolve host: apigw.example'), TransportFailure::NotSent];
        yield 'message: refused' => [new \RuntimeException('Failed to connect: Connection refused'), TransportFailure::NotSent];
        yield 'message: reset' => [new \RuntimeException('Connection reset by peer'), TransportFailure::UnknownOutcome];
        yield 'message: read timeout' => [new \RuntimeException('Operation timed out after 180000 ms'), TransportFailure::UnknownOutcome];
        yield 'nothing known' => [new \RuntimeException('boom'), TransportFailure::UnknownOutcome];
        yield 'wrapped cause' => [new \RuntimeException('outer', 0, new ConnectException('x', $req, null, ['errno' => 7])), TransportFailure::NotSent];
    }

    #[DataProvider('failures')]
    public function testFailureClassification(\Throwable $error, TransportFailure $expected): void
    {
        self::assertSame($expected, FailureClassifier::classify($error));
    }

    public function testNetworkFailuresBecomeTransportExceptionsWithTheCauseAttached(): void
    {
        $mock = new MockHandler([new ConnectException('cURL error 7: refused', new Request('POST', 'https://x'), null, ['errno' => 7])]);
        $factory = new HttpFactory();
        $transport = new Psr18Transport($this->guzzle($mock), $factory, $factory);

        try {
            $transport->send($this->request());
            self::fail('expected a TransportException');
        } catch (TransportException $e) {
            self::assertSame(TransportFailure::NotSent, $e->failure);
            self::assertTrue($e->isNotSent());
            self::assertInstanceOf(ConnectException::class, $e->getPrevious());
        }
    }

    public function testAnUnclassifiableClientErrorIsUnknownOutcome(): void
    {
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class ('socket closed') extends \RuntimeException implements NetworkExceptionInterface {
                    public function getRequest(): RequestInterface
                    {
                        return new Request('GET', 'https://x');
                    }
                };
            }
        };
        $factory = new HttpFactory();
        $transport = new Psr18Transport($client, $factory, $factory);

        try {
            $transport->send($this->request());
            self::fail('expected a TransportException');
        } catch (TransportException $e) {
            self::assertSame(TransportFailure::UnknownOutcome, $e->failure);
        }

        $throwing = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new \LogicException('not a PSR-18 exception');
            }
        };
        try {
            (new Psr18Transport($throwing, $factory, $factory))->send($this->request());
            self::fail('expected a TransportException');
        } catch (TransportException $e) {
            self::assertSame(TransportFailure::UnknownOutcome, $e->failure);
        }
    }

    public function testDiscoveryFindsAClientAndFactories(): void
    {
        $transport = Psr18Transport::discover();
        // Only construction is exercised: no network call is made.
        self::assertInstanceOf(Psr18Transport::class, $transport);
    }
}
