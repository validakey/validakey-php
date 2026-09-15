<?php

declare(strict_types=1);

namespace Validakey\Response;

final class BillingStatusResponse
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly array $data,
    ) {
    }

    public function billingStatus(): ?string
    {
        $status = $this->data['billing_status'] ?? null;

        return null === $status ? null : (string) $status;
    }

    public function hasCard(): bool
    {
        return ! empty($this->data['has_card']);
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
