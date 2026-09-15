<?php

declare(strict_types=1);

namespace Validakey\Response;

final class TokenResponse
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly string $token,
        public readonly int $created,
        public readonly int $expiresAt,
        public readonly array $raw,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $out = isset($payload['out']) && is_array($payload['out']) ? $payload['out'] : $payload;

        return new self(
            token: (string) ($out['token'] ?? ''),
            created: (int) ($out['created'] ?? 0),
            expiresAt: (int) ($out['expires_at'] ?? $out['expires'] ?? 0),
            raw: $payload,
        );
    }
}
