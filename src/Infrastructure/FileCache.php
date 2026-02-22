<?php

namespace GastosNaia\Infrastructure;

/**
 * Caché de archivos con TTL.
 * Guarda datos en /cache/ como JSON con timestamp de expiración.
 */
class FileCache
{
    private string $cacheDir;
    private int $ttl; // segundos

    public function __construct(string $cacheDir, int $ttl = 300)
    {
        $this->cacheDir = rtrim($cacheDir, '/');
        $this->ttl = $ttl;

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    public function get(string $key): mixed
    {
        $path = $this->getPath($key);

        if (!file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (!isset($data['expires_at'], $data['value'])) {
            return null;
        }

        if (time() > $data['expires_at']) {
            unlink($path);
            return null;
        }

        return $data['value'];
    }

    public function set(string $key, mixed $value): void
    {
        $path = $this->getPath($key);
        $data = [
            'expires_at' => time() + $this->ttl,
            'value' => $value,
        ];
        file_put_contents($path, json_encode($data), LOCK_EX);
    }

    public function invalidate(string $key): void
    {
        $path = $this->getPath($key);
        if (file_exists($path)) {
            unlink($path);
        }
    }

    public function invalidatePrefix(string $prefix): void
    {
        $pattern = $this->cacheDir . '/' . preg_quote($prefix, '/') . '*.json';
        foreach (glob($pattern) as $file) {
            unlink($file);
        }
    }

    private function getPath(string $key): string
    {
        return $this->cacheDir . '/' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key) . '.json';
    }
}
