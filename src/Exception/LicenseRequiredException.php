<?php

declare(strict_types=1);

namespace Validakey\Exception;

/**
 * Thrown by {@see \Validakey\License::requireGranted()} when the last check denies access.
 */
final class LicenseRequiredException extends ValidakeyException
{
    public function __construct(
        string $message = 'A valid Validakey license is required.',
        public readonly string $reason = 'none',
    ) {
        parent::__construct($message);
    }
}
