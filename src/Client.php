<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom;

use Psr\Clock\ClockInterface;
use SelcomDevelopers\Selcom\Exception\InvalidResponseException;
use SelcomDevelopers\Selcom\Exception\SigningException;
use SelcomDevelopers\Selcom\Exception\TransportException;
use SelcomDevelopers\Selcom\Exception\ValidationException;
use SelcomDevelopers\Selcom\Request\CancelOrderRequest;
use SelcomDevelopers\Selcom\Request\CardPaymentRequest;
use SelcomDevelopers\Selcom\Request\CreateOrderMinimalRequest;
use SelcomDevelopers\Selcom\Request\CreateOrderRequest;
use SelcomDevelopers\Selcom\Request\DeleteCardRequest;
use SelcomDevelopers\Selcom\Request\ListOrdersRequest;
use SelcomDevelopers\Selcom\Request\OperationRequest;
use SelcomDevelopers\Selcom\Request\OrderStatusRequest;
use SelcomDevelopers\Selcom\Request\StoredCardsRequest;
use SelcomDevelopers\Selcom\Request\UtilityLookupRequest;
use SelcomDevelopers\Selcom\Request\UtilityPaymentRequest;
use SelcomDevelopers\Selcom\Request\UtilityQueryRequest;
use SelcomDevelopers\Selcom\Request\VendorBalanceRequest;
use SelcomDevelopers\Selcom\Request\WalletCashinQueryRequest;
use SelcomDevelopers\Selcom\Request\WalletCashinRequest;
use SelcomDevelopers\Selcom\Request\WalletNameLookupRequest;
use SelcomDevelopers\Selcom\Request\WalletPaymentRequest;
use SelcomDevelopers\Selcom\Result\BalanceResult;
use SelcomDevelopers\Selcom\Result\CreateOrderResult;
use SelcomDevelopers\Selcom\Result\ListOrdersResult;
use SelcomDevelopers\Selcom\Result\LookupResult;
use SelcomDevelopers\Selcom\Result\OrderStatusResult;
use SelcomDevelopers\Selcom\Result\PayoutResult;
use SelcomDevelopers\Selcom\Result\Result;
use SelcomDevelopers\Selcom\Result\ResultFactory;
use SelcomDevelopers\Selcom\Result\StoredCardsResult;
use SelcomDevelopers\Selcom\Signing\DigestSigner;
use SelcomDevelopers\Selcom\Signing\Hs256DigestSigner;
use SelcomDevelopers\Selcom\Signing\RequestSigner;
use SelcomDevelopers\Selcom\Signing\Timestamp;
use SelcomDevelopers\Selcom\Transport\Psr18Transport;
use SelcomDevelopers\Selcom\Transport\Transport;
use SelcomDevelopers\Selcom\Transport\TransportRequest;

/**
 * The Selcom client: one typed method per operation. Stateless between calls: it does not lock,
 * queue or deduplicate. Persist your transid or order_id before calling (see the README).
 *
 * Every method signs the request, sends it once, and returns a typed Result. It never retries a
 * payment or a write. A failed send is thrown as TransportException; read its `failure`.
 */
final class Client
{
    public const VERSION = '0.1.0';

    private readonly Transport $transport;
    private readonly ClockInterface $clock;

    /** @var \Closure(Credentials): DigestSigner */
    private readonly \Closure $digestSignerFactory;

    /**
     * @param Transport|null $transport Null: PSR-18 through php-http/discovery
     * @param ClockInterface|null $clock Null: the system clock
     * @param \Closure(Credentials): DigestSigner|null $digestSignerFactory Null: HS256 with the secret
     */
    public function __construct(
        private readonly Config $config,
        ?Transport $transport = null,
        ?ClockInterface $clock = null,
        ?\Closure $digestSignerFactory = null,
    ) {
        $this->transport = $transport ?? Psr18Transport::discover();
        $this->clock = $clock ?? new SystemClock();
        $this->digestSignerFactory = $digestSignerFactory
            ?? static fn (Credentials $c): DigestSigner => new Hs256DigestSigner($c->secret());
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['version' => self::VERSION, 'config' => $this->config];
    }

    // ---- Checkout ---------------------------------------------------------------------------

    public function createOrder(CreateOrderRequest $request): CreateOrderResult
    {
        return $this->run($request, CreateOrderResult::class);
    }

