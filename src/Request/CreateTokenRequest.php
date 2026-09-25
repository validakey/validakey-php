<?php

declare(strict_types=1);

namespace Validakey\Request;

final class CreateTokenRequest
{
    /**
     * @param ?int $duration Lifetime in seconds. Omit (null) when the mint has
     *                       no clock limit (e.g. uses-only Limited). Pass 0 with
     *                       {@see $noExpiry} for a transactional perpetual vKey.
     * @param bool $noExpiry Mint a vKey that never expires, which only
     *                       revocation can end. This is what a transactional
     *                       (type 1) vKey needs, and it has to be asked for
     *                       explicitly: silently reading a zero duration as
     *                       "forever" would turn a misconfigured value into a
     *                       perpetual license.
     */
    public function __construct(
        public readonly ?int $duration = null,
        public readonly ?int $expiresAt = null,
        public readonly ?int $uses = null,
        public readonly ?string $recurrence = null,
        public readonly ?bool $autoRenew = null,
        public readonly ?int $basisCents = null,
        public readonly ?float $amount = null,
        public readonly ?float $costUsd = null,
        public readonly ?float $taxUsd = null,
        public readonly bool $noExpiry = false,
    ) {
    }

    /**
     * A type 1 (transactional) vKey: no expiry, no price.
     *
     * The seller account still needs a card on file for the handshake. The
     * Instance Entity does not, because this request carries no amount.
     */
    public static function free(): self
    {
        return new self(duration: 0, noExpiry: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = array();

        if (null !== $this->duration) {
            $payload['duration'] = $this->duration;
        }
        if ($this->noExpiry) {
            $payload['no_expiry'] = true;
        }
        if (null !== $this->expiresAt) {
            $payload['expires_at'] = $this->expiresAt;
        }
        if (null !== $this->uses) {
            $payload['uses'] = $this->uses;
        }
        if (null !== $this->recurrence) {
            $payload['recurrence'] = $this->recurrence;
        }
        if (null !== $this->autoRenew) {
            $payload['auto_renew'] = $this->autoRenew;
        }
        if (null !== $this->basisCents) {
            $payload['basis_cents'] = $this->basisCents;
        } elseif (null !== $this->amount) {
            $payload['amount'] = $this->amount;
        } elseif (null !== $this->costUsd) {
            $payload['cost_USD'] = $this->costUsd;
        }
        if (null !== $this->taxUsd) {
            $payload['tax_USD'] = $this->taxUsd;
        }

        return $payload;
    }
}
