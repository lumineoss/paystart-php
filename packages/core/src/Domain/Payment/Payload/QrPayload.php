<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Payment\Payload;

use Lumineoss\Paystart\Core\Domain\Payment\InstructionPayload;
use Lumineoss\Paystart\Core\Domain\Shared\Exception\InvalidArgument;

/**
 * QR content, or a URL pointing at a provider-hosted QR image.
 *
 * The content is a bearer-like credential: anyone who scans it can attempt to
 * pay. It is therefore excluded from logs and from audit descriptions.
 */
final readonly class QrPayload implements InstructionPayload
{
    public function __construct(
        public string $content,
        public QrFormat $format = QrFormat::String,
    ) {
        if (trim($content) === '') {
            throw InvalidArgument::because('QR payload content must not be empty.');
        }

        if (\strlen($content) > 8192) {
            throw InvalidArgument::because('QR payload content exceeds 8192 bytes.');
        }
    }

    public static function fromImageUrl(string $url): self
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw InvalidArgument::because('QR image URL is not a valid URL.');
        }

        return new self($url, QrFormat::ImageUrl);
    }

    public function isImageUrl(): bool
    {
        return $this->format === QrFormat::ImageUrl;
    }

    public function describeForAudit(): string
    {
        return \sprintf('qr(format=%s,bytes=%d)', $this->format->value, \strlen($this->content));
    }
}
