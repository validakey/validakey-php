<?php

declare(strict_types=1);

namespace Validakey\Response;

/**
 * Public mint defaults and limits for the instance’s User App.
 *
 * Returned by instance-sealed {@see \Validakey\ValidakeyClient::getMintPolicy()}
 * (`POST /v1/policy/`). No account private key is involved.
 *
 * When {@see $enabled} is true, omit mint fields (or send values inside the
 * limits) so the server can apply defaults; inventing a free or oversized
 * licence is rejected with `mint_policy_violation`.
 */
final class MintPolicyResponse
{
    /**
     * @param array<string, mixed> $defaults
     * @param array<string, mixed> $limits
     * @param array<string, mixed> $raw Opened reply (includes mint_policy key when present).
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly array $defaults,
        public readonly array $limits,
        public readonly array $raw = array(),
    ) {
    }

    /**
     * @param array<string, mixed> $payload Opened sealed reply or account app row fragment.
     */
    public static function fromArray(array $payload): self
    {
        $policy = $payload;
        if (isset($payload['mint_policy']) && is_array($payload['mint_policy'])) {
            $policy = $payload['mint_policy'];
        }

        $defaults = isset($policy['defaults']) && is_array($policy['defaults'])
            ? $policy['defaults']
            : array();
        $limits = isset($policy['limits']) && is_array($policy['limits'])
            ? $policy['limits']
            : array();

        return new self(
            enabled: ! empty($policy['enabled']),
            defaults: $defaults,
            limits: $limits,
            raw: $payload,
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function defaultBasisCents(): ?int
    {
        if (! array_key_exists('basis_cents', $this->defaults) || null === $this->defaults['basis_cents'] || '' === $this->defaults['basis_cents']) {
            return null;
        }

        return (int) $this->defaults['basis_cents'];
    }

    public function defaultTaxCents(): ?int
    {
        if (! array_key_exists('tax_cents', $this->defaults) || null === $this->defaults['tax_cents'] || '' === $this->defaults['tax_cents']) {
            return null;
        }

        return (int) $this->defaults['tax_cents'];
    }

    public function defaultDuration(): ?int
    {
        if (! array_key_exists('duration', $this->defaults) || null === $this->defaults['duration'] || '' === $this->defaults['duration']) {
            return null;
        }

        return (int) $this->defaults['duration'];
    }

    /**
     * Default use quota, or null when unlimited / unset.
     */
    public function defaultUses(): ?int
    {
        if (! array_key_exists('uses', $this->defaults)) {
            return null;
        }
        if (null === $this->defaults['uses'] || '' === $this->defaults['uses']) {
            return null;
        }

        return (int) $this->defaults['uses'];
    }

    public function allowNoExpiry(): bool
    {
        return ! empty($this->limits['allow_no_expiry']);
    }

    /**
     * True when min and max basis are set and equal (fixed price).
     */
    public function isFixedPrice(): bool
    {
        if (! isset($this->limits['basis_cents_min'], $this->limits['basis_cents_max'])) {
            return false;
        }

        return (int) $this->limits['basis_cents_min'] === (int) $this->limits['basis_cents_max'];
    }

    /**
     * @return array{enabled: bool, defaults: array<string, mixed>, limits: array<string, mixed>}
     */
    public function toArray(): array
    {
        return array(
            'enabled' => $this->enabled,
            'defaults' => $this->defaults,
            'limits' => $this->limits,
        );
    }
}
