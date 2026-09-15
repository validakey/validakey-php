<?php

declare(strict_types=1);

namespace Validakey\Exception;

class ApiException extends ValidakeyException
{
    public function __construct(
        string $message,
        public readonly ?string $errorCode = null,
        public readonly int $httpStatus = 400,
        public readonly mixed $data = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromErrorPayload(array $payload, int $httpStatus): self
    {
        $message = isset($payload['ERROR']) ? (string) $payload['ERROR'] : 'Validakey API error.';
        $code = isset($payload['CODE']) ? (string) $payload['CODE'] : null;
        $data = $payload['DATA'] ?? null;

        if (402 === $httpStatus || 'payment_required' === $code) {
            return new PaymentRequiredException($message, $code, $httpStatus, $data);
        }

        return new self($message, $code, $httpStatus, $data);
    }
}
