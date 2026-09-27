<?php

declare(strict_types=1);

namespace Validakey\Request;

use Validakey\Response\MintPolicyResponse;

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
     * @param ?int $taxCents Sales tax in cents (preferred over {@see $taxUsd}).
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
        public readonly ?int $taxCents = null,
    ) {
    }

    /**
     * A type 1 (transactional) vKey: no expiry, no price.
     *
     * The seller account still needs a card on file for the handshake. The
     * Instance Entity does not, because this request carries no amount.
     *
     * Do not use this when the User App enforces mint policy with a non-zero
     * price — prefer {@see fromMintPolicy()} or an empty request so omitted
     * fields take server defaults.
     */
    public static function free(): self
    {
        return new self(duration: 0, noExpiry: true);
    }

    /**
     * Build a mint request from an app’s public mint policy defaults.
     *
     * When policy is disabled, returns an empty request (server one-hour
     * default). When enabled, copies defined defaults so the client and server
     * agree on price and shape without inventing values.
     */
    public static function fromMintPolicy(MintPolicyResponse $policy): self
    {
        if (! $policy->isEnabled()) {
            return new self();
        }

        $defaults = $policy->defaults;
        $duration = null;
        if (array_key_exists('duration', $defaults) && null !== $defaults['duration'] && '' !== $defaults['duration']) {
            $duration = (int) $defaults['duration'];
        }

        $uses = null;
        if (array_key_exists('uses', $defaults) && null !== $defaults['uses'] && '' !== $defaults['uses']) {
            $uses = (int) $defaults['uses'];
        }

        $basisCents = null;
        if (array_key_exists('basis_cents', $defaults) && null !== $defaults['basis_cents'] && '' !== $defaults['basis_cents']) {
            $basisCents = (int) $defaults['basis_cents'];
        }

        $taxCents = null;
        if (array_key_exists('tax_cents', $defaults) && null !== $defaults['tax_cents'] && '' !== $defaults['tax_cents']) {
            $taxCents = (int) $defaults['tax_cents'];
        }

        $noExpiry = ! empty($defaults['no_expiry']);
        if ($noExpiry && null === $duration) {
            $duration = 0;
        }

        $autoRenew = null;
        $recurrence = null;
        if (! $noExpiry && ! empty($defaults['auto_renew'])) {
            $autoRenew = true;
            if (array_key_exists('recurrence', $defaults) && null !== $defaults['recurrence'] && '' !== $defaults['recurrence']) {
                $recurrence = (string) $defaults['recurrence'];
            } else {
                $recurrence = 'monthly';
            }
            if (null === $duration) {
                $duration = 2592000;
            }
        } elseif (array_key_exists('auto_renew', $defaults)) {
            $autoRenew = false;
        }

        return new self(
            duration: $duration,
            uses: $uses,
            recurrence: $recurrence,
            autoRenew: $autoRenew,
            basisCents: $basisCents,
            taxCents: $taxCents,
            noExpiry: $noExpiry,
        );
    }

    /**
     * Omit every mint field so an enabled server mint policy can fill defaults.
     */
    public static function serverDefaults(): self
    {
        return new self();
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
        if (null !== $this->taxCents) {
            $payload['tax_cents'] = $this->taxCents;
        } elseif (null !== $this->taxUsd) {
            $payload['tax_USD'] = $this->taxUsd;
        }

        return $payload;
    }
}
