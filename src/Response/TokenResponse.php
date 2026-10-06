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
        public readonly ?string $doorSeed = null,
        public readonly ?int $doorBucketSecs = null,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $out = isset($payload['out']) && is_array($payload['out']) ? $payload['out'] : $payload;

        $doorSeed = self::normalizeDoorSeed($out['door_seed'] ?? null);
        $doorBucket = isset($out['door_bucket_secs']) ? (int) $out['door_bucket_secs'] : null;
        if (null !== $doorBucket && $doorBucket <= 0) {
            $doorBucket = null;
        }

        return new self(
            token: (string) ($out['token'] ?? ''),
            created: (int) ($out['created'] ?? 0),
            expiresAt: (int) ($out['expires_at'] ?? $out['expires'] ?? 0),
            raw: $payload,
            doorSeed: $doorSeed,
            doorBucketSecs: $doorBucket,
        );
    }

    /**
     * Per-vKey Dynamic Pass seed (16-char hex), or null when the app flag is off.
     *
     * Issued once at mint (or first sealed /v1/v/ sync). Store it server-side;
     * never put it in HTML or email. Use {@see \Validakey\Door} to pack /g/ codes.
     */
    public function doorSeed(): ?string
    {
        return $this->doorSeed;
    }

    /**
     * Dynamic Pass bucket length in seconds (typically 60), or null when absent.
     */
    public function doorBucketSecs(): ?int
    {
        return $this->doorBucketSecs;
    }

    private static function normalizeDoorSeed(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $hex = strtolower(trim($value));
        if (16 !== strlen($hex) || ! ctype_xdigit($hex)) {
            return null;
        }

        return $hex;
    }
}
