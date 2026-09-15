<?php

declare(strict_types=1);

namespace Validakey\Http;

use Symfony\Contracts\HttpClient\ResponseInterface;

interface HttpTransport
{
    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface;
}
