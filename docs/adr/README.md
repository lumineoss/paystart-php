# Architecture Decision Records

Deliverable 2 for the Paystart payment orchestration library. Each ADR follows
Status / Context / Decision / Consequences / Alternatives / Verification.

| ID | Title | Status |
|---|---|---|
| [0001](0001-amount-representation.md) | Amount representation (Money value object) | Accepted |
| [0002](0002-persistence-and-orm.md) | Persistence and ORM strategy | Accepted |
| [0003](0003-package-split.md) | Package split, compatibility matrix, versioning | Accepted |
| [0004](0004-qr-rendering.md) | QR rendering library | Accepted |
| [0005](0005-gateway-sdk-wrapping.md) | How gateway SDKs are wrapped | Accepted |

## Supported PHP versions

The library floor is **PHP 8.2**. That is a deliberate decision, and it has one
consequence that must be visible rather than buried:

> PHP 8.2 leaves security-only support on **31 December 2026** (php.net
> supported-versions table). Hosts running the library on 8.2 are on a runtime
> that no longer receives security patches after that date.

Recommended: run on 8.3+ where the framework allows it. The floor exists so that
long-lived LTS hosts are not excluded outright.

## Package versions verified for this deliverable

Queried from `repo.packagist.org` (latest metadata at the time of writing, not
necessarily the version you will install):

| Package | Latest seen | `require.php` | Notable |
|---|---|---|---|
| `moneyphp/money` | v4.9.0 | `~8.1.0 \|\| ~8.2.0 \|\| ~8.3.0 \|\| ~8.4.0 \|\| ~8.5.0` | hard `ext-bcmath` |
| `brick/money` | 0.15.2 | `^8.2` | `brick/math` + `psr/simple-cache`, no bcmath |
| `bacon/bacon-qr-code` | v3.1.1 | `^8.1` | `ext-iconv`, `dasprid/enum`; Imagick only suggested |
| `endroid/qr-code` | 6.1.3 | `^8.4` | unusable on the 8.2 floor; 6.0.9 is `^8.2` |
| `midtrans/midtrans-php` | 2.6.2 | `>=5.4` | `ext-curl`, `ext-json`, `ext-openssl` |
| `xendit/xendit-php` | 7.0.0 | `^7.4 \|\| ^8.0` | brings `guzzlehttp/guzzle ^7.3`, `guzzlehttp/psr7`, `vlucas/phpdotenv` |
| `stripe/stripe-php` | v22.1.0 stable | `>=7.4.0` | `ext-curl`, `ext-json`, `ext-mbstring` |

Dev tooling newest release usable on 8.2: `phpunit ^11.5`, `pest ^3.8`,
`infection ^0.32`, `orchestra/testbench ^10.12`, `symfony/framework-bundle ^6.4 || ^7.4`,
`doctrine/doctrine-bundle ^2.19`. Full reasoning in ADR 0003.
