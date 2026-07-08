<?php

class ApiResponseCache
{
    private string $baseDir;
    private int $maxStaleSeconds;
    private bool $enabled;
    private static bool $garbageCollected = false;

    public function __construct(?string $baseDir = null, int $maxStaleSeconds = 120)
    {
        $this->maxStaleSeconds = max(30, $maxStaleSeconds);
        $resolvedBaseDir = $this->resolveBaseDir($baseDir);
        $this->baseDir = $resolvedBaseDir ?: rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sport-api-response-cache';
        $this->enabled = $resolvedBaseDir !== null;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function probe(array $parts): array
    {
        if (!$this->enabled) {
            return ['state' => 'disabled'];
        }

        $this->garbageCollect();

        $key = $this->buildKey($parts);
        $entryPath = $this->entryPath($key);
        $lockPath = $this->lockPath($key);
        $entry = $this->readEntry($entryPath);
        $now = time();

        if ($entry && (($entry['expires_at'] ?? 0) >= $now)) {
            return [
                'state' => 'hit',
                'key' => $key,
                'entry' => $entry
            ];
        }

        $lockHandle = @fopen($lockPath, 'c+');
        if ($lockHandle && @flock($lockHandle, LOCK_EX | LOCK_NB)) {
            return [
                'state' => 'miss',
                'key' => $key,
                'entry' => $entry,
                'lock' => $lockHandle
            ];
        }

        if (is_resource($lockHandle)) {
            fclose($lockHandle);
        }

        if ($entry && (($entry['stale_until'] ?? 0) >= $now)) {
            return [
                'state' => 'stale',
                'key' => $key,
                'entry' => $entry
            ];
        }

        return [
            'state' => 'miss',
            'key' => $key,
            'entry' => $entry
        ];
    }

    public function store(array $probe, string $body, int $statusCode, int $ttlSeconds, array $meta = []): bool
    {
        if (!$this->enabled || empty($probe['key']) || $ttlSeconds <= 0) {
            $this->release($probe);
            return false;
        }

        $key = $probe['key'];
        $entryPath = $this->entryPath($key);
        $entryDir = dirname($entryPath);

        if (!$this->ensureDirectory($entryDir)) {
            $this->release($probe);
            return false;
        }

        $now = time();
        $entry = [
            'created_at' => $now,
            'expires_at' => $now + $ttlSeconds,
            'stale_until' => $now + $ttlSeconds + $this->maxStaleSeconds,
            'status_code' => $statusCode,
            'meta' => $meta,
            'body' => $body
        ];

        $tempFile = $entryPath . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $written = @file_put_contents($tempFile, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        if ($written === false) {
            @unlink($tempFile);
            $this->release($probe);
            return false;
        }

        @rename($tempFile, $entryPath);
        $this->release($probe);
        return true;
    }

    public function release(array $probe): void
    {
        if (empty($probe['lock']) || !is_resource($probe['lock'])) {
            return;
        }

        $lockHandle = $probe['lock'];
        @flock($lockHandle, LOCK_UN);
        $lockMeta = stream_get_meta_data($lockHandle);
        fclose($lockHandle);

        if (!empty($lockMeta['uri']) && is_file($lockMeta['uri'])) {
            @unlink($lockMeta['uri']);
        }
    }

    public function clear(): int
    {
        if (!$this->enabled || !is_dir($this->baseDir)) {
            return 0;
        }

        $deleted = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->baseDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if ($item->isFile()) {
                if (@unlink($path)) {
                    $deleted++;
                }
            } elseif ($item->isDir()) {
                @rmdir($path);
            }
        }

        return $deleted;
    }

    private function buildKey(array $parts): string
    {
        $normalized = $this->normalizeValue($parts);
        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function normalizeValue($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        if ($this->isList($value)) {
            return array_map([$this, 'normalizeValue'], $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeValue($item);
        }

        return $value;
    }

    private function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    private function entryPath(string $key): string
    {
        return $this->baseDir
            . DIRECTORY_SEPARATOR
            . substr($key, 0, 2)
            . DIRECTORY_SEPARATOR
            . $key
            . '.json';
    }

    private function lockPath(string $key): string
    {
        return $this->entryPath($key) . '.lock';
    }

    private function readEntry(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        $entry = json_decode($raw, true);
        if (!is_array($entry) || !isset($entry['body'])) {
            return null;
        }

        return $entry;
    }

    private function ensureDirectory(string $path): bool
    {
        if (is_dir($path)) {
            return is_writable($path);
        }

        if (!@mkdir($path, 0775, true) && !is_dir($path)) {
            return false;
        }

        return is_writable($path);
    }

    private function resolveBaseDir(?string $preferredDir): ?string
    {
        $candidates = [];

        if (!empty($preferredDir)) {
            $candidates[] = $preferredDir;
        }

        $candidates[] = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.api-response-cache';
        $candidates[] = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sport-api-response-cache';

        foreach ($candidates as $candidate) {
            if ($this->ensureDirectory($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function garbageCollect(): void
    {
        if (self::$garbageCollected || !$this->enabled) {
            return;
        }

        self::$garbageCollected = true;

        if (mt_rand(1, 100) > 5) {
            return;
        }

        $expiryThreshold = time() - $this->maxStaleSeconds;

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->baseDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $item) {
                $path = $item->getPathname();

                if ($item->isFile()) {
                    if (substr($path, -5) === '.lock') {
                        if ($item->getMTime() < (time() - 60)) {
                            @unlink($path);
                        }
                        continue;
                    }

                    if (substr($path, -5) !== '.json') {
                        continue;
                    }

                    $entry = $this->readEntry($path);
                    $staleUntil = (int)($entry['stale_until'] ?? 0);
                    if ($entry === null || $staleUntil < $expiryThreshold) {
                        @unlink($path);
                    }
                } elseif ($item->isDir()) {
                    @rmdir($path);
                }
            }
        } catch (Throwable $e) {
            // Ignore cache GC errors.
        }
    }
}
