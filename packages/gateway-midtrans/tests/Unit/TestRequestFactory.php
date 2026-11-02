<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Gateway\Midtrans\Tests\Unit;

use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * PSR-17 double delegating to guzzlehttp/psr7, a transitive dev dependency.
 *
 * psr/http-factory ships interfaces only, and the adapter must be exercised through
 * real PSR-7 messages rather than test doubles of the message layer itself.
 */
final class TestRequestFactory implements RequestFactoryInterface, ResponseFactoryInterface, StreamFactoryInterface
{
    private HttpFactory $factory;

    public function __construct()
    {
        $this->factory = new HttpFactory();
    }

    public function createRequest(string $method, $uri): RequestInterface
    {
        return $this->factory->createRequest($method, $uri);
    }

    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        return $this->factory->createResponse($code, $reasonPhrase);
    }

    public function createStream(string $content = ''): StreamInterface
    {
        return $this->factory->createStream($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        return $this->factory->createStreamFromFile($filename, $mode);
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        return $this->factory->createStreamFromResource($resource);
    }
}
