<?php

declare(strict_types=1);

namespace App\AdminRead\Catalog;

use App\AdminRead\Contracts\AdminDashboardSourceInterface;
use App\AdminRead\Support\ReadOnlyQuery;
use CodeIgniter\Database\BaseConnection;

/** Permission-aware Catalog dashboard projection over the Catalog database. */
final class CatalogDashboardSource implements AdminDashboardSourceInterface
{
    /** @var array<string, array{table: string, permission: string, projection: string, soft_delete: bool}> */
    private const RESOURCES = [
        'collection_items' => [
            'table' => 'collection_items',
            'permission' => 'catalog.collectionItem.read',
            'projection' => 'id, name, updated_at',
            'soft_delete' => true,
        ],
        'categories' => [
            'table' => 'categories',
            'permission' => 'catalog.category.read',
            'projection' => 'id, name, slug, updated_at',
            'soft_delete' => true,
        ],
        'techniques' => [
            'table' => 'techniques',
            'permission' => 'catalog.technique.read',
            'projection' => 'id, name, slug, updated_at',
            'soft_delete' => true,
        ],
    ];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @param list<string> $permissions
     * @return array{sections: array<string, mixed>}
     */
    public function read(array $permissions): array
    {
        $knownPermissions = array_column(self::RESOURCES, 'permission');
        if (array_intersect($knownPermissions, $permissions) === []) {
            return ['sections' => ['counts' => []]];
        }

        $counts = [];
        $activity = [];
        foreach (self::RESOURCES as $type => $resource) {
            if (! in_array($resource['permission'], $permissions, true)) {
                continue;
            }

            $builder = $this->db->table($resource['table']);
            if ($resource['soft_delete']) {
                $builder->where('deleted_at', null);
            }
            $counts[$type] = ReadOnlyQuery::count($builder, 'Catalog ' . $type);
            $activity = array_merge($activity, $this->recent($resource, $type));
        }

        usort(
            $activity,
            static fn (array $left, array $right): int => strcmp(
                (string) ($right['updated_at'] ?? ''),
                (string) ($left['updated_at'] ?? '')
            )
        );

        return ['sections' => [
            'counts' => $counts,
            'recent_activity' => array_slice($activity, 0, 6),
        ]];
    }

    /** @param array{table: string, permission: string, projection: string, soft_delete: bool} $resource */
    private function recent(array $resource, string $type): array
    {
        $builder = $this->db->table($resource['table'])
            ->select($resource['projection'])
            ->orderBy('updated_at', 'DESC')
            ->limit(5);
        if ($resource['soft_delete']) {
            $builder->where('deleted_at', null);
        }

        $rows = ReadOnlyQuery::rows($builder, 'Catalog ' . $type . ' activity');
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'type' => $type,
                'id' => (int) ($row['id'] ?? 0),
                'title' => trim((string) ($row['name'] ?? '')),
                'slug' => trim((string) ($row['slug'] ?? '')),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }

        return $items;
    }
}
