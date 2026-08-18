<?php

declare(strict_types=1);

namespace App\AdminRead\Cms;

use App\AdminRead\Contracts\AdminCmsBootstrapSourceInterface;
use App\AdminRead\Support\JsonArrayAggregateSql;
use App\AdminRead\Support\ReadOnlyQuery;
use App\Libraries\Domain\DomainClient;
use App\Support\RequestTelemetry;
use CodeIgniter\Cache\CacheInterface;
use CodeIgniter\Database\BaseConnection;
use dcardenasl\Ci4ApiCore\Exceptions\AuthorizationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Composes the bounded CMS reads required by the Admin form screens.
 *
 * The CMS domain remains the source of truth. This reader only coordinates
 * authenticated, read-only domain calls and caches the resulting projection
 * for a short period under the effective permission scope.
 */
final class AdminCmsBootstrapSource implements AdminCmsBootstrapSourceInterface
{
    private const CACHE_TTL = 30;

    public function __construct(
        private readonly DomainClient $client,
        private readonly CacheInterface $cache,
        private readonly ?BaseConnection $readDb = null,
    ) {
    }

    /** @param list<string> $permissions */
    public function entryFormOptions(?int $entryId, array $permissions, string $bearerToken): array
    {
        $this->requirePermissions($permissions, [
            'cms.entries.read',
            'cms.languages.read',
            'cms.collections.read',
        ]);

        $cacheKey = $this->cacheKey(
            'entry-form-options',
            $permissions,
            ...($entryId === null ? [] : [$entryId]),
        );
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            RequestTelemetry::recordCache('admin.cms.bootstrap.entry-form-options', 'hit');

            return $cached;
        }
        RequestTelemetry::recordCache('admin.cms.bootstrap.entry-form-options', 'miss');

        $sections = [
            'languages'   => $this->items('/cms/languages?limit=100&is_active=1', $bearerToken),
            'collections' => $this->items('/cms/collections?limit=100&is_active=1', $bearerToken),
        ];

        if (in_array('cms.categories.read', $permissions, true)) {
            $sections['categories'] = $this->items('/cms/categories?per_page=1000&projection=list', $bearerToken);
        }
        if (in_array('cms.tags.read', $permissions, true)) {
            $sections['tags'] = $this->items('/cms/tags?per_page=1000&projection=list', $bearerToken);
        }

        $this->cache->save($cacheKey, $sections, self::CACHE_TTL);

