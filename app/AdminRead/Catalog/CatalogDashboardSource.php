<?php

declare(strict_types=1);

namespace App\AdminRead\Catalog;

use App\AdminRead\Contracts\AdminDashboardSourceInterface;
use App\AdminRead\Support\ReadOnlyQuery;
use CodeIgniter\Database\BaseConnection;

/** Permission-aware Catalog dashboard projection over the Catalog database. */
final class CatalogDashboardSource implements AdminDashboardSourceInterface
{
    /**
     * A method (not a `const`) so the `bool` fields keep their declared type
     * instead of PHPStan narrowing them to the literal `true` every current
     * entry happens to share — `soft_delete`/`slug` are genuinely per-resource
     * flags for future entries, not always-true dead branches.
     *
     * @return array<string, array{table: string, permission: string, soft_delete: bool, slug: bool}>
     */
    private static function resources(): array
    {
        return [
            'collection_items' => [
                'table' => 'collection_items',
                'permission' => 'catalog.collectionItem.read',
                'soft_delete' => true,
                'slug' => false,
            ],
            'categories' => [
                'table' => 'categories',
                'permission' => 'catalog.category.read',
                'soft_delete' => true,
                'slug' => true,
            ],
            'techniques' => [
                'table' => 'techniques',
                'permission' => 'catalog.technique.read',
                'soft_delete' => true,
                'slug' => true,
            ],
        ];
    }

    /** @param BaseConnection<mixed,mixed> $db */
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @param list<string> $permissions
     * @return array{sections: array<string, mixed>, diagnostics?: array<string, mixed>}
     */
    public function read(array $permissions): array
    {
        $branches = [];
        foreach (self::resources() as $type => $resource) {
            if (! in_array($resource['permission'], $permissions, true)) {
                continue;
            }

            $where = $resource['soft_delete'] ? ' WHERE deleted_at IS NULL' : '';
            $branches[] = sprintf(
                "SELECT 'count' AS row_type, '%s' AS resource, NULL AS item_id,
                        NULL AS item_title, NULL AS item_slug, NULL AS updated_at,
                        COUNT(*) AS total
                 FROM %s%s",
                $type,
                $resource['table'],
                $where,
            );

            $slug = $resource['slug'] ? 'slug' : 'NULL';
            $branches[] = sprintf(
                "SELECT 'activity' AS row_type, '%s' AS resource, id AS item_id,
                        name AS item_title, slug AS item_slug, updated_at, NULL AS total
                 FROM (
                     SELECT id, name, %s AS slug, updated_at
                     FROM %s%s
                     ORDER BY updated_at DESC
                     LIMIT 5
                 ) recent_%s",
                $type,
                $slug,
                $resource['table'],
                $where,
                $type,
            );
        }

        if ($branches === []) {
            return ['sections' => ['counts' => []]];
        }

        $query = ReadOnlyQuery::timedSql(
            $this->db,
            'SELECT row_type, resource, item_id, item_title, item_slug, updated_at, total
             FROM (' . implode("\nUNION ALL\n", $branches) . ') dashboard_rows
             ORDER BY CASE WHEN row_type = \'activity\' THEN updated_at ELSE NULL END DESC',
            [],
            'Catalog dashboard projection',
        );
        $rows = $query['rows'];

        $counts = [];
        $activity = [];
        foreach ($rows as $row) {
            if (($row['row_type'] ?? '') === 'count') {
                $counts[(string) ($row['resource'] ?? '')] = (int) ($row['total'] ?? 0);
                continue;
            }

            $activity[] = [
                'type' => (string) ($row['resource'] ?? ''),
                'id' => (int) ($row['item_id'] ?? 0),
                'title' => trim((string) ($row['item_title'] ?? '')),
                'slug' => trim((string) ($row['item_slug'] ?? '')),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }

        return [
            'sections' => [
                'counts' => $counts,
                'recent_activity' => array_slice($activity, 0, 6),
            ],
            'diagnostics' => [
                'checks' => [
                    'database' => [
                        'status' => 'healthy',
                        'response_time_ms' => $query['duration_ms'],
                    ],
                ],
            ],
        ];
    }
}
