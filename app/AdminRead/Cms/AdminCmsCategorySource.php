<?php

declare(strict_types=1);

namespace App\AdminRead\Cms;

use App\AdminRead\Contracts\AdminCmsCategorySourceInterface;
use App\AdminRead\Support\JsonArrayAggregateSql;
use App\AdminRead\Support\JsonProjectionDecoder;
use App\AdminRead\Support\PermissionGuard;
use App\AdminRead\Support\ReadOnlyQuery;
use App\Support\RequestTelemetry;
use CodeIgniter\Cache\CacheInterface;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/**
 * Bounded, permission-aware CMS category projection.
 *
 * The list, editor options, and optional category record are produced by one
 * SQL projection against the CMS read connection. This keeps the Admin from
 * opening separate CMS HTTP requests for collections, parents, languages,
 * and the selected category.
 */
final class AdminCmsCategorySource implements AdminCmsCategorySourceInterface
{
    private const CACHE_TTL = 30;
    private const MAX_LANGUAGES = 100;
    private const MAX_COLLECTIONS = 100;
    private const MAX_CATEGORIES = 1000;

    /** @param BaseConnection<mixed,mixed> $db */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly CacheInterface $cache,
    ) {
    }

    /** @param list<string> $permissions */
    public function bootstrap(?int $categoryId, array $permissions): array
    {
        PermissionGuard::require($permissions, 'cms.categories.read');
        if ($categoryId !== null && $categoryId < 1) {
            throw new RuntimeException('A positive CMS category identifier is required.');
        }

        $scope = array_values(array_unique($permissions));
        sort($scope);
        $cacheKey = 'admin_cms_category_' . ($categoryId ?? 'base') . '_' . hash('sha256', implode("\0", $scope));
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            RequestTelemetry::recordCache('admin.cms.category', 'hit');

            return $cached;
        }
        RequestTelemetry::recordCache('admin.cms.category', 'miss');

        $projection = $this->query($categoryId, $permissions);
        $row = $projection[0] ?? [];
        $sections = [
            'category' => $this->decodeObject($row['category_json'] ?? null),
            'collections' => $this->normalizeList($row['collections_json'] ?? null, ['id']),
            'categories' => $this->normalizeList($row['categories_json'] ?? null, ['id', 'collection_id', 'parent_id']),
            'languages' => $this->normalizeList($row['languages_json'] ?? null, ['id']),
            'quality' => [],
        ];

        foreach ($sections['languages'] as &$language) {
            $language['is_default'] = (bool) ($language['is_default'] ?? false);
            $language['is_active'] = (bool) ($language['is_active'] ?? false);
        }
        unset($language);

        if ($sections['category'] !== []) {
            $sections['category']['is_active'] = (bool) ($sections['category']['is_active'] ?? false);
            $sections['category']['translations'] = $this->normalizeList($sections['category']['translations'] ?? null, ['language_id']);
        }

        $this->cache->save($cacheKey, $sections, self::CACHE_TTL);

        return $sections;
    }

    /** @return list<array<string,mixed>> */
    /**
     * @param list<string> $permissions
     * @return list<array<string,mixed>>
     */
    private function query(?int $categoryId, array $permissions): array
    {
        $json = JsonArrayAggregateSql::forDatabase($this->db);
        $aggregate = $json['aggregate'];
        $object = $json['object'];
        $suffix = $json['suffix'];
        $empty = "'[]'";
        $maxLanguages = self::MAX_LANGUAGES;
        $maxCollections = self::MAX_COLLECTIONS;
        $maxCategories = self::MAX_CATEGORIES;

        $languagesJson = $empty;
        if (in_array('cms.languages.read', $permissions, true)) {
            $languagesJson = "(SELECT {$aggregate}({$object}(
                    'id', l.id,
                    'code', l.code,
                    'name', l.name,
                    'native_name', l.native_name,
                    'is_default', l.is_default,
                    'is_active', l.is_active,
                    'sort_order', l.sort_order
                ){$suffix})
                 FROM (
                    SELECT id, code, name, native_name, is_default, is_active, sort_order
                    FROM cms_languages
                    WHERE is_active = 1
                    ORDER BY sort_order ASC, id ASC
                    LIMIT {$maxLanguages}
                 ) l)";
        }

        $collectionsJson = $empty;
        if (in_array('cms.collections.read', $permissions, true)) {
            $collectionsJson = "(SELECT {$aggregate}({$object}(
                    'id', c.id,
                    'collection_key', c.collection_key,
                    'collection_type', c.collection_type,
                    'is_active', c.is_active,
                    'sort_order', c.sort_order
                ){$suffix})
                 FROM (
                    SELECT id, collection_key, collection_type, is_active, sort_order
                    FROM cms_collections
                    WHERE is_active = 1
                    ORDER BY sort_order ASC, id ASC
                    LIMIT {$maxCollections}
                 ) c)";
        }

        $categoriesJson = "(SELECT {$aggregate}({$object}(
                    'id', c.id,
                    'collection_id', c.collection_id,
                    'parent_id', c.parent_id,
                    'name', COALESCE(t.name, ''),
                    'slug', COALESCE(t.slug, ''),
                    'is_active', c.is_active,
                    'sort_order', c.sort_order
                ){$suffix})
                 FROM (
                    SELECT id, collection_id, parent_id, is_active, sort_order
                    FROM cms_categories
                    WHERE is_active = 1
                    ORDER BY sort_order ASC, id ASC
                    LIMIT {$maxCategories}
                 ) c
                 LEFT JOIN cms_category_translations t
                    ON t.category_id = c.id
                   AND t.language_id = (
                        SELECT id FROM cms_languages
                        WHERE is_default = 1 AND is_active = 1
                        ORDER BY id ASC LIMIT 1
                   ))";

        $categoryJson = 'NULL';
        $bindings = [];
        if ($categoryId !== null) {
            $translations = "COALESCE((SELECT {$aggregate}({$object}(
                    'id', t.id,
                    'language_id', t.language_id,
                    'slug', t.slug,
                    'name', t.name,
                    'description', t.description,
                    'meta_title', t.meta_title,
                    'meta_description', t.meta_description
                ){$suffix}) FROM cms_category_translations t WHERE t.category_id = c.id), {$empty})";
            $categoryJson = "(SELECT {$object}(
                    'id', c.id,
                    'collection_id', c.collection_id,
                    'parent_id', c.parent_id,
                    'sort_order', c.sort_order,
                    'is_active', c.is_active,
                    'created_at', c.created_at,
                    'updated_at', c.updated_at,
                    'translations', {$translations}
                ) FROM cms_categories c WHERE c.id = ? LIMIT 1)";
            $bindings[] = $categoryId;
        }

        $sql = <<<SQL
            SELECT
                {$categoryJson} AS category_json,
                {$languagesJson} AS languages_json,
                {$collectionsJson} AS collections_json,
                {$categoriesJson} AS categories_json
        SQL;

        return ReadOnlyQuery::sql($this->db, $sql, $bindings, 'CMS admin category projection');
    }

    /** @return array<string,mixed> */
    private function decodeObject(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
        }

        return is_array($value) ? $value : [];
    }

    /**
     * @param list<string> $integerFields
     * @return list<array<string,mixed>>
     */
    private function normalizeList(mixed $value, array $integerFields): array
    {
        $rows = JsonProjectionDecoder::decodeList($value);
        foreach ($rows as &$row) {
            foreach ($integerFields as $field) {
                if (array_key_exists($field, $row) && $row[$field] !== null) {
                    $row[$field] = (int) $row[$field];
                }
            }
        }
        unset($row);

        return $rows;
    }
}
