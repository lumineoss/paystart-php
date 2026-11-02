<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Payment\Payload;

/**
 * How the QR value is carried.
 */
enum QrFormat: string
{
    /** The raw QR content, to be rendered by us or the host. */
    case String = 'string';

    /** A URL that serves a pre-rendered QR image. Never re-rendered (ADR 0004). */
    case ImageUrl = 'image_url';
}
