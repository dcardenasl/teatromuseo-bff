<?php

declare(strict_types=1);

namespace App\AdminRead\Files;

use App\AdminRead\Contracts\AdminFileUsageSourceInterface;
use App\AdminRead\Support\PermissionGuard;
use App\Libraries\Hub\HubClient;
use RuntimeException;

/** Hub-authoritative file-usage snapshot reader for the Admin. */
final class AdminFileUsageSource implements AdminFileUsageSourceInterface
{
    public function __construct(
        private readonly HubClient $hubClient,
    ) {
    }

    /**
     * @param list<string> $permissions
     * @return array{complete: bool, source: array<string, string>, usages: list<array<string, mixed>>}
     */
    public function readSnapshot(int $fileId, string $bearerToken, array $permissions): array
    {
        PermissionGuard::require($permissions, 'files.read');

        $payload = $this->hubClient->get('/api/v1/files/' . $fileId . '/usage-snapshot', $bearerToken);
        $snapshot = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        if (! is_array($snapshot['usages'] ?? null)) {
            throw new RuntimeException('Hub returned an invalid file usage snapshot.');
        }

        $merged = [];
        foreach ($this->normalizeRows($snapshot['usages'], 'hub') as $usage) {
            $key = $this->usageKey($usage);
            if (! isset($merged[$key]) || (! $this->hasContext($merged[$key]) && $this->hasContext($usage))) {
                $merged[$key] = $usage;
            }
        }

        $source = [];
        foreach (($snapshot['source'] ?? []) as $key => $state) {
            if (is_string($key) && is_string($state)) {
                $source[$key] = $state;
            }
        }

        return [
            'complete' => ($snapshot['complete'] ?? false) === true,
            'source' => $source,
            'usages' => array_values($merged),
        ];
    }

    /**
     * @param mixed $rows
     * @return list<array<string, mixed>>
     */
    private function normalizeRows(mixed $rows, string $fallbackSource): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $normalized = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $usage = [
                'source'      => (string) ($row['source'] ?? $fallbackSource),
                'resource'    => (string) ($row['resource'] ?? ''),
                'resource_id' => (int) ($row['resource_id'] ?? 0),
                'role'        => (string) ($row['role'] ?? 'default'),
                'label'       => isset($row['label']) ? (string) $row['label'] : null,
            ];
            if (is_array($row['context'] ?? null)) {
                $usage['context'] = $row['context'];
            }
            $normalized[] = $usage;
        }

        return $normalized;
    }

    /** @param array<string, mixed> $usage */
    private function usageKey(array $usage): string
    {
        return implode("\0", [
            (string) ($usage['source'] ?? ''),
            (string) ($usage['resource'] ?? ''),
            (string) ($usage['resource_id'] ?? ''),
            (string) ($usage['role'] ?? ''),
        ]);
    }

    /** @param array<string, mixed> $usage */
    private function hasContext(array $usage): bool
    {
        return is_array($usage['context'] ?? null) && $usage['context'] !== [];
    }
}
