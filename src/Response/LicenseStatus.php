<?php

declare(strict_types=1);

namespace Validakey\Response;

/**
 * Local-plus-server view of a subject's license.
 *
 * When no vKey is stored, this is a local answer (`hasToken` false) and no
 * network call was made. When a vKey is stored, `check` is the verify reply.
 */
final class LicenseStatus
{
    public function __construct(
        public readonly string $subject,
        public readonly bool $hasToken,
        public readonly ?TokenVerifyResponse $check,
    ) {
    }

    public static function none(string $subject): self
    {
        return new self($subject, false, null);
    }

    public static function fromVerify(string $subject, TokenVerifyResponse $check): self
    {
        return new self($subject, true, $check);
    }

    /**
     * A stored vKey that the server still accepts.
     */
    public function isGranted(): bool
    {
        return $this->hasToken && null !== $this->check && $this->check->isValid();
    }

    public function isValid(): bool
    {
        return $this->isGranted();
    }

    /**
     * Empty when valid. `none` when nothing is stored. Otherwise the verify
     * reason (revoked, expired, depleted, not_found, …).
     */
    public function reason(): string
    {
        if (! $this->hasToken) {
            return 'none';
        }

        return $this->check?->reason() ?? '';
    }

    public function expiresAt(): ?int
    {
        return $this->check?->expiresAt();
    }

    public function usesRemaining(): ?int
    {
        return $this->check?->usesRemaining();
    }
}
