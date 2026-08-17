<?php

declare(strict_types=1);

namespace App\AdminRead\Files;

use App\AdminRead\Contracts\AdminFileUsageSourceInterface;
use App\AdminRead\Support\ReadOnlyQuery;
use App\Libraries\Hub\HubClient;
use CodeIgniter\Database\BaseConnection;
use dcardenasl\Ci4ApiCore\Exceptions\AuthorizationException;

/** Hub + CMS file-usage reader with stable, context-aware deduplication. */
final class AdminFileUsageSource implements AdminFileUsageSourceInterface
{
    public function __construct(
        private readonly HubClient $hubClient,
        private readonly BaseConnection $cmsDb,
    ) {
    }

    /**
     * @param list<string> $permissions
     * @return list<array<string, mixed>>
     */
    public function readHub(int $fileId, string $bearerToken, array $permissions): array
    {
        $this->requirePermission($permissions, 'files.read');

        $payload = $this->hubClient->get('/api/v1/files/' . $fileId . '/usages', $bearerToken);
        $rows    = is_array($payload['usages'] ?? null) ? $payload['usages'] : $payload;

        return $this->normalizeRows($rows, 'hub');
    }

    /**
     * @param list<string> $permissions
     * @return list<array<string, mixed>>
     */
    public function readCms(int $fileId, array $permissions): array
    {
        $this->requirePermission($permissions, 'cms.entries.read');

        if (! $this->cmsDb->tableExists('cms_file_references')) {
            throw new \RuntimeException('CMS file usage registry is unavailable.');
        }

        $rows = ReadOnlyQuery::rows(
            $this->cmsDb->table('cms_file_references fr')
                ->select('fr.resource_type, fr.resource_id, fr.role, fr.label, bi.owner_type, bi.owner_id, bt.block_key, bt.name as block_name')
                ->join('cms_block_instances bi', 'bi.id = fr.block_instance_id', 'left')
                ->join('cms_content_blocks bt', 'bt.id = bi.block_id', 'left')
                ->where('fr.hub_file_id', $fileId)
                ->orderBy('fr.resource_type', 'ASC')
                ->orderBy('fr.resource_id', 'ASC')
                ->orderBy('fr.role', 'ASC'),
            'CMS file usages',
        );

        return array_values(array_map(function (array $row) use ($fileId): array {
            $resourceType = (string) ($row['resource_type'] ?? '');
            $usage = [
                'source'      => 'domain',
                'resource'    => match ($resourceType) {
                    'entry' => 'entries',
                    'page' => 'pages',
                    'setting' => 'settings',
                    'block_instance' => 'block_instances',
                    default => $resourceType,
                },
                'resource_id' => (int) ($row['resource_id'] ?? 0),
                'role'        => (string) ($row['role'] ?? 'default'),
                'label'       => isset($row['label']) && trim((string) $row['label']) !== ''
                    ? (string) $row['label']
                    : null,
            ];

            if ($resourceType === 'block_instance') {
                $usage['context'] = [
                    'owner_type' => (string) ($row['owner_type'] ?? ''),
                    'owner_id'   => (int) ($row['owner_id'] ?? 0),
                    'file_id'    => $fileId,
                    'block_key'  => (string) ($row['block_key'] ?? ''),
                    'block_name' => (string) ($row['block_name'] ?? ''),
                ];
            }

            return $usage;
        }, $rows));
    }

    /**
     * Keep the first row for every stable usage identity. A CMS row with a
     * context replaces a Hub row without one, while preserving first-seen
     * order for stable UI output.
     *
     * @param list<array<string, mixed>> $hubUsages
     * @param list<array<string, mixed>> $cmsUsages
     * @return list<array<string, mixed>>
     */
    public function merge(array $hubUsages, array $cmsUsages): array
    {
        $merged = [];
        foreach (array_merge($hubUsages, $cmsUsages) as $usage) {
            $key = $this->usageKey($usage);
            if (! isset($merged[$key]) || $this->hasContext($usage)) {
                if (! isset($merged[$key]) || ! $this->hasContext($merged[$key])) {
                    $merged[$key] = $usage;
                }
            }
        }

        return array_values($merged);
    }

    /** @param list<string> $permissions */
    private function requirePermission(array $permissions, string $permission): void
    {
        if (! in_array($permission, $permissions, true)) {
            throw new AuthorizationException('The ' . $permission . ' permission is required.');
        }
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