    public function createOrderMinimal(CreateOrderMinimalRequest $request): CreateOrderResult
    {
        return $this->run($request, CreateOrderResult::class);
    }

    /** Money-moving: never retried. */
    public function walletPayment(WalletPaymentRequest $request): Result
    {
        return $this->execute($request);
    }

    /** Money-moving: never retried. */
    public function cardPayment(CardPaymentRequest $request): Result
    {
        return $this->execute($request);
    }

    public function orderStatus(OrderStatusRequest $request): OrderStatusResult
    {
        return $this->run($request, OrderStatusResult::class);
    }

    public function cancelOrder(CancelOrderRequest $request): Result
    {
        return $this->execute($request);
    }

    public function listOrders(ListOrdersRequest $request): ListOrdersResult
    {
        return $this->run($request, ListOrdersResult::class);
    }

    public function storedCards(StoredCardsRequest $request): StoredCardsResult
    {
        return $this->run($request, StoredCardsResult::class);
    }

    public function deleteCard(DeleteCardRequest $request): Result
    {
        return $this->execute($request);
    }

    // ---- Payouts ----------------------------------------------------------------------------

    /** Money-moving: never retried. */
    public function utilityPayment(UtilityPaymentRequest $request): PayoutResult
    {
        return $this->run($request, PayoutResult::class);
    }

    public function utilityLookup(UtilityLookupRequest $request): LookupResult
    {
        return $this->run($request, LookupResult::class);
    }

    public function utilityQuery(UtilityQueryRequest $request): PayoutResult
    {
        return $this->run($request, PayoutResult::class);
    }

    /** Money-moving: never retried. */
    public function walletCashin(WalletCashinRequest $request): PayoutResult
    {
        return $this->run($request, PayoutResult::class);
    }

    public function walletNameLookup(WalletNameLookupRequest $request): LookupResult
    {
        return $this->run($request, LookupResult::class);
    }

    public function walletCashinQuery(WalletCashinQueryRequest $request): PayoutResult
    {
        return $this->run($request, PayoutResult::class);
    }

    public function vendorBalance(VendorBalanceRequest $request): BalanceResult
    {
        return $this->run($request, BalanceResult::class);
    }

    // ---- Generic ----------------------------------------------------------------------------

    /**
     * Sign, send once (or repeat after NOT_SENT when opted in and allowed) and classify.
     *
     * @throws ValidationException when the PIN is needed and missing
     * @throws SigningException when the request cannot be signed: nothing is sent
     * @throws TransportException when no response arrived
     * @throws InvalidResponseException when an operation that moves no money got no verdict
     */
    public function execute(OperationRequest $request): Result
    {
        $operation = $request->operation();
        $credentials = $this->config->credentialsFor($operation);
        $params = $request->params($credentials);

        $attempts = 1 + ($operation->isRetryableWhenNotSent() ? $this->config->maxNotSentRetries : 0);
        for ($attempt = 1;; $attempt++) {
            // A fresh timestamp for every send, including a repeat after NOT_SENT.
            $signed = RequestSigner::sign(
                $operation->method(),
                $params,
                Timestamp::format($this->clock->now(), $this->config->timezone),
                $credentials->apiKey(),
                ($this->digestSignerFactory)($credentials),
            );

            $headers = $signed->headers() + [
                'Accept' => 'application/json',
                'User-Agent' => 'selcom-php/' . self::VERSION,
            ];
            $url = $this->config->baseUrl() . $operation->path();
            if ($signed->body !== null) {
                $headers['Content-Type'] = 'application/json';
            } else {
                $url .= '?' . $signed->query;
            }

            try {
                $response = $this->transport->send(new TransportRequest(
                    $operation->method(),
                    $url,
                    $headers,
                    $signed->body,
                    $this->config->timeouts->forOperation($operation),
                ));
            } catch (TransportException $e) {
                if ($e->isNotSent() && $attempt < $attempts) {
                    continue;
                }
                throw $e;
            }

            return ResultFactory::create($operation, $response->status, $response->body, $params);
        }
    }

    /**
     * @template T of Result
     * @param class-string<T> $class
     * @return T
     */
    private function run(OperationRequest $request, string $class): Result
    {
        $result = $this->execute($request);
        if (!$result instanceof $class) {
            throw new \LogicException('Unexpected result type for ' . $request->operation()->value . '.');
        }

        return $result;
    }
}
