# ADR 0005 — How gateway SDKs are wrapped

- Status: Accepted
- Deciders: library maintainers
- Constraint: library floor is PHP 8.2

## Context

The specification allows either wrapping the official SDK or calling the HTTP API
through a PSR-18 client. Which is viable is a per-SDK fact discoverable by
reading the SDK, not a stylistic preference. All three were read from source at
the versions below, and all three are 8.2-compatible.

| SDK | Version read |
|---|---|
| `midtrans/midtrans-php` | 2.6.2 (`8ed7fc5`) |
| `xendit/xendit-php` | 7.0.0 (`4c6e1b9`) |
| `stripe/stripe-php` | v22.1.0 (`776af06`) |

### Midtrans: no injection point exists

- `Midtrans\Config` is static public properties only (`$serverKey`,
  `$isProduction`, `$curlOptions`, `$paymentIdempotencyKey`).
- `Snap`, `CoreApi` and `Transaction` are all-static wrappers.
  `ApiRequestor::remoteCall()` calls `curl_init()` directly and configures the
  handle with `curl_setopt_array()`. There is no client parameter anywhere.
- The only extension hook is `Config::$curlOptions`, a raw `CURLOPT_*` array.
- `Midtrans\Notification::__construct()` reads `php://input` and then calls
  `Transaction::status()` — it re-fetches authoritative status rather than
  verifying the payload. `signature_key` appears in transaction responses, but
  the SDK contains no verification code.
- Idempotency is supported: `Config::$paymentIdempotencyKey` emits an
  `Idempotency-Key` header.

### Xendit: real client injection

OpenAPI-generated. `PaymentRequestApi::__construct(ClientInterface $client,
Configuration $config, HeaderSelector $selector, $hostIndex)` — the client type is
`GuzzleHttp\ClientInterface` and defaults to `new Client()` when omitted.
`GuzzleHttp\Client` implements `Psr\Http\Client\ClientInterface`, so a host
PSR-18 client can be supplied through Guzzle's `handler` option. The package
hard-requires `guzzlehttp/guzzle ^7.3`, `guzzlehttp/psr7` and
`vlucas/phpdotenv`; the dotenv dependency is unused inside `lib/`.

### Stripe: PSR-18 blocked, non-PSR hook available

- `BaseStripeClient::validateConfig()` throws
  `Found unknown key(s) in configuration array` for keys outside
  `DEFAULT_CONFIG`, and `DEFAULT_CONFIG` has no HTTP-client key. There is no
  per-client HTTP injection.
- `Stripe\ApiRequestor::setHttpClient($client)` is a static/global setter typed
  against `Stripe\HttpClient\ClientInterface` —
  `request($method, $absUrl, $headers, $params, $hasFile, $apiMode,
  $maxNetworkRetries)`. Bridging PSR-18 requires an adapter class implementing
  that interface, installed process-wide.
- `max_network_retries` is native to the client.
- Webhook verification is native and correct:
  `Stripe\Webhook::constructEvent($payload, $sigHeader, $secret, $tolerance)` →
  `WebhookSignature::verifyHeader()`, HMAC-SHA256 over `timestamp . '.' . payload`
  with a tolerance window and `hash_equals()` comparison.

## Decision

| Gateway | Wrapping | Webhook verification | Retries |
|---|---|---|---|
| Stripe | Official SDK (`StripeClient`) | Delegated to `Stripe\Webhook::constructEvent` | Delegated to SDK `max_network_retries`; our retry decorator is disabled for this adapter |
| Xendit | Official SDK with an injected `GuzzleHttp\Client`; host PSR-18 client bridged via Guzzle `handler` | Ours, `hash_equals()`, token from `x-callback-token` | Ours (exponential backoff with jitter) |
| Midtrans | **PSR-18 direct to the HTTP API**; the SDK is kept as behavioural reference only | Ours, `hash_equals()`, SHA-512 per provider formula | Ours |

Midtrans is the deliberate exception to "wrap the SDK": no client injection means
static global state that breaks multi-merchant use in one process, and the SDK
provides no webhook verification, so the security-critical half must be written
regardless. Calling the HTTP API through PSR-18 is equivalent effort without the
global state. `PaymentGatewayPort` and the ACL are identical either way, so this
is reversible if Midtrans ships an injectable client.

Adapter invariants regardless of wrapping: `PaymentGatewayPort` never exposes SDK
types; `createPayment` returns `PaymentResult` plus `PaymentInstruction`s; and
request shapes are taken from SDK source or official documentation, never
invented.

### Webhook verification, as documented by the providers

Midtrans (`docs.midtrans.com/docs/https-notification-webhooks`):

```
SHA512(order_id + status_code + gross_amount + ServerKey)
```

Concatenate the four values, hex-digest, compare with `hash_equals()` against
`signature_key` in the notification body. `gross_amount` is the **string** form
as sent (`"10000.00"`), so it must be hashed verbatim, not re-derived from minor
units. A partner-server-key variant uses `PartnerServerKey`; the verifier must
know which mode it is configured for rather than assuming one key.

Success requires three conditions together: `status_code == "200"`,
`transaction_status` of `settlement` (or `capture` for card), and
`fraud_status == "accept"` *when that field is present*. Indomaret and Alfamart
notifications omit `fraud_status` entirely.

Xendit (`docs.xendit.co/docs/integration-security`): shared secret in the
`x-callback-token` header, compared against the Verification Token from the
Dashboard's webhook settings. No HMAC, no timestamp — so `hash_equals()` and
secret rotation are the entire defence. Xendit retries up to six times with
exponential backoff and explicitly documents that webhook order is not
guaranteed.

Both providers document that notifications may arrive out of order; the inbox and
state machine must tolerate that rather than assuming monotonic delivery.

## Consequences

- Two of three verifiers are our own code and carry the security burden. They get
  the contract suite plus known-answer tests built from the documented examples.
- `gateway-xendit` pulls Guzzle into any host that installs it. Acceptable and
  contained to that package.
- Stripe's global `setHttpClient` means two differently configured Stripe clients
  in one process are unsupported. Documented, not discovered.
- Midtrans request shapes are mirrored by hand when the provider changes, since
  no SDK absorbs the change for us.

## Alternatives rejected

- **Wrap Midtrans's SDK anyway** — inherits static global config and still leaves
  webhook verification to us.
- **All three through PSR-18** — discards Stripe's tested verification and retry
  logic, and Xendit's typed request/response models.
- **One HTTP abstraction over the SDKs** — the SDKs are not request-transparent;
  the abstraction would leak.

## Verification

Read directly from SDK sources: `Midtrans/ApiRequestor.php`, `Midtrans/Config.php`,
`Midtrans/Notification.php`, `lib/PaymentRequest/PaymentRequestApi.php`,
`lib/BaseStripeClient.php`, `lib/ApiRequestor.php`, `lib/Webhook.php`,
`lib/WebhookSignature.php`, `lib/HttpClient/ClientInterface.php`, plus each
package's `composer.json`.

Midtrans signature formula, success conditions and out-of-order caveat read from
`docs.midtrans.com/docs/https-notification-webhooks`. Xendit token scheme, retry
policy and ordering caveat read from `docs.xendit.co/docs/integration-security`
and `docs.xendit.co/docs/handling-webhooks`.

**Not verified:** whether either provider rejects or silently accepts a
webhook delivered with an old but valid timestamp (Xendit has no timestamp at
all), and whether Midtrans's partner mode is in scope for v1.
