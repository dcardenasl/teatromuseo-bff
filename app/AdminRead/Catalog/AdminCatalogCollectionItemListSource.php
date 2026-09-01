<?php

declare(strict_types=1);

namespace App\AdminRead\Catalog;

use App\AdminRead\Contracts\AdminCatalogCollectionItemListSourceInterface;
use App\AdminRead\Support\PermissionGuard;
use App\AdminRead\Support\ReadOnlyQuery;
use App\Support\RequestTelemetry;
use CodeIgniter\Cache\CacheInterface;
use CodeIgniter\Database\BaseConnection;

/** One bounded Catalog read for the collection-item list filters. */
final class AdminCatalogCollectionItemListSource implements AdminCatalogCollectionItemListSourceInterface
{
    private const CACHE_TTL = 30;
    private const MAX_CATEGORIES = 500;

    /** @param BaseConnection<mixed,mixed> $catalogDb */
    public function __construct(
        private readonly BaseConnection $catalogDb,
        private readonly CacheInterface $cache,
    ) {
    }

    /** @param list<string> $permissions */
    public function bootstrap(array $permissions): array
    {
        PermissionGuard::require($permissions, 'catalog.collectionItem.read');
        $scope = array_values(array_unique($permissions));
        sort($scope);
        $cacheKey = 'admin_catalog_collection_item_list_' . hash('sha256', implode("\0", $scope));
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            RequestTelemetry::recordCache('admin.catalog.collection-item-list', 'hit');

            return $cached;
        }
        RequestTelemetry::recordCache('admin.catalog.collection-item-list', 'miss');

        $categories = [];
        if (in_array('catalog.category.read', $permissions, true)) {
            $rows = ReadOnlyQuery::rows(
                $this->catalogDb->table('categories')
                    ->select('id, name, slug, sort_order')
                    ->where('deleted_at', null)
                    ->orderBy('sort_order', 'ASC')
                    ->orderBy('name', 'ASC')
                    ->limit(self::MAX_CATEGORIES),
                'Catalog collection item list categories',
            );
            foreach ($rows as &$row) {
                $row['id'] = (int) ($row['id'] ?? 0);
            }
            unset($row);
            $categories = $rows;
        }

        $sections = ['categories' => $categories, 'quality' => []];
        $this->cache->save($cacheKey, $sections, self::CACHE_TTL);

        return $sections;
    }
}
