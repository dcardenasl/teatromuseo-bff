<?php

declare(strict_types=1);

namespace App\AdminRead\Cms;

use App\AdminRead\Support\JsonArrayAggregateSql;
use App\AdminRead\Support\ReadOnlyQuery;
use CodeIgniter\Database\BaseConnection;

/**
 * One bounded projection per bootstrap screen, read straight from
 * `cms_readonly`. The CMS Domain HTTP path was removed from the bootstrap
 * flow so these projections are the only production implementation.
 *
 * Extracted from `AdminCmsBootstrapSource::directPageFormOptions()`,
 * `::directEntryFormOptions()`, `::directMenuEditorBootstrap()`,
 * `::directSiteIdentityBootstrap()` and their decode helpers as a pure move:
 * no SQL or decoding logic changed.
 */
final class CmsBootstrapDirectQueries
{
    /** @param BaseConnection<mixed,mixed> $db */
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function pageFormOptions(): array
    {
        $db = $this->db;
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

    /**
     * @param list<string> $permissions
     * @return array<string, list<array<string, mixed>>>
     */
    public function entryFormOptions(array $permissions): array
    {
        $db = $this->db;
        $jsonSql = JsonArrayAggregateSql::forDatabase($db);
        $aggregate = $jsonSql['aggregate'];
        $object = $jsonSql['object'];
        $suffix = $jsonSql['suffix'];
        $empty = "'[]'";

        $columns = [
            "(SELECT {$aggregate}({$object}('id', l.id, 'code', l.code, 'name', l.name, 'native_name', l.native_name, 'is_default', l.is_default, 'is_active', l.is_active, 'fallback_language_id', l.fallback_language_id, 'sort_order', l.sort_order){$suffix})
              FROM (SELECT id, code, name, native_name, is_default, is_active, fallback_language_id, sort_order
                    FROM cms_languages WHERE is_active = 1 ORDER BY sort_order ASC, id ASC LIMIT 100) l) AS languages_json",
            "(SELECT {$aggregate}({$object}('id', c.id, 'collection_key', c.collection_key, 'collection_type', c.collection_type, 'is_active', c.is_active, 'enables_categories', c.enables_categories, 'enables_tags', c.enables_tags, 'block_template', c.block_template){$suffix})
              FROM (SELECT id, collection_key, collection_type, is_active, enables_categories, enables_tags, block_template
                    FROM cms_collections WHERE is_active = 1 ORDER BY sort_order ASC, id ASC LIMIT 100) c) AS collections_json",
        ];

