<?php

namespace GastosNaia\Tests\Unit\Infrastructure;

use GastosNaia\Infrastructure\FileCache;
use PHPUnit\Framework\TestCase;

class FileCacheTest extends TestCase
{
    private string $tmpDir;
    private FileCache $cache;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/gastos_naia_filecache_' . uniqid();
        $this->cache = new FileCache($this->tmpDir, ttl: 60);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            array_map('unlink', glob($this->tmpDir . '/*.json'));
            rmdir($this->tmpDir);
        }
    }

    public function test_returns_null_for_missing_key(): void
    {
        $this->assertNull($this->cache->get('nonexistent'));
    }

    public function test_set_and_get_string_value(): void
    {
        $this->cache->set('greeting', 'hola');
        $this->assertSame('hola', $this->cache->get('greeting'));
    }

    public function test_set_and_get_array_value(): void
    {
        $data = [['year' => 2025, 'total' => 1500.0]];
        $this->cache->set('totals', $data);
        $result = $this->cache->get('totals');
        $this->assertEquals(1500.0, $result[0]['total']);
        $this->assertSame(2025, $result[0]['year']);
    }

    public function test_expired_entry_returns_null(): void
    {
        $cache = new FileCache($this->tmpDir, ttl: -1); // TTL en el pasado
        $cache->set('expired', 'value');
        $this->assertNull($cache->get('expired'));
    }

    public function test_invalidate_removes_entry(): void
    {
        $this->cache->set('key_to_delete', 'data');
        $this->cache->invalidate('key_to_delete');
        $this->assertNull($this->cache->get('key_to_delete'));
    }

    public function test_creates_cache_directory_if_not_exists(): void
    {
        $newDir = $this->tmpDir . '/nested/cache';
        new FileCache($newDir, ttl: 60);
        $this->assertDirectoryExists($newDir);
        rmdir($newDir);
        rmdir(dirname($newDir));
    }

    public function test_overwrite_existing_key(): void
    {
        $this->cache->set('key', 'original');
        $this->cache->set('key', 'updated');
        $this->assertSame('updated', $this->cache->get('key'));
    }
}
