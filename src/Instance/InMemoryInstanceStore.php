<?php

declare(strict_types=1);

namespace Validakey\Instance;

/**
 * Holds the instance id for the life of the process only.
 *
 * This is the default so that the library never writes to disk without being
 * asked to. It means one handshake per process; long-lived applications should
 * supply a FileInstanceStore or their own persistent implementation.
 */
final class InMemoryInstanceStore implements InstanceStore
{
    /** @var array<string, string> */
    private array $items = array();

    public function get(string $key): ?string
    {
        return $this->items[$key] ?? null;
    }

    public function set(string $key, string $instanceId): void
    {
        $this->items[$key] = $instanceId;
    }

    public function forget(string $key): void
    {
        unset($this->items[$key]);
    }
}
