<?php

declare(strict_types=1);

namespace Validakey\Instance;

use Validakey\Exception\ValidakeyException;

/**
 * Persists instance ids in a JSON file readable only by the owning user.
 *
 * Writes go through a temporary file and rename so a concurrent reader never
 * observes a half-written map, and the file is created with 0600 so the
 * instance secret is not exposed to other users on a shared host.
 */
final class FileInstanceStore implements InstanceStore
{
    public function __construct(
        private readonly string $path,
    ) {
        if ('' === trim($this->path)) {
            throw new \InvalidArgumentException('Instance store path must not be empty.');
        }
    }

    public function get(string $key): ?string
    {
        $items = $this->read();
        $value = $items[$key] ?? null;

        return is_string($value) && '' !== $value ? $value : null;
    }

    public function set(string $key, string $instanceId): void
    {
        $items = $this->read();
        $items[$key] = $instanceId;
        $this->write($items);
    }

    public function forget(string $key): void
    {
        $items = $this->read();
        if (! array_key_exists($key, $items)) {
            return;
        }

        unset($items[$key]);
        $this->write($items);
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        if (! is_file($this->path)) {
            return array();
        }

        $raw = @file_get_contents($this->path);
        if (false === $raw || '' === trim($raw)) {
            return array();
        }

        $decoded = json_decode($raw, true);

        // A corrupt store is recoverable: the next handshake repopulates it.
        return is_array($decoded) ? $decoded : array();
    }

    /**
     * @param array<string, mixed> $items
     */
    private function write(array $items): void
    {
        $directory = \dirname($this->path);
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new ValidakeyException('Cannot create instance store directory: ' . $directory);
        }

        $temp = @tempnam($directory, 'vk-instance-');
        if (false === $temp) {
            throw new ValidakeyException('Cannot create a temporary file in: ' . $directory);
        }

        if (false === @file_put_contents($temp, (string) json_encode($items))) {
            @unlink($temp);

            throw new ValidakeyException('Cannot write instance store: ' . $this->path);
        }

        @chmod($temp, 0600);

        if (! @rename($temp, $this->path)) {
            @unlink($temp);

            throw new ValidakeyException('Cannot replace instance store: ' . $this->path);
        }
    }
}
