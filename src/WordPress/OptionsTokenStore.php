<?php

declare(strict_types=1);

namespace Validakey\WordPress;

use Validakey\Instance\TokenStore;

/**
 * Persists granted vKeys in the WordPress options table.
 *
 * Prefer this over a file under wp-content: the vKey is a bearer secret and
 * that path may be web-servable. Autoload is left off so the option is loaded
 * only when a license check needs it.
 *
 * Requires WordPress (`get_option` / `update_option`). The core client has no
 * WordPress dependency; only load this class from a plugin or theme.
 */
final class OptionsTokenStore implements TokenStore
{
    public function __construct(
        private readonly string $optionName,
    ) {
        if ('' === trim($this->optionName)) {
            throw new \InvalidArgumentException('Option name must not be empty.');
        }
    }

    public function optionName(): string
    {
        return $this->optionName;
    }

    public function get(string $key): ?string
    {
        $items = $this->all();
        $value = $items[$key] ?? null;

        return is_string($value) && '' !== $value ? $value : null;
    }

    public function set(string $key, string $token): void
    {
        $items = $this->all();
        $items[$key] = $token;
        $this->write($items);
    }

    public function forget(string $key): void
    {
        $items = $this->all();
        if (! array_key_exists($key, $items)) {
            return;
        }

        unset($items[$key]);
        $this->write($items);
    }

    /**
     * @return array<string, string>
     */
    private function all(): array
    {
        $items = \get_option($this->optionName, array());

        return is_array($items) ? $items : array();
    }

    /**
     * @param array<string, string> $items
     */
    private function write(array $items): void
    {
        \update_option($this->optionName, $items, false);
    }
}
