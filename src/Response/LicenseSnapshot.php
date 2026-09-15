<?php

declare(strict_types=1);

namespace Validakey\Response;

/**
 * Last local verification of a subject's license.
 *
 * Written by {@see \Validakey\License::revalidate()} / {@see \Validakey\License::request()}
 * so {@see \Validakey\License::allows()} can gate features without a network call.
 */
final class LicenseSnapshot
{
    public function __construct(
        public readonly bool $granted,
        public readonly int $checkedAt,
        public readonly ?int $expiresAt,
        public readonly string $reason,
        public readonly string $subject,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $expires = $data['expires_at'] ?? null;

        return new self(
            granted: ! empty($data['granted']),
            checkedAt: (int) ($data['checked_at'] ?? 0),
            expiresAt: null === $expires || '' === $expires ? null : (int) $expires,
            reason: isset($data['reason']) ? (string) $data['reason'] : '',
            subject: isset($data['subject']) ? (string) $data['subject'] : '',
        );
    }

    /**
     * @return array{granted: bool, checked_at: int, expires_at: ?int, reason: string, subject: string}
     */
    public function toArray(): array
    {
        return array(
            'granted' => $this->granted,
            'checked_at' => $this->checkedAt,
            'expires_at' => $this->expiresAt,
            'reason' => $this->reason,
            'subject' => $this->subject,
        );
    }

    public static function fromStatus(LicenseStatus $status, ?int $checkedAt = null): self
    {
        return new self(
            granted: $status->isGranted(),
            checkedAt: $checkedAt ?? time(),
            expiresAt: $status->expiresAt(),
            reason: $status->reason(),
            subject: $status->subject,
        );
    }
}
