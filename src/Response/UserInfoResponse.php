<?php

declare(strict_types=1);

namespace Validakey\Response;

final class UserInfoResponse
{
    /**
     * @param array<string, mixed> $billing
     */
    public function __construct(
        public readonly string $apiUserId,
        public readonly array $billing,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromSuccessEnvelope(array $payload): self
    {
        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : $payload;

        return new self(
            apiUserId: (string) ($data['api_user_id'] ?? ''),
            billing: isset($data['billing']) && is_array($data['billing']) ? $data['billing'] : array(),
        );
    }
}