        if (in_array('cms.categories.read', $permissions, true)) {
            $columns[] = "(SELECT {$aggregate}({$object}('id', c.id, 'collection_id', c.collection_id, 'parent_id', c.parent_id, 'name', COALESCE(t.name, ''), 'slug', COALESCE(t.slug, ''), 'is_active', c.is_active){$suffix})
              FROM (SELECT id, collection_id, parent_id, is_active FROM cms_categories WHERE is_active = 1 ORDER BY sort_order ASC, id ASC LIMIT 1000) c
              LEFT JOIN cms_category_translations t ON t.category_id = c.id AND t.language_id = (SELECT id FROM cms_languages WHERE is_default = 1 AND is_active = 1 ORDER BY id ASC LIMIT 1)) AS categories_json";
        }
        if (in_array('cms.tags.read', $permissions, true)) {
            $columns[] = "(SELECT {$aggregate}({$object}('id', t.id, 'name', COALESCE(tt.name, ''), 'slug', COALESCE(tt.slug, ''), 'is_active', t.is_active){$suffix})
              FROM (SELECT id, is_active FROM cms_tags WHERE is_active = 1 ORDER BY id ASC LIMIT 1000) t
              LEFT JOIN cms_tag_translations tt ON tt.tag_id = t.id AND tt.language_id = (SELECT id FROM cms_languages WHERE is_default = 1 AND is_active = 1 ORDER BY id ASC LIMIT 1)) AS tags_json";
        }

        $row = ReadOnlyQuery::sql($db, 'SELECT ' . implode(",\n", $columns), [], 'CMS admin entry form options projection')[0] ?? [];
        $sections = [
            'languages' => $this->decodeAndNormalizeList($row['languages_json'] ?? null, ['is_default', 'is_active']),
            'collections' => $this->decodeAndNormalizeList($row['collections_json'] ?? null, ['is_active', 'enables_categories', 'enables_tags']),
        ];
        if (array_key_exists('categories_json', $row)) {
            $sections['categories'] = $this->decodeAndNormalizeList($row['categories_json'] ?? null, ['is_active']);
        }
        if (array_key_exists('tags_json', $row)) {
            $sections['tags'] = $this->decodeAndNormalizeList($row['tags_json'] ?? null, ['is_active']);
        }

        return $sections;
    }

    /** @return array<string, array<string, mixed>|list<array<string, mixed>>> */
    public function menuEditorBootstrap(int $menuId, ?int $itemId): array
    {
        $db = $this->db;
        $jsonSql = JsonArrayAggregateSql::forDatabase($db);
        $aggregate = $jsonSql['aggregate'];
        $object = $jsonSql['object'];
        $suffix = $jsonSql['suffix'];
        $empty = "'[]'";
        $translation = static function (string $table, string $foreignKey, string $fields, string $ownerAlias) use ($aggregate, $object, $suffix): string {
            return "COALESCE((SELECT {$aggregate}({$object}{$fields}{$suffix}) FROM {$table} t WHERE t.{$foreignKey} = {$ownerAlias}.id), '[]')";
        };

        $menuTranslations = $translation('cms_menu_translations', 'menu_id', "('language_id', t.language_id, 'name', t.name)", 'm');
        $itemTranslations = $translation('cms_menu_item_translations', 'menu_item_id', "('language_id', t.language_id, 'label', t.label, 'custom_url', t.custom_url)", 'i');
        $pageTranslations = $translation('cms_page_translations', 'page_id', "('language_id', t.language_id, 'slug', t.slug, 'title', t.title)", 'p');
        $entryTranslations = $translation('cms_entry_translations', 'entry_id', "('language_id', t.language_id, 'slug', t.slug, 'title', t.title)", 'e');
        $collectionTranslations = $translation('cms_collection_translations', 'collection_id', "('language_id', t.language_id, 'slug', t.slug, 'name', t.name)", 'c');

        $sql = <<<SQL
            SELECT
                (SELECT {$object}('id', m.id, 'menu_key', m.menu_key, 'location', m.location,
                                  'is_active', m.is_active, 'translations', {$menuTranslations})
                 FROM cms_menus m WHERE m.id = ?) AS menu_json,
                (SELECT {$aggregate}({$object}('id', i.id, 'menu_id', i.menu_id, 'parent_id', i.parent_id,
                                  'link_type', i.link_type, 'page_id', i.page_id, 'entry_id', i.entry_id,
                                  'collection_id', i.collection_id, 'link_target', i.link_target, 'icon', i.icon,
                                  'css_class', i.css_class, 'sort_order', i.sort_order, 'is_active', i.is_active,
                                  'translations', {$itemTranslations}){$suffix})
                 FROM (SELECT id, menu_id, parent_id, link_type, page_id, entry_id, collection_id,
                              link_target, icon, css_class, sort_order, is_active
                       FROM cms_menu_items
                       WHERE menu_id = ?
                       ORDER BY sort_order ASC, id ASC
                       LIMIT 1000) i) AS items_json,
                (SELECT {$aggregate}({$object}('id', l.id, 'code', l.code, 'name', l.name, 'native_name', l.native_name,
                                  'is_default', l.is_default, 'is_active', l.is_active, 'sort_order', l.sort_order){$suffix})
                 FROM (SELECT id, code, name, native_name, is_default, is_active, sort_order
                       FROM cms_languages WHERE is_active = 1 ORDER BY sort_order ASC, id ASC LIMIT 100) l) AS languages_json,
                (SELECT {$aggregate}({$object}('id', p.id, 'parent_id', p.parent_id, 'page_type', p.page_type,
                                  'status', p.status, 'translations', {$pageTranslations}){$suffix})
                 FROM (SELECT id, parent_id, page_type, status FROM cms_pages WHERE deleted_at IS NULL
                       ORDER BY sort_order ASC, id ASC LIMIT 250) p) AS pages_json,
                (SELECT {$aggregate}({$object}('id', e.id, 'collection_id', e.collection_id,
                                  'workflow_status', e.workflow_status, 'translations', {$entryTranslations}){$suffix})
                 FROM (SELECT id, collection_id, workflow_status FROM cms_entries WHERE deleted_at IS NULL
                       ORDER BY sort_order ASC, id ASC LIMIT 250) e) AS entries_json,
                (SELECT {$aggregate}({$object}('id', c.id, 'collection_key', c.collection_key,
                                  'collection_type', c.collection_type, 'is_active', c.is_active,
                                  'translations', {$collectionTranslations}){$suffix})
                 FROM (SELECT id, collection_key, collection_type, is_active FROM cms_collections WHERE is_active = 1
                       ORDER BY sort_order ASC, id ASC LIMIT 100) c)
                    AS collections_json
        SQL;
        $row = ReadOnlyQuery::sql($db, $sql, [$menuId, $menuId], 'CMS admin menu editor projection')[0] ?? [];
        $items = $this->decodeAndNormalizeList($row['items_json'] ?? null, ['is_active']);
        foreach ($items as &$menuItem) {
            $menuItem['translations'] = $this->decodeJsonList($menuItem['translations'] ?? null);
        }
        unset($menuItem);

        $sections = [
            'menu' => $this->decodeJsonObject($row['menu_json'] ?? null),
            'items' => $items,
            'languages' => $this->decodeAndNormalizeList($row['languages_json'] ?? null, ['is_default', 'is_active']),
            'pages' => $this->decodeAndNormalizeList($row['pages_json'] ?? null, []),
            'entries' => $this->decodeAndNormalizeList($row['entries_json'] ?? null, []),
            'collections' => $this->decodeAndNormalizeList($row['collections_json'] ?? null, ['is_active']),
        ];
        foreach (['pages', 'entries', 'collections'] as $section) {
            foreach ($sections[$section] as &$option) {
                $option['translations'] = $this->decodeJsonList($option['translations'] ?? null);
            }
            unset($option);
        }
        if ($itemId !== null) {
            $sections['item'] = array_values(array_filter(
                $items,
                static fn (array $item): bool => (int) ($item['id'] ?? 0) === $itemId,
            ))[0] ?? [];
        }

        return $sections;
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function siteIdentityBootstrap(): array
    {
        $db = $this->db;
        $jsonSql = JsonArrayAggregateSql::forDatabase($db);
        $aggregate = $jsonSql['aggregate'];
        $object = $jsonSql['object'];
        $suffix = $jsonSql['suffix'];
        $sql = <<<SQL
            SELECT
                (SELECT {$aggregate}({$object}('id', s.id, 'setting_key', s.setting_key,
                                  'setting_value', s.setting_value, 'setting_meta', s.setting_meta,
                                  'setting_type', s.setting_type, 'input_type', s.input_type,
                                  'options_json', s.options_json, 'is_required', s.is_required,
                                  'is_readonly', s.is_readonly, 'setting_group', s.setting_group,
                                  'is_translatable', s.is_translatable, 'sort_order', s.sort_order,
                                  'description', s.description, 'is_public', s.is_public,
                                  'is_active', s.is_active, 'translations',
                                  COALESCE((SELECT {$aggregate}({$object}('language_id', t.language_id,
                                      'setting_value', t.setting_value, 'label', t.label,
                                      'placeholder', t.placeholder, 'help_text', t.help_text){$suffix})
                                      FROM cms_setting_translations t WHERE t.setting_id = s.id), '[]')
                              ){$suffix})
                 FROM (SELECT id, setting_key, setting_value, setting_meta, setting_type, input_type,
                              options_json, is_required, is_readonly, setting_group, is_translatable,
                              sort_order, description, is_public, is_active
                       FROM cms_settings
                       WHERE is_active = 1 AND setting_group IN ('identity', 'social')
                       ORDER BY sort_order ASC, id ASC
                       LIMIT 100) s) AS settings_json,
                (SELECT {$aggregate}({$object}('id', l.id, 'code', l.code, 'name', l.name,
                                  'native_name', l.native_name, 'is_default', l.is_default,
                                  'is_active', l.is_active, 'fallback_language_id', l.fallback_language_id,
                                  'sort_order', l.sort_order){$suffix})
                 FROM (SELECT id, code, name, native_name, is_default, is_active, fallback_language_id, sort_order
                       FROM cms_languages WHERE is_active = 1 ORDER BY sort_order ASC, id ASC LIMIT 100) l) AS languages_json
        SQL;
        $row = ReadOnlyQuery::sql($db, $sql, [], 'CMS site identity projection')[0] ?? [];
        $settings = $this->decodeAndNormalizeList($row['settings_json'] ?? null, ['is_required', 'is_readonly', 'is_translatable', 'is_public', 'is_active']);
        foreach ($settings as &$setting) {
            $setting['translations'] = $this->decodeJsonList($setting['translations'] ?? null);
        }
        unset($setting);

        return [
            'settings' => $settings,
            'languages' => $this->decodeAndNormalizeList($row['languages_json'] ?? null, ['is_default', 'is_active']),
        ];
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

    /** @return array<string, mixed> */
    private function decodeJsonObject(mixed $value): array
    {
        $decoded = $this->decodeJson($value);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param list<string> $booleanFields
     * @return list<array<string, mixed>>
     */
    private function decodeAndNormalizeList(mixed $value, array $booleanFields): array
    {
        $items = $this->decodeJsonList($value);
        foreach ($items as &$item) {
            foreach ($booleanFields as $field) {
                if (array_key_exists($field, $item)) {
                    $item[$field] = (bool) $item[$field];
                }
            }
            foreach (['id', 'collection_id', 'parent_id', 'page_id', 'entry_id'] as $field) {
                if (array_key_exists($field, $item) && $item[$field] !== null) {
                    $item[$field] = (int) $item[$field];
                }
            }
        }
        unset($item);

        return $items;
    }

    private function decodeJson(mixed $value): mixed
    {
        if (! is_string($value) || trim($value) === '') {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }
}
