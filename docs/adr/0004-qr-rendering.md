# ADR 0004 — QR rendering library

- Status: Accepted
- Deciders: library maintainers
- Constraint: library floor is PHP 8.2

## Context

Most providers return a QR **image URL** or at least the QR payload string;
re-rendering is the exception, not the rule. The renderer is therefore optional,
must not drag heavy extensions into `core`, and must degrade to a Null Object.

Candidates (metadata from `repo.packagist.org`, source read from the repositories):

| Package | Latest | `require.php` | Hard requires | Notes |
|---|---|---|---|---|
| `bacon/bacon-qr-code` | v3.1.1 | `^8.1` | `ext-iconv`, `dasprid/enum ^1.0.3` | `ext-imagick` only `suggest`ed ("to generate QR code images") |
| `endroid/qr-code` | 6.1.3 | `^8.4` | `bacon/bacon-qr-code ^3.0` | 6.0.9 is `^8.2` |

Verified in `bacon/bacon-qr-code` source:

- `BaconQrCode\Renderer\Image\SvgImageBackEnd` writes SVG via PHP's built-in
  `XMLWriter` and throws if that class is unavailable. No image extension needed.
- `ImagickImageBackEnd` and `EpsImageBackEnd` exist but are only reachable if the
  caller selects them; Imagick is not a hard requirement.
- The library is 8.2-compatible, so the floor does not affect this choice.

`endroid/qr-code` 6.1.3 requires PHP 8.4, which is above both the 8.2 floor and
the originally proposed 8.3 floor — it is unusable, not merely unpreferred.
Pinning 6.0.9 would mean fighting the upgrade path for no benefit.

## Decision

`QrRendererPort` lives in `core`. The default adapter lives in its own package,
`packages/qr-renderer`, which requires `bacon/bacon-qr-code ^3.1`:

```php
interface QrRendererPort
{
    /** @return array{0: string, 1: string} [bytes, mimeType] */
    public function render(QrPayload $payload, QrRenderOptions $options): array;
}
```

- SVG is the default output format: no GD or Imagick dependency, renders in
  browsers, prints cleanly.
- PNG is available only when the host has `ext-imagick` and explicitly selects it;
  `ext-imagick` is listed in `suggest`, never in `require`.
- When the package is not installed, `NullQrRenderer` is injected and throws
  `QrRenderingUnavailable` rather than emitting a broken image.
- If a provider already returned a `QR_IMAGE_URL`, the renderer is never invoked.
  That rule belongs to the application layer, not to the adapter.
- Error correction level defaults to `QrRenderOptions::$errorCorrection`
  (default: medium) and is caller-selectable; the payload is never logged.

Because this module serves images, it also serves bearer-like content: render
endpoints must be signed and expiring, and the payload must be masked in logs
(see the security section of the main specification).

## Consequences

- Zero extension requirements for the common path; only `ext-iconv`, which is
  effectively universal.
- One extra package in the tree, justified by the "QR library must not be in
  `core`" requirement.
- The QR test decodes the produced image and compares it against the payload
  rather than string-comparing markup, so a rendering regression cannot pass.

## Alternatives rejected

- **`endroid/qr-code`** — PHP 8.4 floor at the current version; extra GD
  requirement; fluent API we do not need.
- **QR library inside `core`** — violates the optional-capability rule and forces
  `ext-iconv` (and transitively `dasprid/enum`) on every consumer.
- **`suggest` inside `core` only** — the adapter class cannot load without the
  library, so a separate package is required.
