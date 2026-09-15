<?php

declare(strict_types=1);

namespace Validakey\Response;

/**
 * The result of a validate, renew or revoke call against /v1/v/.
 *
 * The server answers all three in one sealed shape, so one response type
 * covers them. `valid` is authoritative; `reason` explains a false.
 */
final class TokenVerifyResponse
{
    /**
     * @param array<string, mixed> $data Opened reply payload.
     */
    public function __construct(
        public readonly array $data,
    ) {
    }

    public function isValid(): bool
    {
        return ! empty($this->data['valid']);
    }

    /**
     * Why the token is not valid: revoked, expired, depleted, not_found,
     * or a billing failure code from a renewal. Empty when it is valid.
     */
    public function reason(): string
    {
        return isset($this->data['reason']) ? (string) $this->data['reason'] : '';
    }

    /**
     * Unix expiry, or null when the vKey never expires (type 1).
     */
    public function expiresAt(): ?int
    {
        $expires = $this->data['expires_at'] ?? null;
        if (null === $expires) {
            return null;
        }

        // The server sends 0 for "no expiry"; a nullable int says that more
        // clearly than a sentinel a caller has to remember to special-case.
        return 0 === (int) $expires ? null : (int) $expires;
    }

    /**
     * Remaining uses, or null when the vKey is not use-limited.
     *
     * Zero is a real answer — depleted — and must not be confused with
     * unlimited, so this stays nullable rather than defaulting to 0.
     */
    public function usesRemaining(): ?int
    {
        $uses = $this->data['uses_remaining'] ?? null;

        return null === $uses ? null : (int) $uses;
    }

    public function wasRevoked(): bool
    {
        return ! empty($this->data['revoked']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self($payload);
    }
}
