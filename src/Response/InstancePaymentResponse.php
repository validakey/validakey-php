<?php

declare(strict_types=1);

namespace Validakey\Response;

/**
 * The result of an Instance Entity card operation against /v1/p/.
 *
 * The Instance Entity is the end customer running the host application. They
 * hold no account and no private key, so their card is collected over the
 * sealed channel and stored against their instance.
 *
 * A declined card is not an exception here: the server seals the failure and
 * answers 200, because "this card was declined" is something the application
 * shows the customer, not an error in the call.
 */
final class InstancePaymentResponse
{
    /**
     * @param array<string, mixed> $data Opened reply payload.
     */
    public function __construct(
        public readonly array $data,
    ) {
    }

    public function isOk(): bool
    {
        return ! empty($this->data['ok']);
    }

    /**
     * Failure code when the operation did not succeed, e.g. a decline.
     */
    public function errorCode(): string
    {
        return isset($this->data['code']) ? (string) $this->data['code'] : '';
    }

    public function errorMessage(): string
    {
        return isset($this->data['error']) ? (string) $this->data['error'] : '';
    }

    public function hasCard(): bool
    {
        return ! empty($this->data['has_card']);
    }

    public function cardBrand(): string
    {
        return isset($this->data['brand']) ? (string) $this->data['brand'] : '';
    }

    public function cardLast4(): string
    {
        return isset($this->data['last4']) ? (string) $this->data['last4'] : '';
    }

    /**
     * The hosted payment URL, present only on a link request.
     */
    public function paymentUrl(): ?string
    {
        $url = $this->data['url'] ?? null;

        return null === $url || '' === $url ? null : (string) $url;
    }

    /**
     * When the hosted payment link stops working.
     */
    public function expiresAt(): ?int
    {
        $expires = $this->data['expires_at'] ?? null;

        return null === $expires ? null : (int) $expires;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self($payload);
    }

    /**
     * Transport or decode failure turned into a declined-style result so a
     * malformed server body does not unwind the host application.
     */
    public static function fromException(\Throwable $e): self
    {
        $code = 'invalid_response';
        $status = 0;
        $data = null;

        if ($e instanceof \Validakey\Exception\ApiException) {
            $code = $e->errorCode ?? 'invalid_response';
            $status = $e->httpStatus;
            $data = $e->data;
        }

        return new self(array(
            'ok' => false,
            'code' => $code,
            'error' => $e->getMessage(),
            'status' => $status,
            'has_card' => false,
            'ie_status' => 'none',
            'brand' => '',
            'last4' => '',
            'data' => $data,
        ));
    }
}
