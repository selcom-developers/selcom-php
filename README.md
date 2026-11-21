# selcom-php

Framework-agnostic PHP client for the Selcom API gateway: request signing, checkout, payouts and webhooks.

> **Status: not yet published to Packagist.** Version 0.1.0 is unreleased. Until it is published, install from the repository (see [Install](#install)).
>
> **Not affiliated with Selcom Paytech Ltd.** This is a community project, written from scratch under the Apache License 2.0. "Selcom" names the API it talks to. For API access, credentials, contracts and support, go to Selcom: <https://developers.selcommobile.com/>. Where this README and Selcom's portal disagree about the API, the portal wins.

Every layer is usable on its own and replaceable: the **signer** is a pure function, the **transport** is an interface, the **client** gives one typed method per operation, **results** carry both a typed status and the raw response, and the **webhook verifier** and **redactor** stand alone.

The rules this client follows (signing, result codes, retries, webhooks) live in the shared spec: <https://github.com/selcom-developers/selcom-spec> (this release is tested against `v0.2.1`, recorded in [`SPEC_VERSION`](SPEC_VERSION)).

## Contents

- [Install](#install)
- [Quickstart](#quickstart)
- [Public API](#public-api)
- [Configuration](#configuration)
- [Operations](#operations)
- [Results and statuses](#results-and-statuses)
- [Error handling](#error-handling)
- [Idempotency, retries and reconciliation](#idempotency-retries-and-reconciliation)
- [Webhooks](#webhooks)
- [Transport](#transport)
- [Signing on its own](#signing-on-its-own)
- [Redacting logs](#redacting-logs)
- [Security](#security)
- [Testing](#testing)

## Install

Requires PHP 8.2 or later (CI runs 8.2, 8.3, 8.4 and 8.5). There is no framework dependency.

Until the package is on Packagist, require it from the repository:

```json
{
    "repositories": [{ "type": "vcs", "url": "https://github.com/selcom-developers/selcom-php" }],
    "require": { "selcom-developers/selcom-php": "dev-main" }
}
```

The HTTP layer is PSR-18. The package finds a PSR-18 client and PSR-17 factories through [`php-http/discovery`](https://docs.php-http.org/en/latest/discovery.html), so install any one you like (Guzzle, Symfony HttpClient with a PSR-18 bridge, and so on):

```
composer require guzzlehttp/guzzle
```

You can also pass your own client or your own transport explicitly: see [Transport](#transport).

**IP allowlist.** Selcom allows only the IP addresses on its allowlist to call the gateway. A first call from a new machine that fails with an unexplained error is usually this. Ask Selcom to allow your server's outbound address, for the sandbox and for production separately.

## Quickstart

```php
use SelcomDevelopers\Selcom\Client;
use SelcomDevelopers\Selcom\Config;
use SelcomDevelopers\Selcom\Credentials;
use SelcomDevelopers\Selcom\Environment;
use SelcomDevelopers\Selcom\Request\OrderStatusRequest;
use SelcomDevelopers\Selcom\Request\UtilityPaymentRequest;
use SelcomDevelopers\Selcom\Status;

$config = new Config(
    environment: Environment::Sandbox,                  // required: there is no default
    credentials: new Credentials(
        apiKey: getenv('SELCOM_API_KEY'),
        secret: getenv('SELCOM_API_SECRET'),
        vendor: getenv('SELCOM_VENDOR'),
        pin: getenv('SELCOM_PIN'),                      // needed by payout operations only
    ),
);

$client = new Client($config);                          // PSR-18 client found by discovery

// Persist the transid BEFORE you call (see "Idempotency"), then:
$result = $client->utilityPayment(new UtilityPaymentRequest(
    transid: 'TXN-UTL-0001',
    utilityCode: 'LUKU',
    utilityRef: '01234567891',
    amount: 8000,                                       // whole TZS
));

switch ($result->status) {
    case Status::Success:   /* paid; $result->receipt() may hold a token */ break;
    case Status::Failed:    /* it did not happen */ break;
    case Status::Pending:
    case Status::Ambiguous: /* unknown: query later, never resubmit */ break;
    case Status::NotFound:  /* only on queries */ break;
}
```

## Public API

The public surface is small on purpose, so that a framework package can sit on top of it. Everything is in the `SelcomDevelopers\Selcom` namespace.

| Piece | Classes | What it is |
|---|---|---|
| Config | `Config`, `Credentials`, `Environment`, `Transport\Timeouts`, `Transport\TimeoutProfile` | Validated at construction. `Config::fromArray()` builds one from a framework config array. |
| Signer | `Signing\RequestSigner`, `Signing\DigestSigner`, `Signing\Hs256DigestSigner`, `Signing\SignedRequest`, `Signing\Timestamp` | Pure signing: no I/O, no clock. The digest step is an interface, so RS256 can be added later. |
| Transport | `Transport\Transport`, `Transport\Psr18Transport`, `Transport\TransportRequest`, `Transport\TransportResponse`, `Transport\TransportFailure`, `Transport\FailureClassifier` | Replaceable HTTP layer, with one PSR-18 default. |
| Client | `Client` | One typed method per operation, plus `execute()`. |
| Requests | `Request\*Request`, `Request\Address`, `Request\PaymentMethod`, `Currency` | One typed request class per operation. |
| Results | `Result\Result` and subclasses, `Status`, `Result\ResponseClassifier` | Typed status plus code, text, HTTP status, decoded data and raw body. |
| Webhooks | `Webhook\WebhookVerifier`, `Webhook\WebhookVerdict`, `Webhook\WebhookPayload`, `Webhook\ReplayStore`, `Webhook\InMemoryReplayStore` | Verify a delivery, parse it. |
| Redactor | `Redaction\Redactor` | Safe-to-log copies of headers, bodies and query strings. |
| Errors | `Exception\*` (all implement `Exception\SelcomException`) | See [Error handling](#error-handling). |

Semantic versioning applies to these classes from the first release. Classes marked `@internal` (`Request\Check`) are not part of the API.

## Configuration

```php
$config = new Config(
    environment: Environment::Production,
    credentials: new Credentials($apiKey, $secret, $vendor, pin: $pin),
    checkoutCredentials: null,        // optional second credential set for checkout operations
    timeouts: new Timeouts(),         // defaults below
    timezone: new DateTimeZone('Africa/Dar_es_Salaam'),
    maxNotSentRetries: 0,             // opt-in, read-only operations only
);
```

- **The environment is required.** There is no default, and in particular no production default: `Config::fromArray()` without `environment` throws `ConfigurationException`.

  | Environment | Base URL |
  |---|---|
  | `Environment::Production` | `https://apigw.selcommobile.com` |
  | `Environment::Sandbox` | `https://apigwtest.selcommobile.com` |

- **A custom base URL** (a simulator or a proxy) is its own explicit step: `$config = $config->withBaseUrl('http://localhost:8080')`. It never replaces a preset silently.
- **Credentials.** The API key, the secret and the vendor (till) ID are required and checked at construction, not on the first request. The float PIN is needed by payout operations only; a payout without one fails with `ValidationException` before anything is sent. A merchant may use separate credentials for checkout: pass `checkoutCredentials`, and checkout operations use them.
- **Secrets stay out of output.** `var_dump`, `print_r`, `var_export`, array casts, serialisation and exception messages show none of the secret, the PIN, `Authorization` or `Digest`; the API key shows only its last 4 characters. Secret parameters are marked `#[\SensitiveParameter]`, so they are also left out of stack traces.
- **Timeouts** are per profile, and no request runs without one:

  | Profile | Used by | Connect | Read |
  |---|---|---|---|
  | query | status queries, order status, everything that moves no money | 10 s | 10 s |
  | payment | money-moving operations | 15 s | 180 s |

  The long read timeout on payments is deliberate. Timing out early does not stop the payment; it turns a known answer into an unknown one. Change them with `new Timeouts(new TimeoutProfile(10, 10), new TimeoutProfile(15, 180))`. See [Transport](#transport) for how they reach the HTTP client.
- **Timestamps** are generated from the clock you give the client (a PSR-20 `ClockInterface`; the default is the system clock) in the `Africa/Dar_es_Salaam` zone (`+03:00`), in the colon form `2026-11-21T10:30:46+03:00`. The gateway rejects requests whose time is too far from its own clock, so keep the server clock synchronised.
- **Settings from an array**, as a framework config file would give them:

  ```php
  Config::fromArray([
      'environment' => 'sandbox',            // required
      'api_key' => '...', 'secret' => '...', 'vendor' => '...', 'pin' => '...',
      'checkout' => ['api_key' => '...', 'secret' => '...'],      // optional
      'base_url' => null, 'timezone' => 'Africa/Dar_es_Salaam',
      'max_not_sent_retries' => 0,
      'timeouts' => ['query_connect' => 10, 'query_read' => 10, 'payment_connect' => 15, 'payment_read' => 180],
  ]);
  ```

## Operations

One method per operation. Submitting and querying are separate methods: nothing hides a query inside a submit. Amounts are whole units. Request classes accept an `int`, a string of digits or an integral float, and refuse anything else (a fraction, a negative, zero, more than 9007199254740991) with a `ValidationException`; nothing is rounded or truncated. The signer applies the same rule to raw parameters.

### Checkout

| Method | Request | Returns | Notes |
|---|---|---|---|
| `createOrder` | `CreateOrderRequest` | `CreateOrderResult` | Any enabled method, cards included. Send the full `Address` billing group for any order that may be paid by card. URLs are given in plain form and base64-encoded for you. |
| `createOrderMinimal` | `CreateOrderMinimalRequest` | `CreateOrderResult` | Non-card orders: first step of a USSD push. |
| `walletPayment` | `WalletPaymentRequest` | `Result` | Push a USSD prompt for an order. **Money-moving.** Normally `PENDING`. |
| `cardPayment` | `CardPaymentRequest` | `Result` | Charge a stored card. **Money-moving.** Normally `PENDING`. |
| `orderStatus` | `OrderStatusRequest` | `OrderStatusResult` | The source of truth for checkout. |
| `cancelOrder` | `CancelOrderRequest` | `Result` | A successful cancel proves the order was never paid. |
| `listOrders` | `ListOrdersRequest` | `ListOrdersResult` | Orders in a date range, for reconciliation. |
| `storedCards` | `StoredCardsRequest` | `StoredCardsResult` | Card tokens are sensitive: never log them. |
| `deleteCard` | `DeleteCardRequest` | `Result` | |

```php
$order = $client->createOrderMinimal(new CreateOrderMinimalRequest(
    orderId: 'ORD-1001',
    buyerEmail: 'buyer@example.com',
    buyerName: 'Test Buyer',
    buyerPhone: '255700000001',
    amount: 8000,
    currency: Currency::TZS,                // always explicit
    noOfItems: 1,
    webhookUrl: 'https://shop.example.com/selcom/webhook/<random-token>',
));

$created = $order->order();                 // CreatedOrder|null: paymentToken, qr, paymentGatewayUrl (decoded) ...

$push = $client->walletPayment(new WalletPaymentRequest('TXN-WLT-0001', 'ORD-1001', '255700000001'));
// If this fails outright (a not-sent error or FAILED), cancel the order on a best-effort basis so it
// cannot be paid later by surprise: $client->cancelOrder(new CancelOrderRequest('ORD-1001')).

$status = $client->orderStatus(new OrderStatusRequest('ORD-1001'));
$status->status;                            // the verdict on the ORDER (from payment_status)
$status->order()?->amount;                  // whole TZS, strictly parsed
```

`paymentGatewayUrl` is decoded from the gateway's base64 for you; the raw value stays in `paymentGatewayUrlRaw`.

### Payouts

Payouts spend from the merchant's float account and are authorised with the vendor and the float PIN.

| Method | Request | Returns | Notes |
|---|---|---|---|
| `utilityPayment` | `UtilityPaymentRequest` | `PayoutResult` | Bills and prepaid services. **Money-moving.** |
| `utilityLookup` | `UtilityLookupRequest` | `LookupResult` | Check a reference before paying. |
| `utilityQuery` | `UtilityQueryRequest` | `PayoutResult` | Verdict on an earlier payment, by `transid`. |
| `walletCashin` | `WalletCashinRequest` | `PayoutResult` | Credit a mobile wallet. **Money-moving.** |
| `walletNameLookup` | `WalletNameLookupRequest` | `LookupResult` | Registered name on a wallet. |
| `walletCashinQuery` | `WalletCashinQueryRequest` | `PayoutResult` | Verdict on an earlier cash-in, by `transid`. |
| `vendorBalance` | `VendorBalanceRequest` | `BalanceResult` | Moves no money; `balance()` is whole TZS. |

Payouts are TZS only and carry no currency field.

`$client->execute($request)` is the generic entry point the typed methods use: it takes any `OperationRequest` and returns the matching `Result`.

## Results and statuses

Every operation returns a `Result` with:

| Field | Content |
|---|---|
| `status` | `Status::Success`, `Pending`, `Ambiguous`, `Failed` or `NotFound` (backed by `SUCCESS`, `PENDING`, `AMBIGUOUS`, `FAILED`, `NOT_FOUND`) |
| `resultCode` | The normalised code, or `null` when there was no verdict |
| `result`, `message` | The gateway's text, unchanged |
| `reference` | The gateway's reference, when present |
| `transid`, `orderId` | The ID this result is about |
| `httpStatus` | The HTTP status code. It does not decide the verdict |
| `data` | The decoded `data` array, raw values kept. Typed accessors on the subclasses |
| `raw` | The body exactly as received. Redact before logging |

`isFinal()` is true for `SUCCESS`, `FAILED` and `NOT_FOUND`.

The status comes from `resultcode` only (`000` success; `111` and `927` in progress; `999` ambiguous; `404` on a status query is `NOT_FOUND`; anything else failed). The text fields are for people. For `orderStatus` the verdict is the order's `payment_status`, not the query's code. The full table and the edge cases are in the spec's [results.md](https://github.com/selcom-developers/selcom-spec/blob/v0.2.1/docs/results.md).

| Status | Meaning | What you may do |
|---|---|---|
| `SUCCESS` | It happened | Fulfil (after confirming a webhook with `orderStatus`) |
| `PENDING` | Accepted, still processing | Wait for the webhook, or query after 3 minutes or more |
| `AMBIGUOUS` | Nobody knows yet | Query after 3 minutes or more. **Never resubmit** |
| `FAILED` | It did not happen | Report it. A new attempt is a new transaction with a new ID, and your decision |
| `NOT_FOUND` | The gateway has no record of the ID | The only case in which the same ID may be submitted again |

A response that arrives without a verdict (an HTML error page from a proxy, an empty body) is `AMBIGUOUS` for payments and queries, because the money may have moved, and raises `InvalidResponseException` for operations that move no money.

`ResponseClassifier::classify($kind, $httpStatus, $rawBody)` is the same logic as a pure function, if you want to classify a response yourself.

## Error handling

All exceptions implement `Exception\SelcomException`.

| Exception | When | Was anything sent? |
|---|---|---|
| `ConfigurationException` | Missing or invalid configuration, at construction | No |
| `ValidationException` | A request object was built with a bad field, or a payout has no PIN | No |
| `SigningException` | The request cannot be signed (`->errorCode`: `EMPTY_SECRET`, `INVALID_TIMESTAMP`, `NON_INTEGER_NUMBER`, ...) | No |
| `TransportException` | No HTTP response arrived | See `->failure` |
| `InvalidResponseException` | A response with no verdict, on an operation that moves no money | Yes |

**Fail closed.** If signing fails, nothing is sent. The client never sends a request with an empty or missing `Digest`.

**Transport errors say what they mean.** `TransportException::$failure` is a `TransportFailure`:

- `NotSent`: DNS failure, connection refused, connect timeout, TLS handshake failure. The request provably never reached the gateway; sending it again with the same ID is safe.
- `UnknownOutcome`: anything after the request may have been written (read timeout, reset, truncated response). The gateway may have acted. **Treat it exactly like `AMBIGUOUS`: query, never resend.** When the HTTP library cannot tell which case it is, the answer is `UnknownOutcome`.

A transport error is never turned into `FAILED`.

## Idempotency, retries and reconciliation

The client is stateless between calls: it does not lock, queue or deduplicate. Staying safe is your side of the contract.

**The ID is the idempotency key.** `transid` identifies a payout or a payment attempt; `order_id` identifies a checkout order. The gateway refuses a second transaction with an ID it has seen. The maximum length and characters are not published, so keep IDs to 30 characters or fewer using `A-Z a-z 0-9 -`.

**What the client retries.** Nothing, by default. It **never** retries a payment or a write (create order, cancel, delete card), not after a timeout and not with the same ID. For read-only operations (queries, lookups, balance, list, stored cards) you may opt in with `maxNotSentRetries`, which repeats a request only after a `NotSent` error, with the same parameters and a fresh timestamp.

**Before you call a money-moving method:**

1. Generate the ID deterministically from your own records (for example the payout row's primary key with a prefix), so that a crash and restart produces the same ID.
2. Persist the ID, with a "submitting" state, **before** calling the gateway. After a crash, reconcile anything left in that state by querying.
3. Put a unique constraint on the ID, so that a retried job cannot create a second transaction.

**After the call:**

- `SUCCESS` or `FAILED` is final.
- `PENDING`, `AMBIGUOUS` or a `TransportException` with `UnknownOutcome`: wait at least 3 minutes, then query with the same ID (`utilityQuery` or `walletCashinQuery` by `transid`; `orderStatus` by `order_id`). If it is still unsettled, query again every 3 minutes for a bounded window you choose. If it never settles, hand it to a person to raise with Selcom support, with the `transid` and `reference`.
- **Never mint a new ID while an outcome is unknown.** A new ID is a new transaction.
- **Resubmit the same ID only after `NOT_FOUND`**, or after a `NotSent` error. If the original did arrive in the meantime, the gateway refuses the duplicate.
- Apply results under a row lock or a compare-and-set (`UPDATE ... WHERE status NOT IN (final states)`), so that a webhook and a poll arriving together cannot both win, and never let a later result change a final state.
- To close a checkout order as failed for good, cancel it: a completed order cannot be cancelled, so a successful `cancelOrder` proves it was never paid. A `FAILED` order status alone is not enough, because an unexpired order can still be paid by a later attempt.

## Webhooks

A webhook is a **hint, not proof**. Never fulfil an order because a webhook said so: confirm with `orderStatus` first, every time, and take the amount from there.

```php
use SelcomDevelopers\Selcom\Webhook\WebhookPayload;
use SelcomDevelopers\Selcom\Webhook\WebhookVerdict;
use SelcomDevelopers\Selcom\Webhook\WebhookVerifier;

$verifier = new WebhookVerifier(
    secret: $secret,
    replayStore: $sharedReplayStore,   // a ReplayStore over APCu or Redis; the default is per-process
    windowSeconds: 300,
);

$raw = file_get_contents('php://input');            // the raw body, before anything parses it
$verdict = $verifier->verify(getallheaders(), $raw);

if ($verdict->shouldProcess()) {                    // VALID or UNSIGNED
    $payload = WebhookPayload::fromRawBody($raw);   // orderId, transid, paymentStatus, amountTzs, ...
    // 1. verify (done)  2. deduplicate on $payload->dedupeKey() and STORE the delivery (redacted);
    //    if storing fails, answer 5xx so the gateway delivers again
    // 3. acknowledge with 200
    // 4. later, outside the request: orderStatus($payload->orderId), then act on THAT
}
http_response_code($verdict->httpStatus());         // 200 for VALID, UNSIGNED, REPLAY; 401 for INVALID, STALE
```

| Verdict | Respond | Process? |
|---|---|---|
| `VALID` | 200 | Yes, then confirm with `orderStatus` |
| `UNSIGNED` (no `Digest` header) | 200 | Yes, then confirm with `orderStatus` |
| `REPLAY` | 200 | No: already handled |
| `INVALID` | 401 | No. Log it, redacted |
| `STALE` | 401 | No. Log it, redacted |

- `verify(headers, rawBody)` never throws on bad input: bad input is `INVALID`. Header names are matched case-insensitively. The digest is compared in constant time. The timestamp window defaults to 300 seconds and is configurable.
- `VALID` covers the fields named in `Signed-Fields`, which must include `order_id` and `transid` and may not contain `&`. Take the status and the amount from `orderStatus`, not from the delivery.
- Verification is bounded and never throws: oversized input, numbers that are not whole numbers within 2^53 - 1, and anything unparseable are `INVALID`.
- `WebhookPayload::$amountTzs` parses text such as `"10,000"` strictly into whole shillings, or `null`.
- The replay store keeps each digest until max(now, signed time) + window (`ReplayStore::seen($digest, $keepUntil)`; an entry is live through `$keepUntil`). In PHP-FPM each request is a new process, so give the verifier a shared `ReplayStore` (APCu, Redis); `InMemoryReplayStore` is for tests and long-running workers. Your own deduplication on `(order_id, transid)` is the durable guard.
- **Open question in the spec:** it is not confirmed which key Selcom signs webhooks with. The samples assume your API secret. Until you have confirmed it in the sandbox, run in report-only mode: log the verdict and process anyway, relying on `orderStatus`. Default to rejecting `INVALID` once you have confirmed.
- Acknowledge fast (200 as soon as the delivery is stored) and do the `orderStatus` call and fulfilment afterwards. Poll `orderStatus` as the fallback for orders with no final status: webhooks can be lost.

Use an HTTPS URL with an unguessable token in the path, and send it on every order, in base64 (the request classes do the encoding).

## Transport

The `Transport` interface is the whole HTTP layer:

```php
interface Transport
{
    public function send(TransportRequest $request): TransportResponse;   // throws TransportException
}
```

`TransportRequest` carries the method, the full URL, the signed headers, the body and the `TimeoutProfile` to use. Implement the interface for a custom client, a proxy, request logging or a test fake, and pass it to `new Client($config, $transport)`. The contract: return a `TransportResponse` for **every** HTTP response whatever its status; throw `TransportException` only when no response arrived, classified `NotSent` only when the request provably never left; apply the timeouts; never retry.

`Psr18Transport` is the default. It takes any PSR-18 client and PSR-17 factories, found by discovery when you pass nothing:

```php
new Psr18Transport();                                   // a Guzzle client per timeout profile (needs guzzlehttp/guzzle)
new Psr18Transport(fn (TimeoutProfile $p) => new GuzzleHttp\Client(Psr18Transport::guzzleOptions($p)));
new Psr18Transport($myPsr18Client, clientManagesTimeouts: true);   // a ready client: you confirm it applies the timeouts
```

`Psr18Transport::guzzleOptions($profile)` returns what the default route uses. Written out, a per-profile Guzzle client looks like this:

```php
new Psr18Transport(fn (TimeoutProfile $p) => new GuzzleHttp\Client([
    'connect_timeout' => $p->connectSeconds,
    'timeout' => $p->connectSeconds + $p->readSeconds,
    'allow_redirects' => false,
    'http_errors' => false,
    // payments only: a new connection every time, not kept for reuse
    'curl' => $p->freshConnection ? [CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true] : [],
]));
```

**Timeouts and PSR-18.** PSR-18 has no way to pass a timeout with a request, so the timeouts live in the client, and no request runs without them. `Psr18Transport` therefore needs one of three things: nothing (it builds a Guzzle client per profile, so Guzzle must be installed), a closure that builds one client per `TimeoutProfile`, or a ready client together with `clientManagesTimeouts: true`, by which you confirm that the client applies the connect and read timeouts itself. A ready client without the flag is refused with a `ConfigurationException`.

**Fresh connections for payments.** The payment profile has `freshConnection = true`: each payment request uses a new connection and does not leave it open for reuse (`CURLOPT_FRESH_CONNECT` and `CURLOPT_FORBID_REUSE` on cURL). The default Guzzle route does this for you. With a custom client, disable connection reuse for the payment profile yourself.

**No hidden resends.** Whatever client you plug in must not retry requests on its own (no retry middleware, no automatic resend of a POST): a repeated POST is a second payment attempt. Retries are the caller's decision, see above.

**What is kept from a failed send.** `TransportException` carries a short summary as its cause (exception classes and the cURL error number), not the client's own exception, whose message and request hold request data.

## Signing on its own

The signer is a pure function: the timestamp is an argument, and nothing is sent or read.

```php
use SelcomDevelopers\Selcom\Signing\Hs256DigestSigner;
use SelcomDevelopers\Selcom\Signing\RequestSigner;

$signed = RequestSigner::sign(
    'POST',                                          // GET, POST or DELETE
    ['vendor' => 'TILL123', 'order_id' => 'ORD-1001', 'amount' => 8000, 'billing' => ['firstname' => 'John']],
    '2026-11-21T10:30:46+03:00',                     // also accepted: +0300
    $apiKey,
    new Hs256DigestSigner($secret),
);

$signed->signedFields;   // vendor,order_id,amount,billing.firstname
$signed->signingString;  // timestamp=...&vendor=TILL123&...
$signed->headers();      // Authorization, Digest-Method, Digest, Timestamp, Signed-Fields
$signed->query;          // GET/DELETE: percent-encoded query string (null on POST)
$signed->body;           // POST: compact JSON with flat keys (null on GET/DELETE)
```

Nested arrays are flattened in place to dotted keys; the order is the order you give. Values are signed and sent as the same text; `null` becomes an empty string; fractional numbers, integers beyond 2^53 - 1, lists and empty objects are rejected with a `SigningException`. The behaviour is fixed by the shared vectors, so any change that breaks them fails the tests. Implement `DigestSigner` to add another digest method (RS256 is not implemented: Selcom has not confirmed the padding or the key registration).

## Redacting logs

Do not log secrets or personal data. Pass anything you store or log through the redactor:

```php
use SelcomDevelopers\Selcom\Redaction\Redactor;

$redactor = new Redactor();
$redactor->redactHeaders($request->headers);     // Authorization, Digest -> [REDACTED]
$redactor->redactParams($params);                // flat or nested arrays
$redactor->redactQuery($queryString);
$redactor->redactJson($result->raw);             // non-JSON bodies are replaced by a placeholder
```

| Data | Fields (also as `billing.x`, `shipping.x`) | Becomes |
|---|---|---|
| Secrets | `pin`, `secret`, `api_secret`, `api_key`, `Authorization`, `Digest`, `card_token` | `[REDACTED]` |
| Phone numbers | `msisdn`, `phone`, `buyer_phone`, and `utilityref` on wallet cash-in | `********0001` (all but the last 4) |
| E-mail | `buyer_email`, `email` | `t***@example.com` |
| Names | `buyer_name`, `firstname`, `lastname`, `name` | `T***` |

`TransportRequest::redactedHeaders()` is a shortcut for the headers. The client does no logging of its own.

## Security

- Keep the API secret, the float PIN and card tokens out of source control, logs, error trackers and chat. Read them from the environment or a secret store.
- Redact before you log (above). Dumps of `Config`, `Credentials` and `Hs256DigestSigner` are already safe, and the secret is never in an exception message.
- Fail closed: the client never sends an unsigned or empty-digest request.
- Treat webhooks as hints (above), over HTTPS, with a replay store.
- Report vulnerabilities privately: see [SECURITY.md](SECURITY.md).

## Testing

```
composer install
composer test        # PHPUnit: the shared spec vectors, unit tests, no network
composer analyse     # PHPStan at the max level, with the strict rules
```

`scripts/check-spec.sh` (run in CI) checks that the copy is byte-identical to selcom-spec at `SPEC_VERSION`. The tests run every case and every reject in `signing.json`, every response scenario and every webhook sample from the shared spec, from a copy kept in `tests/vectors`. The spec version is recorded in `SPEC_VERSION`; refresh the copy with `scripts/refresh-spec.sh [tag]`.

No test calls the network. An opt-in sandbox test (`tests/Sandbox`) is skipped unless `SELCOM_SANDBOX_API_KEY`, `SELCOM_SANDBOX_API_SECRET` and `SELCOM_SANDBOX_VENDOR` are set; it makes one read-only call and never runs in CI.

For your own tests, pass a fake `Transport` and a fixed `ClockInterface` to `Client`.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Changes are listed in [CHANGELOG.md](CHANGELOG.md).

## License

[Apache License 2.0](LICENSE). Not affiliated with Selcom Paytech Ltd.
