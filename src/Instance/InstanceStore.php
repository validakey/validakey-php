<?php

declare(strict_types=1);

namespace Validakey\Instance;

/**
 * Persistence for the instance id granted by the handshake.
 *
 * The instance id is the key for every subsequent token request, so it has to
 * outlive the PHP process or each request pays for a fresh handshake. It is a
 * bearer secret for this app on this machine: store it somewhere only the
 * application can read.
 */
interface InstanceStore
{
    public function get(string $key): ?string;

    public function set(string $key, string $instanceId): void;

    public function forget(string $key): void;
}
