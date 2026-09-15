<?php

declare(strict_types=1);

namespace Validakey\Instance;

/**
 * Persistence for a granted vKey.
 *
 * The instance id authenticates token-path calls; the vKey is the license
 * itself. Without somewhere to keep it, the application cannot ask
 * "is this subject's license still good?" on the next request.
 *
 * Key the same way as {@see InstanceStore}: app id, subject, and fingerprint.
 * The vKey is a bearer secret; store it somewhere only the application can read.
 */
interface TokenStore
{
    public function get(string $key): ?string;

    public function set(string $key, string $token): void;

    public function forget(string $key): void;
}
