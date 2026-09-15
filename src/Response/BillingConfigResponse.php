<?php

declare(strict_types=1);

namespace Validakey\Response;

final class BillingConfigResponse
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly array $data,
    ) {
    }

    public function applicationId(): ?string
    {
        $value = $this->data['application_id'] ?? null;

        return null === $value ? null : (string) $value;
    }

    public function locationId(): ?string
    {
        $value = $this->data['location_id'] ?? null;

        return null === $value ? null : (string) $value;
    }

    public function isSandbox(): bool
    {
        return ! empty($this->data['sandbox']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromSuccessEnvelope(array $payload): self
    {
        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : $payload;

        return new self($data);
    }
}
