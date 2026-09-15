<?php

declare(strict_types=1);

namespace Validakey\Tests;

use Symfony\Contracts\HttpClient\ResponseInterface;
use Validakey\Http\HttpTransport;

/**
 * Records requests and replays canned or computed responses.
 *
 * A handler may be a MockResponse for a fixed reply, or a callable for
 * endpoints whose reply depends on the request, which the sealed endpoints do
 * because the server has to decrypt before it can answer.
 */
final class MockHttpTransport implements HttpTransport
{
    /** @var array<string, MockResponse|callable> */
    private array $responses;

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = array();

    /**
     * @param array<string, MockResponse|callable> $responses Keyed by "METHOD url".
     */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $this->requests[] = array(
            'method' => $method,
            'url' => $url,
            'options' => $options,
        );

        $key = $method . ' ' . $url;
        $handler = $this->responses[$key] ?? null;

        if (null === $handler) {
            throw new \RuntimeException(sprintf(
                'Unexpected request: %s. Registered: %s',
                $key,
                implode(', ', array_keys($this->responses))
            ));
        }

        return $handler instanceof MockResponse ? $handler : $handler($method, $url, $options);
    }

    /**
     * @return array{method: string, url: string, options: array<string, mixed>}
     */
    public function lastRequest(): array
    {
        if (array() === $this->requests) {
            throw new \RuntimeException('No request recorded.');
        }

        return $this->requests[count($this->requests) - 1];
    }

    /**
     * @return list<array{method: string, url: string, options: array<string, mixed>}>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * Requests matching a "METHOD url" key.
     *
     * @return list<array{method: string, url: string, options: array<string, mixed>}>
     */
    public function requestsTo(string $key): array
    {
        return array_values(array_filter(
            $this->requests,
            static fn (array $request): bool => $request['method'] . ' ' . $request['url'] === $key
        ));
    }

    public function requestCount(): int
    {
        return count($this->requests);
    }
}
