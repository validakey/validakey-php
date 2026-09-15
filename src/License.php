<?php

declare(strict_types=1);

namespace Validakey;

use Validakey\Exception\LicenseRequiredException;
use Validakey\Instance\InMemoryLicenseCheckStore;
use Validakey\Instance\LicenseCheckStore;
use Validakey\Instance\TokenStore;
use Validakey\Request\CreateTokenRequest;
use Validakey\Response\LicenseSnapshot;
use Validakey\Response\LicenseStatus;
use Validakey\Response\TokenResponse;

/**
 * The usual license workflow: persist a vKey, check it, mint one if needed.
 *
 * The client stays a protocol object. This is the application-facing helper
 * so a plugin does not have to invent storage and status mapping itself.
 *
 * {@see allows()} gates on the last stored verification (no network).
 * {@see revalidate()} refreshes that snapshot; {@see status()} / {@see isGranted()}
 * still verify live when a vKey is stored.
 */
final class License
{
    public const DEFAULT_REVALIDATE_INTERVAL = 43200;

    private LicenseCheckStore $checks;

    public function __construct(
        private ValidakeyClient $client,
        private TokenStore $tokens,
        private CreateTokenRequest $spec,
        ?LicenseCheckStore $checks = null,
        private int $revalidateInterval = self::DEFAULT_REVALIDATE_INTERVAL,
        private bool $failClosed = false,
    ) {
        $this->checks = $checks ?? new InMemoryLicenseCheckStore();
        $this->revalidateInterval = max(60, $revalidateInterval);
    }

    public function client(): ValidakeyClient
    {
        return $this->client;
    }

    public function subject(): string
    {
        return $this->client->subject();
    }

    /**
     * How long a granted snapshot stays fresh when {@see $failClosed} is true.
     */
    public function revalidateInterval(): int
    {
        return $this->revalidateInterval;
    }

    /**
     * When true, {@see allows()} denies after {@see revalidateInterval()} even if
     * the last check was granted. When false (default), a granted snapshot is
     * trusted until expiry or the next failed {@see revalidate()}.
     */
    public function failClosed(): bool
    {
        return $this->failClosed;
    }

    public function hasToken(): bool
    {
        return null !== $this->token();
    }

    /**
     * The stored vKey, or null. Do not put this in HTML or JavaScript.
     */
    public function token(): ?string
    {
        $value = $this->tokens->get($this->client->storeKey());

        return is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * Cleartext lookup prefix of the stored vKey.
     *
     * Same width as the wire prefix ({@see Envelope::PREFIX_LEN}). Enough for
     * an operator to find the record; not enough to forge or gift the license.
     */
    public function tokenPrefix(int $length = Envelope::PREFIX_LEN): ?string
    {
        return self::prefixOf($this->token(), $length);
    }

    /**
     * Cleartext lookup prefix of the stored Instance Entity id, or null.
     *
     * Reads the store only — never handshakes.
     */
    public function instancePrefix(int $length = Envelope::PREFIX_LEN): ?string
    {
        return self::prefixOf($this->client->storedInstanceId(), $length);
    }

    /**
     * Obfuscated locator: `{IE prefix}_{vKey prefix}`.
     *
     * Enough for an operator to find both records; neither half alone is the
     * full secret, and the join order is not the raw credential.
     */
    public function tokenLocator(int $length = Envelope::PREFIX_LEN): ?string
    {
        $instance = $this->instancePrefix($length);
        $token = $this->tokenPrefix($length);
        if (null === $instance || null === $token) {
            return null;
        }

        return $instance . '_' . $token;
    }

    /**
     * Live verify when a vKey is stored. Prefer {@see allows()} for request-path gating.
     */
    public function isGranted(): bool
    {
        return $this->status()->isGranted();
    }

    /**
     * Gate on the last verification — no network call.
     *
     * Returns false when nothing has been checked yet, the stored vKey is gone,
     * the snapshot says denied, the vKey's known expiry has passed, or (when
     * fail-closed) the snapshot is older than {@see revalidateInterval()}.
     */
    public function allows(): bool
    {
        if (! $this->hasToken()) {
            return false;
        }

        $snapshot = $this->lastCheck();
        if (null === $snapshot) {
            return false;
        }

        if (! $snapshot->granted) {
            return false;
        }

        if (null !== $snapshot->expiresAt && time() >= $snapshot->expiresAt) {
            return false;
        }

        if ($this->failClosed) {
            return (time() - $snapshot->checkedAt) <= $this->revalidateInterval;
        }

        return true;
    }

    /**
     * @throws LicenseRequiredException when {@see allows()} is false
     */
    public function requireGranted(string $message = 'A valid Validakey license is required.'): void
    {
        if ($this->allows()) {
            return;
        }

        $reason = $this->lastCheck()?->reason ?? 'none';

        throw new LicenseRequiredException($message, $reason);
    }

    /**
     * Last stored verification, or null.
     */
    public function lastCheck(): ?LicenseSnapshot
    {
        return $this->checks->get($this->client->storeKey());
    }

    private static function prefixOf(?string $value, int $length): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        $length = max(1, $length);

        return substr($value, 0, $length);
    }

    /**
     * If no vKey is stored, this is local and does not call the API.
     * Otherwise it verifies read-only (does not consume uses) and updates the
     * verification snapshot.
     */
    public function status(): LicenseStatus
    {
        $token = $this->token();
        if (null === $token) {
            $status = LicenseStatus::none($this->subject());
            $this->remember($status);

            return $status;
        }

        $status = LicenseStatus::fromVerify(
            $this->subject(),
            $this->client->verifyToken($token)
        );
        $this->remember($status);

        return $status;
    }

    /**
     * Verify the stored vKey (or record "none") and refresh the local snapshot.
     *
     * Safe to call from cron. Network / API failures propagate so the caller
     * can log them; the previous snapshot is left unchanged on throw.
     */
    public function revalidate(): LicenseStatus
    {
        return $this->status();
    }

    /**
     * Mint and persist a vKey when this subject has no valid grant.
     *
     * Idempotent: a still-valid stored vKey is returned without minting.
     * An invalid stored vKey is replaced. PaymentRequiredException and other
     * API errors propagate so the caller can tell seller billing from a
     * customer card requirement.
     */
    public function request(?CreateTokenRequest $spec = null): TokenResponse
    {
        $spec ??= $this->spec;
        $existing = $this->token();
        if (null !== $existing) {
            $check = $this->client->verifyToken($existing);
            if ($check->isValid()) {
                $this->remember(LicenseStatus::fromVerify($this->subject(), $check));

                return new TokenResponse(
                    token: $existing,
                    created: 0,
                    expiresAt: $check->expiresAt() ?? 0,
                    raw: array(),
                );
            }
        }

        $response = $this->client->createToken($spec);
        $this->tokens->set($this->client->storeKey(), $response->token);
        $this->checks->set(
            $this->client->storeKey(),
            new LicenseSnapshot(
                granted: true,
                checkedAt: time(),
                expiresAt: 0 === $response->expiresAt ? null : $response->expiresAt,
                reason: '',
                subject: $this->subject(),
            )
        );

        return $response;
    }

    /**
     * Drop the stored vKey and verification snapshot. Does not revoke server-side.
     */
    public function forget(): void
    {
        $key = $this->client->storeKey();
        $this->tokens->forget($key);
        $this->checks->forget($key);
    }

    private function remember(LicenseStatus $status): void
    {
        $this->checks->set(
            $this->client->storeKey(),
            LicenseSnapshot::fromStatus($status)
        );
    }
}
