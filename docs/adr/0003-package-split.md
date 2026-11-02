# ADR 0003 — Package split, compatibility matrix and versioning

- Status: Accepted
- Deciders: library maintainers
- Constraint: library floor is **PHP 8.2**

## Context

The strategic design fixes six bounded contexts and three framework bridges.
`core` must stay framework-free, and gateway SDKs and QR libraries must not be
forced on consumers.

The PHP floor deserves its own note. PHP 8.2 leaves security-only support on
**31 December 2026** (php.net supported-versions table: active support ended
31 Dec 2024, security support ends 31 Dec 2026). Choosing 8.2 therefore means
supporting a runtime that will shortly receive no security patches. That is an
accepted, documented trade-off, not an oversight — the alternative excludes hosts
on long-lived LTS stacks.

## Decision

Monorepo `packages/*`, each publishable, wired with Composer `path` repositories
for local development and split-published to standalone repositories.

| Package | Contents | Hard requires |
|---|---|---|
| `core` | SharedKernel + Payment + Accounting + LoggingAudit (Domain / Application / Port) | `php ^8.2`, PSR packages only |
| `gateway-midtrans` | ACL adapter + webhook strategy | `core`, `psr/http-client`, `psr/http-factory` |
| `gateway-xendit` | same | `core`, `xendit/xendit-php ^7.0` |
| `gateway-stripe` | same | `core`, `stripe/stripe-php ^22.1` |
| `qr-renderer` | `QrRendererPort` adapter | `core`, `bacon/bacon-qr-code ^3.1` |
| `bridge-laravel` | provider, config, migrations, routes, commands | `core`, `illuminate/* ^11 \|\| ^12` |
| `bridge-symfony` | bundle, DI, Doctrine mapping, commands | `core`, `symfony/* ^6.4 \|\| ^7.4`, `doctrine/orm ^3.7` |
| `bridge-standalone` | PDO repositories, migrations, bootstrap | `core` |
| `testing` | fakes, in-memory repos, contract suites | `core` |

`qr-renderer` is an addition to the original tree. ADR 0004 requires the QR
library to be absent from `core` and optional for consumers; `suggest` in `core`
cannot work because the adapter class needs the library to load. A separate
package is the only clean option.

### Compatibility matrix (verified)

Dev tooling, newest release usable on the 8.2 floor:

| Package | Constraint | Why not newer |
|---|---|---|
| `phpunit/phpunit` | `^11.5` | 12.x needs `>=8.3`, 13.x needs `>=8.4.1` |
| `pestphp/pest` | `^3.8` (optional) | 4.x needs `^8.3`, 5.x needs `^8.4` |
| `infection/infection` | `^0.32 \|\| ^0.35` | 0.35 needs `^8.3`; 0.32.6 is the newest `^8.2` release |
| `orchestra/testbench` | `^10.12` | 11.x needs `^8.3` (and pins Laravel 13) |
| `symfony/framework-bundle` | `^6.4 \|\| ^7.4` | 8.x needs `>=8.4.1`; 7.4 is the LTS and supports 8.2 |
| `doctrine/doctrine-bundle` | `^2.19` | 3.x needs `^8.4` |
| `doctrine/orm` | `^3.7` | compatible (`^8.1`) |
| `phpstan/phpstan` | `^2.3` | compatible |
| `deptrac/deptrac` | `^4.7` | compatible (`^8.2`) |
| `phpat/phpat` | `^0.12` | compatible (`^8.1`) |
| `friendsofphp/php-cs-fixer` | `^3.95` | compatible |

Framework support implications of the 8.2 floor:

- `bridge-laravel` supports **Laravel 11 and 12 only**. Laravel 13 requires PHP
  8.3+. Laravel 12 receives security fixes until 24 Feb 2027; Laravel 11 ended
  12 Mar 2026, so the practical target is Laravel 12.
- `bridge-symfony` supports **Symfony 6.4 and 7.4**. 7.4 is the current LTS
  (PHP 8.2+, security fixes to Nov 2029), so this side is comfortable.

### Language features unavailable on 8.2

Forbidden in all packages, enforced by the 8.2 CI lane plus a PHPStan rule:

- `#[\Override]` attribute (8.3)
- typed class constants (8.3)
- `json_validate()` (8.3), dynamic class constant fetch (8.3)
- property hooks, asymmetric visibility (8.4)
- `array_find()`, `array_any()`, `array_all()` (8.4)
- `new Foo()->bar()` without parentheses (8.4)

Available and used: `final readonly class`, constructor promotion, backed enums,
`match`, DNF types, standalone `true`/`false`/`null` types, `never`.

### Versioning

`core` is the compatibility anchor. Adapters and bridges declare
`"lumineoss/paystart-core": "^1.0"` and version in lockstep while pre-1.0; after
1.0, `core` majors gate everything. Every package declares `php: ^8.2` and an
explicit framework matrix. Gateway SDK and QR library ranges are pinned in their
own package, never in `core`.

Publishing uses `splitsh-lite` (or `git subtree split`) per package with per
package tags. CI fails if a package's `composer.json` is missing its `php`
constraint or drifts from the lockstep version.

## Consequences

- Clean install footprint: a Stripe-only Symfony user pulls no Midtrans SDK.
- Hosts on PHP 8.2 get a working library, with a documented EOL warning.
- Costs: nine pipelines, split-publish machinery, dependency drift risk between
  `core` and adapters (mitigated by a CI matrix that installs adapters against
  the in-repo `core` and against the lowest supported released `core`).
- Infection 0.32.6 is several generations behind 0.35.8. Mitigation: run mutation
  testing on the 8.3+ lane only, where the newer Infection resolves; it is a dev
  dependency and never reaches a host.

## Alternatives rejected

- **Single package with `suggest` for SDKs** — consumers still get everything in
  the repository, and optional-capability handling gets murky.
- **Separate repositories as source of truth** — `core` interface churn becomes
  cross-repository pull requests.
- **PHP 8.3 floor** — buys newer dev tooling and Laravel 13, but excludes the
  long-lived LTS hosts this library is meant to serve. Rejected by decision, not
  by oversight.
