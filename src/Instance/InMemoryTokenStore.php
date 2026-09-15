<?php

declare(strict_types=1);

namespace Validakey\Instance;

/**
 * Holds a vKey for the life of the process only.
 *
 * Fine for tests and one-shot scripts. Long-lived applications should supply
 * a persistent implementation such as {@see \Validakey\WordPress\OptionsTokenStore}.
 */
final class InMemoryTokenStore implements TokenStore
{
    /** @var array<string, string> */
    private array $items = array();

    public function get(string $key): ?string
    {
        return $this->items[$key] ?? null;
    }

    public function set(string $key, string $token): void
    {
        $this->items[$key] = $token;
    }

    public function forget(string $key): void
    {
        unset($this->items[$key]);
    }
}
