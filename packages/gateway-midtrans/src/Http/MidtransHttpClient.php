<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Gateway\Midtrans\Http;

use DateTimeImmutable;
use JsonException;
use Lumineoss\Paystart\Gateway\Midtrans\Config\MidtransConfig;
use Lumineoss\Paystart\Gateway\Midtrans\Exception\MidtransTransactionError;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * The thin HTTP layer for Midtrans.
 *
 * Responsibilities stop at transport: build the request, send it, decode JSON, and
 * classify the outcome into "transport failure" (retryable) or "Midtrans says no"
 * (terminal). No domain mapping happens here.
 *
 * The server key is passed as a Basic credential built from config and is never placed
 * in a URL, so it cannot leak through a proxy access log.
 */
final readonly class MidtransHttpClient
{
    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private MidtransConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     *
     * @throws ClientExceptionInterface         transport failure; retryable
     * @throws MidtransTransactionError          Midtrans rejected the request
     */
    public function post(string $path, ?array $body = null): array
    {
        return $this->send('POST', $path, $body);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ClientExceptionInterface
     * @throws MidtransTransactionError
     */
    public function get(string $path): array
    {
        return $this->send('GET', $path, null);
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, ?array $body): array
    {
        $request = $this->requestFactory
            ->createRequest($method, $this->config->baseUrl() . $path)
            ->withHeader('Accept', 'application/json')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Authorization', $this->config->authorizationHeader());

        if ($body !== null) {
            $encoded = json_encode($body, JSON_THROW_ON_ERROR);

            $request = $request->withBody($this->streamFactory->createStream($encoded));
        }

        // Logged without the body: a charge body can carry customer details, and a
        // response body can carry a masked card number.
        $this->logger->debug('Midtrans request.', ['method' => $method, 'path' => $path]);

        $response = $this->httpClient->sendRequest($request);

        return $this->decode($response, $path);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response, string $path): array
    {
        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();

        try {
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new \RuntimeException(\sprintf(
                'Midtrans returned a non-JSON body for "%s" (HTTP %d).',
                $path,
                $status,
            ));
        }

        if (!\is_array($decoded)) {
            throw new \RuntimeException(\sprintf('Midtrans returned a non-object body for "%s".', $path));
        }

        /** @var array<string, mixed> $decoded */
        $rawStatusCode = $decoded['status_code'] ?? null;
        $statusCode = match (true) {
            \is_string($rawStatusCode) => $rawStatusCode,
            \is_int($rawStatusCode) => (string) $rawStatusCode,
            default => '',
        };

        // Midtrans signals success in the body as well as the HTTP status, and the two
        // can disagree. A 2xx with status_code 200/201 is success; anything else is a
        // rejection whose status_code is safe to report and whose message is not.
        $isSuccess = $status >= 200 && $status < 300
            && \in_array($statusCode, ['200', '201', '202'], true);

        if (!$isSuccess) {
            $this->logger->warning('Midtrans rejected a request.', [
                'path' => $path,
                'httpStatus' => $status,
                'statusCode' => $statusCode,
            ]);

            throw MidtransTransactionError::forStatus($statusCode, (string) $status);
        }

        return $decoded;
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
