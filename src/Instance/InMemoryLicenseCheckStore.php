<?php

declare(strict_types=1);

namespace Validakey\Instance;

use Validakey\Response\LicenseSnapshot;

/**
 * Holds the last license check for the life of the process only.
 */
final class InMemoryLicenseCheckStore implements LicenseCheckStore
{
    /** @var array<string, LicenseSnapshot> */
    private array $items = array();

    public function get(string $key): ?LicenseSnapshot
    {
        return $this->items[$key] ?? null;
    }

    public function set(string $key, LicenseSnapshot $snapshot): void
    {
        $this->items[$key] = $snapshot;
    }

    public function forget(string $key): void
    {
        unset($this->items[$key]);
    }
}
