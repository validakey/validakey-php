<?php

declare(strict_types=1);

namespace Validakey\Tests;

use Symfony\Contracts\HttpClient\ResponseInterface;

final class MockResponse implements ResponseInterface
{
    public function __construct(
        private readonly int $statusCode,
        private readonly string $content,
    ) {
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getHeaders(bool $throw = true): array
    {
        return array('content-type' => array('application/json'));
    }

    public function getContent(bool $throw = true): string
    {
        if ($throw && $this->statusCode >= 400) {
            return $this->content;
        }

        return $this->content;
    }

    public function toArray(bool $throw = true): array
    {
        $decoded = json_decode($this->content, true);

        return is_array($decoded) ? $decoded : array();
    }

    public function cancel(): void
    {
    }

    public function getInfo(?string $type = null): mixed
    {
        return null;
    }
}
