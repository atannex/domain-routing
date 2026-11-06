<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Hosts;

/** @internal bootstrap/cache/domain-routing.php, written by domains:cache. */
final class IndexCache
{
    public function __construct(private readonly string $path) {}

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function storedHash(): ?string
    {
        $data = $this->read();

        return is_array($data) ? (string) ($data['hash'] ?? '') : null;
    }

    public function load(string $hash): ?HostIndex
    {
        $data = $this->read();

        if (! is_array($data) || ($data['hash'] ?? null) !== $hash || ! is_array($data['index'] ?? null)) {
            return null;
        }

        return HostIndex::fromArray($data['index']);
    }

    public function write(HostIndex $index, string $hash): void
    {
        if (! is_dir(dirname($this->path))) {
            mkdir(dirname($this->path), 0755, true);
        }

        $tmp = $this->path.'.'.bin2hex(random_bytes(4)).'.tmp';
        file_put_contents($tmp, '<?php return '.var_export(['hash' => $hash, 'index' => $index->toArray()], true).';'.PHP_EOL);
        rename($tmp, $this->path);

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->path, true);
        }
    }

    public function clear(): bool
    {
        return ! $this->exists() || unlink($this->path);
    }

    private function read(): mixed
    {
        return $this->exists() ? require $this->path : null;
    }
}
