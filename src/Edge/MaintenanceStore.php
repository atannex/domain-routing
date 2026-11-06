<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Edge;

/** @internal storage/framework/domain-routing/down/{site}.json */
final class MaintenanceStore
{
    public function __construct(private readonly string $directory) {}

    /** @param array{secret?: ?string, retry?: ?int, status?: int} $options */
    public function down(string $site, array $options = []): void
    {
        if (! is_dir($this->directory)) {
            mkdir($this->directory, 0755, true);
        }

        file_put_contents($this->path($site), (string) json_encode([
            'secret' => $options['secret'] ?? null,
            'retry' => $options['retry'] ?? null,
            'status' => $options['status'] ?? 503,
            'time' => time(),
        ], JSON_PRETTY_PRINT));
    }

    public function up(string $site): bool
    {
        return is_file($this->path($site)) && unlink($this->path($site));
    }

    /** @return array{secret: ?string, retry: ?int, status: int, time: int}|null */
    public function payload(string $site): ?array
    {
        if (! is_file($this->path($site))) {
            return null;
        }

        $data = json_decode((string) file_get_contents($this->path($site)), true);

        return is_array($data) ? $data + ['secret' => null, 'retry' => null, 'status' => 503, 'time' => 0] : null;
    }

    public static function cookie(string $site): string
    {
        return 'domain_routing_bypass_'.preg_replace('/[^A-Za-z0-9_]/', '_', $site);
    }

    private function path(string $site): string
    {
        return $this->directory.'/'.preg_replace('/[^A-Za-z0-9_-]/', '_', $site).'.json';
    }
}
