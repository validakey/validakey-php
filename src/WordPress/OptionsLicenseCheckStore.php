<?php

declare(strict_types=1);

namespace Validakey\WordPress;

use Validakey\Instance\LicenseCheckStore;
use Validakey\Response\LicenseSnapshot;

/**
 * Persists license verification snapshots in the WordPress options table.
 *
 * Autoload is left off so the option is loaded only when a gate or revalidate
 * needs it.
 */
final class OptionsLicenseCheckStore implements LicenseCheckStore
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

    public function get(string $key): ?LicenseSnapshot
    {
        $items = $this->all();
        $value = $items[$key] ?? null;
        if (! is_array($value)) {
            return null;
        }

        return LicenseSnapshot::fromArray($value);
    }

    public function set(string $key, LicenseSnapshot $snapshot): void
    {
        $items = $this->all();
        $items[$key] = $snapshot->toArray();
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
     * @return array<string, array<string, mixed>>
     */
    private function all(): array
    {
        $items = \get_option($this->optionName, array());

        return is_array($items) ? $items : array();
    }

    /**
     * @param array<string, array<string, mixed>> $items
     */
    private function write(array $items): void
    {
        \update_option($this->optionName, $items, false);
    }
}
