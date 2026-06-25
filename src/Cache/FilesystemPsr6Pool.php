<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Cache;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

final class FilesystemPsr6Pool implements CacheItemPoolInterface
{
    public function __construct(private readonly string $directory)
    {
    }

    public function getItem(string $key): CacheItemInterface
    {
        $file = $this->path($key);
        if (!is_file($file)) {
            return new CacheItem($key);
        }

        try {
            $raw = file_get_contents($file);
            $data = false !== $raw ? unserialize($raw, ['allowed_classes' => false]) : false;
            if (!\is_array($data) || !\array_key_exists('value', $data)) {
                return new CacheItem($key);
            }
            $expiresAt = $data['expiresAt'] ?? null;
            if (null !== $expiresAt && time() > $expiresAt) {
                return new CacheItem($key);
            }

            return new CacheItem($key, $data['value'], true);
        } catch (\Throwable) {
            return new CacheItem($key);
        }
    }

    public function save(CacheItemInterface $item): bool
    {
        if (!$item instanceof CacheItem) {
            return false;
        }

        try {
            if (!is_dir($this->directory) && !@mkdir($this->directory, 0o700, true) && !is_dir($this->directory)) {
                return false;
            }
            $payload = serialize(['value' => $item->get(), 'expiresAt' => $item->expiresAt]);
            $tmp = $this->path($item->getKey()).'.'.bin2hex(random_bytes(6)).'.tmp';
            if (false === @file_put_contents($tmp, $payload, \LOCK_EX)) {
                return false;
            }

            return @rename($tmp, $this->path($item->getKey()));
        } catch (\Throwable) {
            return false;
        }
    }

    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    public function deleteItem(string $key): bool
    {
        $file = $this->path($key);

        return !is_file($file) || @unlink($file);
    }

    /**
     * @param string[] $keys
     *
     * @return array<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->getItem($key);
        }

        return $out;
    }

    public function clear(): bool
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        return true;
    }

    public function deleteItems(array $keys): bool
    {
        $ok = true;
        foreach ($keys as $key) {
            $ok = $this->deleteItem($key) && $ok;
        }

        return $ok;
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->save($item);
    }

    public function commit(): bool
    {
        return true;
    }

    private function path(string $key): string
    {
        return $this->directory.'/'.hash('xxh128', $key).'.cache';
    }
}
