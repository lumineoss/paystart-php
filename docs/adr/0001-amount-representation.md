# ADR 0001 — Amount representation

- Status: Accepted
- Deciders: library maintainers
- Constraint: library floor is PHP 8.2

## Context

Floats are excluded. Amounts cross three boundaries that disagree on scale:

1. **Our domain** — one canonical representation, gateway-independent.
2. **ISO 4217** — exponent (minor units) per currency.
3. **Each gateway** — its own amount unit, not always equal to ISO 4217 and not
   always equal to the next gateway's.

### The IDR problem

IDR is the primary currency for Midtrans and Xendit, so this is the common case,
not a corner case.

| Source | Convention |
|---|---|
| ISO 4217 | IDR exponent **2** |
| Stripe (`docs.stripe.com/currencies`) | IDR is absent from the zero-decimal list, therefore treated as 2-decimal; minimum charge `0.50 IDR`; maximum `9,999,999,999.99 IDR` = `999999999999` minor units |
| Midtrans (`gross_amount`) | Integer; "IDR has no decimals" — you send whole rupiah |
| Midtrans notifications | return `gross_amount` as a decimal string, e.g. `"10000.00"` |
| Xendit | `number`; "No decimal point for `IDR`, and max 2 decimal points for `PHP`" |

A global "IDR has 0 decimals" flag is a 100x error against Stripe. A global
"IDR has 2 decimals" flag is rejected or misread by Midtrans and Xendit.
Currency exponent and gateway amount scale are two separate concerns.

Stripe additionally documents two currencies that cannot be modelled with a
single number: HUF and TWD are chargeable with two decimals but payouts must be
integer amounts divisible by 100. That is a third scale — *charge* scale,
*payout* scale, ISO exponent. Neither currency is in the v1 set, but the type
model must not make them impossible to add.

Candidates (metadata from `repo.packagist.org`):

| Package | Latest stable | `require.php` | Hard requires |
|---|---|---|---|
| `moneyphp/money` | v4.9.0 | `~8.1.0 \|\| ~8.2.0 \|\| ~8.3.0 \|\| ~8.4.0 \|\| ~8.5.0` | `ext-bcmath`, `ext-filter`, `ext-json` |
| `brick/money` | 0.15.2 | `^8.2` | `brick/math`, `psr/simple-cache` |

`moneyphp/money` requires `ext-bcmath` in `require`, not `suggest`, and its
calculation precision is shared process state. `brick/money` avoids bcmath but
owns its own `Money`/`Currency` types, which would enter our shared kernel.

Both are compatible with the PHP 8.2 floor, so the floor is not the deciding
factor here.

## Decision

A **custom immutable `Money` value object in SharedKernel**:

- `final readonly class Money` over `int $minorUnits` + `Currency $currency`.
  No floats anywhere in the library. (`readonly class` is PHP 8.2, so this is
  available on the floor version.)
- `Currency` is a readonly VO (`code`, `exponent`), built from a checked-in ISO
  4217 table via `CurrencyRegistry`. Not a backed enum: the table changes, and a
  VO permits application-defined currencies without a BC-relevant release.
- Arithmetic (`plus`, `minus`, `multipliedBy`, `abs`, `isZero`) throws on
  currency mismatch and on integer overflow; nothing rounds implicitly.
- Persistence: `BIGINT` minor units + `CHAR(3)` currency. `BIGINT` covers any
  realistic IDR amount.
- The quantity a gateway accepts is a distinct type, `GatewayAmount`, not a bare
  `int`, so charge scale and payout scale can diverge later without an API break.

Conversion lives only at the adapter boundary:

```php
interface GatewayAmountScale
{
    public function toGateway(Money $money): GatewayAmount;
    public function fromGateway(GatewayAmount $raw, Currency $currency): Money;
}
```

Midtrans and Xendit with IDR require whole rupiah: `IDR 275000.00` (27500000
minor) becomes `275000`, and the adapter **throws** when
`$money->minorUnits % 100 !== 0` instead of rounding. Silently rounding a payment
amount is how money disappears. Stripe, treating IDR as 2-decimal, receives the
minor units unchanged.

`fromGateway` must accept the decimal string Midtrans sends in notifications
without re-formatting it: the same string is required verbatim for signature
verification (`SHA512(order_id + status_code + gross_amount + ServerKey)`), so
the raw value has to survive until after the webhook signature check.

Rounding, when a business rule needs it, is an explicit named strategy at the
call site.

`moneyphp/money` and `brick/money` are not dependencies of `core`. A separate
optional package may provide interop adapters; it carries the dependency.

## Consequences

- `core` stays PSR-plus-minimal; no `ext-bcmath` imposed on hosts.
- The IDR trap becomes an explicit, tested boundary rather than a latent 100x bug.
- Domain invariants (currency mismatch, overflow, non-representable amounts) live
  in one place and are property-testable.

Costs:

- We own the ISO 4217 table. Mitigation: dev-time generator, reviewed diff,
  exponent tests.
- No free FX or locale formatting. FX is out of domain scope; formatting belongs
  to the presentation layer.
- Consumers cannot pass `moneyphp/money` objects directly. Conversion adapters
  are cheap.

## Alternatives rejected

- **`moneyphp/money` in `core`** — hard `ext-bcmath` narrows the host matrix and
  contradicts the PSR-only rule for `core`.
- **`brick/money` in `core`** — viable, but puts third-party `Money`/`Currency`
  into the shared kernel, and its currency data would still need reconciling
  against per-gateway scales.
- **Backed enum `Currency`** — freezes the table; a new currency becomes a
  BC-relevant release.
- **Strings as canonical amount** — invites re-implementing decimal arithmetic.

## Verification

- Package constraints read from `repo.packagist.org` metadata for
  `moneyphp/money` (v4.9.0) and `brick/money` (0.15.2).
- Stripe IDR handling, the zero-decimal list, the `0.50 IDR` minimum, the
  `999999999999` IDR maximum, and the HUF/TWD payout-divisibility rules read from
  `docs.stripe.com/currencies`.
- Midtrans `gross_amount` integer/no-decimal behaviour and the decimal-string form
  in notifications read from `docs.midtrans.com/docs/https-notification-webhooks`
  (sample bodies show `"gross_amount": "10000.00"`).
- Xendit's per-currency decimal rule quoted from the Xendit API reference.

**Not verified:** whether any gateway rounds or rejects a fractional rupiah sent
as `275000.50`. Must be confirmed against sandboxes before the scale adapters are
implemented. Until then the adapters throw.

## Open questions

- Is a sub-unit (sen) amount ever legitimate for the target merchants? If never,
  an `IDR`-specific guard could reject non-whole rupiah at construction.
- Which currencies beyond IDR, PHP, USD, SGD, MYR ship in v1?
