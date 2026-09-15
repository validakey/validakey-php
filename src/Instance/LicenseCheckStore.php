<?php

declare(strict_types=1);

namespace Validakey\Instance;

use Validakey\Response\LicenseSnapshot;

/**
 * Persistence for the last license verification.
 *
 * Key the same way as {@see TokenStore}: app id, subject, and fingerprint.
 */
interface LicenseCheckStore
{
    public function get(string $key): ?LicenseSnapshot;

    public function set(string $key, LicenseSnapshot $snapshot): void;

    public function forget(string $key): void;
}