        return $sections;
    }

    /** @param list<string> $permissions */
    public function pageFormOptions(?int $pageId, array $permissions, string $bearerToken): array
    {
        $this->requirePermissions($permissions, [
            'cms.pages.read',
            'cms.languages.read',
            'cms.collections.read',
        ]);

        $cacheKey = $this->cacheKey(
            'page-form-options',
            $permissions,
            ...($pageId === null ? [] : [$pageId]),
        );
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            RequestTelemetry::recordCache('admin.cms.bootstrap.page-form-options', 'hit');

            return $cached;
        }
        RequestTelemetry::recordCache('admin.cms.bootstrap.page-form-options', 'miss');

        $sections = $this->readDb !== null
            ? $this->directPageFormOptions()
            : [
                'languages'   => $this->items('/cms/languages?limit=100&is_active=1', $bearerToken),
                'pages'       => $this->items('/cms/pages?limit=250', $bearerToken),
                'collections' => $this->items('/cms/collections?limit=200&is_active=1&projection=list', $bearerToken),
            ];

        $this->cache->save($cacheKey, $sections, self::CACHE_TTL);

        return $sections;
    }

    /** @param list<string> $permissions */
    public function menuEditorBootstrap(int $menuId, ?int $itemId, array $permissions, string $bearerToken): array
    {
        if ($menuId < 1 || ($itemId !== null && $itemId < 1)) {
            throw new InvalidArgumentException('CMS menu identifiers must be positive integers.');
        }

        $this->requirePermissions($permissions, [
            'cms.menus.read',
            'cms.languages.read',
            'cms.pages.read',
            'cms.entries.read',
            'cms.collections.read',
        ]);

        $cacheKey = $this->cacheKey(
            'menu-editor',
            $permissions,
            $menuId,
            ...($itemId === null ? [] : [$itemId]),
        );
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            RequestTelemetry::recordCache('admin.cms.bootstrap.menu-editor', 'hit');

            return $cached;
        }
        RequestTelemetry::recordCache('admin.cms.bootstrap.menu-editor', 'miss');

        $sections = [
            'menu'        => $this->data('/cms/menus/' . $menuId, $bearerToken),
            'items'       => $this->items('/cms/menu-items?menu_id=' . $menuId . '&limit=1000', $bearerToken),
            'languages'   => $this->items('/cms/languages?limit=100&is_active=1', $bearerToken),
            'pages'       => $this->items('/cms/pages?limit=250', $bearerToken),
            'entries'     => $this->items('/cms/entries?limit=250', $bearerToken),
            'collections' => $this->items('/cms/collections?limit=100&is_active=1', $bearerToken),
        ];

        if ($itemId !== null) {
            $sections['item'] = $this->data('/cms/menu-items/' . $itemId, $bearerToken);
        }

        $this->cache->save($cacheKey, $sections, self::CACHE_TTL);

        return $sections;
    }

    /** @param list<string> $permissions */
    public function siteIdentityBootstrap(array $permissions, string $bearerToken): array
    {
        $this->requirePermissions($permissions, [
            'cms.settings.read',
            'cms.languages.read',
        ]);

        $cacheKey = $this->cacheKey('site-identity', $permissions);
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            RequestTelemetry::recordCache('admin.cms.bootstrap.site-identity', 'hit');

            return $cached;
        }
        RequestTelemetry::recordCache('admin.cms.bootstrap.site-identity', 'miss');

        $sections = [
            'settings'  => array_merge(
                $this->items('/cms/settings?' . http_build_query([
                    'filter' => ['setting_group' => 'identity'],
                    'per_page' => 100,
                ]), $bearerToken),
                $this->items('/cms/settings?' . http_build_query([
                    'filter' => ['setting_group' => 'social'],
                    'per_page' => 100,
                ]), $bearerToken),
            ),
            'languages' => $this->items('/cms/languages?limit=100&is_active=1', $bearerToken),
        ];

        $this->cache->save($cacheKey, $sections, self::CACHE_TTL);

        return $sections;
    }

    /** @param list<string> $permissions */
    private function requirePermissions(array $permissions, array $required): void
    {
        foreach ($required as $permission) {
            if (! in_array($permission, $permissions, true)) {
                throw new AuthorizationException('The ' . $permission . ' permission is required.');
            }
        }
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function directPageFormOptions(): array
    {
        $db = $this->readDb ?? throw new RuntimeException('CMS read connection is not configured.');
        $jsonSql = JsonArrayAggregateSql::forDatabase($db);
        $aggregate = $jsonSql['aggregate'];
        $object = $jsonSql['object'];
        $aggregateSuffix = $jsonSql['suffix'];
        $emptyArray = "'[]'";

        $pageTranslations = <<<SQL
            SELECT t.page_id,
                   {$aggregate}({$object}(
                       'language_id', t.language_id,
                       'slug', t.slug,
                       'title', t.title
                   ){$aggregateSuffix}) AS translations_json
            FROM (
                SELECT t.id AS translation_id, t.page_id, t.language_id, t.slug, t.title
                FROM cms_page_translations t
                INNER JOIN (
                    SELECT id
                    FROM cms_pages
                    WHERE deleted_at IS NULL
                    ORDER BY sort_order ASC, id ASC
                    LIMIT 250
                ) selected_pages ON selected_pages.id = t.page_id
                ORDER BY page_id ASC, language_id ASC, translation_id ASC
            ) t
            GROUP BY t.page_id
        SQL;

        $collectionTranslations = <<<SQL
            SELECT t.collection_id,
                   {$aggregate}({$object}(
                       'language_id', t.language_id,
                       'slug', t.slug,
                       'name', t.name
                   ){$aggregateSuffix}) AS translations_json
            FROM (
                SELECT t.id AS translation_id, t.collection_id, t.language_id, t.slug, t.name
                FROM cms_collection_translations t
                INNER JOIN (
                    SELECT id
                    FROM cms_collections
                    WHERE is_active = 1
                    ORDER BY sort_order ASC, id ASC
                    LIMIT 200
                ) selected_collections ON selected_collections.id = t.collection_id
                ORDER BY collection_id ASC, language_id ASC, translation_id ASC
            ) t
            GROUP BY t.collection_id
        SQL;

        $sql = <<<SQL
            SELECT
                COALESCE(language_projection.languages_json, {$emptyArray}) AS languages_json,
                COALESCE(page_projection.pages_json, {$emptyArray}) AS pages_json,
                COALESCE(collection_projection.collections_json, {$emptyArray}) AS collections_json
            FROM (SELECT 1 AS anchor) anchor
            LEFT JOIN (
                SELECT {$aggregate}({$object}(
                           'id', l.id,
                           'code', l.code,
                           'name', l.name,
                           'native_name', l.native_name,
                           'is_default', l.is_default,
                           'is_active', l.is_active,
                           'fallback_language_id', l.fallback_language_id,
                           'sort_order', l.sort_order
                       ){$aggregateSuffix}) AS languages_json
                FROM (
                    SELECT id, code, name, native_name, is_default, is_active,
                           fallback_language_id, sort_order
                    FROM cms_languages
                    WHERE is_active = 1
                    ORDER BY sort_order ASC, id ASC
                ) l
            ) language_projection ON 1 = 1
            LEFT JOIN (
                SELECT {$aggregate}({$object}(
                           'id', p.id,
                           'parent_id', p.parent_id,
                           'collection_id', p.collection_id,
                           'page_type', p.page_type,
                           'status', p.status,
                           'sort_order', p.sort_order,
                           'created_at', p.created_at,
                           'updated_at', p.updated_at,
                           'translations', COALESCE(pt.translations_json, {$emptyArray})
                       ){$aggregateSuffix}) AS pages_json
                FROM (
                    SELECT p.id, p.parent_id, p.collection_id, p.page_type, p.status,
                           p.sort_order, p.created_at, p.updated_at
                    FROM cms_pages p
                    WHERE p.deleted_at IS NULL
                    ORDER BY p.sort_order ASC, p.id ASC
                    LIMIT 250
                ) p
                LEFT JOIN ({$pageTranslations}) pt ON pt.page_id = p.id
            ) page_projection ON 1 = 1
            LEFT JOIN (
                SELECT {$aggregate}({$object}(
                           'id', c.id,
                           'collection_key', c.collection_key,
                           'collection_type', c.collection_type,
                           'is_active', c.is_active,
                           'sort_order', c.sort_order,
                           'translations', COALESCE(ct.translations_json, {$emptyArray})
                       ){$aggregateSuffix}) AS collections_json
                FROM (
                    SELECT c.id, c.collection_key, c.collection_type, c.is_active, c.sort_order
                    FROM cms_collections c
                    WHERE c.is_active = 1
                    ORDER BY c.sort_order ASC, c.id ASC
                    LIMIT 200
                ) c
                LEFT JOIN ({$collectionTranslations}) ct ON ct.collection_id = c.id
            ) collection_projection ON 1 = 1
        SQL;

        $rows = ReadOnlyQuery::sql($db, $sql, [], 'CMS admin page form bootstrap projection');
        $row = $rows[0] ?? [];
        $languages = $this->decodeJsonList($row['languages_json'] ?? null);
        $pages = $this->decodeJsonList($row['pages_json'] ?? null);
        $collections = $this->decodeJsonList($row['collections_json'] ?? null);

        foreach ($languages as &$language) {
            $language['id'] = (int) ($language['id'] ?? 0);
            $language['is_default'] = (bool) ($language['is_default'] ?? false);
            $language['is_active'] = (bool) ($language['is_active'] ?? false);
        }
        unset($language);

        foreach ($pages as &$page) {
            $page['id'] = (int) ($page['id'] ?? 0);
            $page['parent_id'] = $page['parent_id'] === null ? null : (int) $page['parent_id'];
            $page['collection_id'] = $page['collection_id'] === null ? null : (int) $page['collection_id'];
            $page['translations'] = $this->decodeJsonList($page['translations'] ?? null);
        }
        unset($page);

        foreach ($collections as &$collection) {
            $collection['id'] = (int) ($collection['id'] ?? 0);
            $collection['is_active'] = (bool) ($collection['is_active'] ?? false);
            $collection['translations'] = $this->decodeJsonList($collection['translations'] ?? null);
            $collection['name'] = (string) ($collection['translations'][0]['name'] ?? $collection['collection_key'] ?? '');
        }
        unset($collection);

        return compact('languages', 'pages', 'collections');
    }

    /** @return list<array<string, mixed>> */
    private function decodeJsonList(mixed $value): array
    {
        $decoded = $this->decodeJson($value);
        if (is_string($decoded)) {
            $decoded = $this->decodeJson($decoded);
        }

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_array'));
    }

    private function decodeJson(mixed $value): mixed
    {
        if (! is_string($value) || trim($value) === '') {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    /** @param list<string> $permissions */
    private function cacheKey(string $context, array $permissions, int ...$ids): string
    {
        $scope = array_values(array_unique($permissions));
        sort($scope);

        return 'admin_cms_bootstrap_' . $context . '_' . implode('_', $ids ?: ['base'])
            . '_' . hash('sha256', implode("\0", $scope));
    }

    /** @return list<array<string, mixed>> */
    private function items(string $path, string $bearerToken): array
    {
        $data = $this->payloadData($this->client->get($path, $bearerToken));
        if (isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }
        if (! is_array($data)) {
            throw new RuntimeException('CMS bootstrap list payload is invalid.');
        }

        return array_values(array_filter(
            $data,
            static fn (mixed $item): bool => is_array($item),
        ));
    }

    /** @return array<string, mixed> */
    private function data(string $path, string $bearerToken): array
    {
        $data = $this->payloadData($this->client->get($path, $bearerToken));
        if (isset($data['data']) && is_array($data['data']) && ! isset($data['id'])) {
            $data = $data['data'];
        }
        if (! is_array($data)) {
            throw new RuntimeException('CMS bootstrap object payload is invalid.');
        }

        return $data;
    }

    /** @param array<string, mixed> $payload */
    private function payloadData(array $payload): mixed
    {
        if (array_key_exists('data', $payload)) {
            return $payload['data'];
        }

        return $payload;
    }
}
