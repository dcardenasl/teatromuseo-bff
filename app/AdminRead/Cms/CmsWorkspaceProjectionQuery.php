<?php

declare(strict_types=1);

namespace App\AdminRead\Cms;

use App\AdminRead\Support\JsonArrayAggregateSql;
use App\AdminRead\Support\ReadOnlyQuery;
use CodeIgniter\Database\BaseConnection;

/**
 * Builds and executes the single bounded SQL projection behind
 * {@see AdminCmsWorkspaceSource}: one page/entry row joined against its
 * translations, blocks (with their own translations), active block types,
 * languages, collections, pages, entries, forms and (permission-gated)
 * category/entry-taxonomy relations. Every relation is reduced by the
 * database engine first; this class returns the raw joined row (still
 * JSON-encoded per relation) — {@see CmsWorkspaceRowHydrator} decodes and
 * types it.
 *
 * Extracted from `AdminCmsWorkspaceSource::workspaceProjection()` as a pure
 * move: no SQL or binding order changed.
 */
final class CmsWorkspaceProjectionQuery
{
    /**
     * @param BaseConnection<mixed,mixed> $db
     * @param 'page'|'entry' $ownerType
     * @param list<string> $permissions
     * @return array<string, mixed>|null
     */
    public static function fetch(BaseConnection $db, string $ownerType, int $ownerId, array $permissions): ?array
    {
        $jsonSql = JsonArrayAggregateSql::forDatabase($db);
        $aggregate = $jsonSql['aggregate'];
        $object = $jsonSql['object'];
        $aggregateSuffix = $jsonSql['suffix'];
        $emptyArray = "'[]'";

        $pageTranslations = <<<SQL
            SELECT t.page_id AS resource_id,
                   {$aggregate}({$object}(
                       'id', t.id,
                       'page_id', t.page_id,
                       'language_id', t.language_id,
                       'slug', t.slug,
                       'title', t.title,
                       'excerpt', t.excerpt,
                       'meta_title', t.meta_title,
                       'meta_description', t.meta_description,
                       'og_image_file_id', t.og_image_file_id,
                       'og_image_url', t.og_image_url,
                       'og_type', t.og_type,
                       'canonical_url', t.canonical_url,
                       'robots', t.robots,
                       'schema_data', t.schema_data,
                       'created_at', t.created_at,
                       'updated_at', t.updated_at
                   ){$aggregateSuffix}) AS translations_json
            FROM (
                SELECT id, page_id, language_id, slug, title, excerpt,
                       meta_title, meta_description, og_image_file_id,
                       og_image_url, og_type, canonical_url, robots,
                       schema_data, created_at, updated_at
                FROM cms_page_translations
                WHERE page_id = ?
                ORDER BY language_id ASC, id ASC
            ) t
            GROUP BY t.page_id
        SQL;

        $entryTranslations = <<<SQL
            SELECT t.entry_id AS resource_id,
                   {$aggregate}({$object}(
                       'id', t.id,
                       'entry_id', t.entry_id,
                       'language_id', t.language_id,
                       'slug', t.slug,
                       'title', t.title,
                       'excerpt', t.excerpt,
                       'featured_file_id', t.featured_file_id,
                       'featured_image_url', t.featured_image_url,
                       'meta_title', t.meta_title,
                       'meta_description', t.meta_description,
                       'og_image_file_id', t.og_image_file_id,
                       'og_type', t.og_type,
                       'canonical_url', t.canonical_url,
                       'robots', t.robots,
                       'schema_data', t.schema_data,
                       'created_at', t.created_at,
                       'updated_at', t.updated_at
                   ){$aggregateSuffix}) AS translations_json
            FROM (
                SELECT id, entry_id, language_id, slug, title, excerpt,
                       featured_file_id, featured_image_url, meta_title,
                       meta_description, og_image_file_id, og_type,
                       canonical_url, robots, schema_data, created_at,
                       updated_at
                FROM cms_entry_translations
                WHERE entry_id = ?
                ORDER BY language_id ASC, id ASC
            ) t
            GROUP BY t.entry_id
        SQL;

        $blockTranslations = <<<SQL
            SELECT t.instance_id,
                   {$aggregate}({$object}(
                       'id', t.id,
                       'instance_id', t.instance_id,
                       'language_id', t.language_id,
                       'block_data', t.block_data,
                       'is_published', t.is_published,
                       'created_at', t.created_at,
                       'updated_at', t.updated_at
                   ){$aggregateSuffix}) AS translations_json
            FROM (
                SELECT t.id, t.instance_id, t.language_id, t.block_data,
                       t.is_published, t.created_at, t.updated_at
                FROM cms_block_instance_translations t
                INNER JOIN (
                    SELECT id
                    FROM cms_block_instances
                    WHERE owner_type = '{$ownerType}'
                      AND owner_id = ?
                ) selected_instances ON selected_instances.id = t.instance_id
                ORDER BY t.instance_id ASC, t.language_id ASC, t.id ASC
            ) t
            GROUP BY t.instance_id
        SQL;

        $blocks = <<<SQL
            SELECT bi.owner_type,
                   bi.owner_id,
                   {$aggregate}({$object}(
                       'id', bi.id,
                       'block_id', bi.block_id,
                       'owner_type', bi.owner_type,
                       'owner_id', bi.owner_id,
                       'parent_instance_id', bi.parent_instance_id,
                       'sort_order', bi.sort_order,
                       'column_index', bi.column_index,
                       'is_active', bi.is_active,
                       'block_config', bi.block_config,
                       'created_at', bi.created_at,
                       'updated_at', bi.updated_at,
                       'translations', COALESCE(bt.translations_json, {$emptyArray})
                   ){$aggregateSuffix}) AS blocks_json
            FROM cms_block_instances bi
            LEFT JOIN ({$blockTranslations}) bt ON bt.instance_id = bi.id
            WHERE bi.owner_type = '{$ownerType}'
              AND bi.owner_id = ?
            GROUP BY bi.owner_type, bi.owner_id
        SQL;

        $blockTypes = <<<SQL
            SELECT {$aggregate}({$object}(
                       'id', b.id,
                       'block_key', b.block_key,
                       'name', b.name,
                       'description', b.description,
                       'category', b.category,
                       'icon', b.icon,
                       'schema_definition', b.schema_definition,
                       'supports_pages', b.supports_pages,
                       'supports_entries', b.supports_entries,
                       'is_container', b.is_container,
                       'is_active', b.is_active,
                       'sort_order', b.sort_order
                   ){$aggregateSuffix}) AS block_types_json
            FROM (
                SELECT id, block_key, name, description, category, icon,
                       schema_definition, supports_pages, supports_entries,
                       is_container, is_active, sort_order
                FROM cms_content_blocks
                WHERE is_active = 1
                ORDER BY sort_order ASC, name ASC, id ASC
            ) b
        SQL;

        $languages = <<<SQL
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
        SQL;

        $collectionTranslations = <<<SQL
            SELECT t.collection_id,
                   {$aggregate}({$object}(
                       'id', t.id,
                       'collection_id', t.collection_id,
                       'language_id', t.language_id,
                       'slug', t.slug,
                       'name', t.name
                   ){$aggregateSuffix}) AS translations_json
            FROM (
                SELECT t.id, t.collection_id, t.language_id, t.slug, t.name
                FROM cms_collection_translations t
                INNER JOIN (
                    SELECT id
                    FROM cms_collections
                    WHERE is_active = 1
                    ORDER BY sort_order ASC, id ASC
                    LIMIT 250
                ) selected_collections ON selected_collections.id = t.collection_id
                ORDER BY t.collection_id ASC, t.language_id ASC, t.id ASC
            ) t
            GROUP BY t.collection_id
        SQL;

        $collections = <<<SQL
            SELECT {$aggregate}({$object}(
                       'id', c.id,
                       'collection_key', c.collection_key,
                       'collection_type', c.collection_type,
                       'enables_categories', c.enables_categories,
                       'enables_tags', c.enables_tags,
                       'block_template', c.block_template,
                       'is_active', c.is_active,
                       'sort_order', c.sort_order,
                       'translations', COALESCE(ct.translations_json, {$emptyArray})
                   ){$aggregateSuffix}) AS collections_json
            FROM (
                SELECT c.id, c.collection_key, c.collection_type,
                       c.enables_categories, c.enables_tags, c.block_template,
                       c.is_active, c.sort_order
                FROM cms_collections c
                WHERE c.is_active = 1
                ORDER BY c.sort_order ASC, c.id ASC
                LIMIT 250
            ) c
            LEFT JOIN ({$collectionTranslations}) ct ON ct.collection_id = c.id
        SQL;

        $pageOptionTranslations = <<<SQL
            SELECT t.page_id,
                   {$aggregate}({$object}(
                       'id', t.id,
                       'language_id', t.language_id,
                       'slug', t.slug,
                       'title', t.title
                   ){$aggregateSuffix}) AS translations_json
            FROM (
                SELECT t.id, t.page_id, t.language_id, t.slug, t.title
                FROM cms_page_translations t
                INNER JOIN (
                    SELECT id
                    FROM cms_pages
                    WHERE deleted_at IS NULL
                    ORDER BY sort_order ASC, id ASC
                    LIMIT 250
                ) selected_pages ON selected_pages.id = t.page_id
                ORDER BY t.page_id ASC, t.language_id ASC, t.id ASC
            ) t
            GROUP BY t.page_id
        SQL;

        $pages = <<<SQL
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
                SELECT p.id, p.parent_id, p.collection_id, p.page_type, p.status, p.sort_order, p.created_at, p.updated_at
                FROM cms_pages p
                WHERE p.deleted_at IS NULL
                ORDER BY p.sort_order ASC, p.id ASC
                LIMIT 250
            ) p
            LEFT JOIN ({$pageOptionTranslations}) pt ON pt.page_id = p.id
        SQL;

        $entryOptionTranslations = <<<SQL
            SELECT t.entry_id,
                   {$aggregate}({$object}(
                       'id', t.id,
                       'language_id', t.language_id,
                       'slug', t.slug,
                       'title', t.title
                   ){$aggregateSuffix}) AS translations_json
            FROM (
                SELECT t.id, t.entry_id, t.language_id, t.slug, t.title
                FROM cms_entry_translations t
                INNER JOIN (
                    SELECT id
                    FROM cms_entries
                    WHERE deleted_at IS NULL
                    ORDER BY sort_order ASC, id ASC
                    LIMIT 250
                ) selected_entries ON selected_entries.id = t.entry_id
                ORDER BY t.entry_id ASC, t.language_id ASC, t.id ASC
            ) t
            GROUP BY t.entry_id
        SQL;

        $entries = <<<SQL
            SELECT {$aggregate}({$object}(
                       'id', e.id,
                       'collection_id', e.collection_id,
                       'workflow_status', e.workflow_status,
                       'published_at', e.published_at,
                       'sort_order', e.sort_order,
                       'created_at', e.created_at,
                       'updated_at', e.updated_at,
                       'translations', COALESCE(et.translations_json, {$emptyArray})
                   ){$aggregateSuffix}) AS entries_json
            FROM (
                SELECT e.id, e.collection_id, e.workflow_status, e.published_at, e.sort_order, e.created_at, e.updated_at
                FROM cms_entries e
                WHERE e.deleted_at IS NULL
                ORDER BY e.sort_order ASC, e.id ASC
                LIMIT 250
            ) e
            LEFT JOIN ({$entryOptionTranslations}) et ON et.entry_id = e.id
        SQL;

        $forms = <<<SQL
            SELECT {$aggregate}({$object}('form_key', f.form_key){$aggregateSuffix}) AS forms_json
            FROM (
                SELECT id, form_key
                FROM cms_forms
                WHERE is_active = 1
                ORDER BY form_key ASC, id ASC
                LIMIT 100
            ) f
        SQL;

        $categoryTranslations = <<<SQL
            SELECT t.category_id,
                   {$aggregate}({$object}('id', t.id, 'language_id', t.language_id, 'name', t.name){$aggregateSuffix}) AS translations_json
            FROM (
                SELECT t.id, t.category_id, t.language_id, t.name
                FROM cms_category_translations t
                INNER JOIN (
                    SELECT id
                    FROM cms_categories
                    WHERE is_active = 1
                    ORDER BY sort_order ASC, id ASC
                    LIMIT 500
                ) selected_categories ON selected_categories.id = t.category_id
                ORDER BY t.category_id ASC, t.language_id ASC, t.id ASC
            ) t
            GROUP BY t.category_id
        SQL;

        $categoriesProjection = <<<SQL
            SELECT {$aggregate}({$object}(
                       'id', c.id,
                       'collection_id', c.collection_id,
                       'translations', COALESCE(ct.translations_json, {$emptyArray})
                   ){$aggregateSuffix}) AS categories_json
            FROM (
                SELECT c.id, c.collection_id
                FROM cms_categories c
                WHERE c.is_active = 1
                ORDER BY c.sort_order ASC, c.id ASC
                LIMIT 500
            ) c
            LEFT JOIN ({$categoryTranslations}) ct ON ct.category_id = c.id
        SQL;
        $categories = in_array('cms.categories.read', $permissions, true)
            ? $categoriesProjection
            : "SELECT {$emptyArray} AS categories_json";

        $entryCategories = "SELECT {$emptyArray} AS entry_categories_json";
        $entryTags = "SELECT {$emptyArray} AS entry_tags_json";
        $taxonomyPermissions = $ownerType === 'entry'
            && (in_array('cms.categories.read', $permissions, true) || in_array('cms.tags.read', $permissions, true));
        if ($taxonomyPermissions && in_array('cms.categories.read', $permissions, true)) {
            $entryCategoryTranslations = <<<SQL
                SELECT t.category_id,
                       {$aggregate}({$object}(
                           'id', t.id,
                           'language_id', t.language_id,
                           'slug', t.slug,
                           'name', t.name
                       ){$aggregateSuffix}) AS translations_json
                FROM cms_category_translations t
                INNER JOIN cms_entry_categories ec ON ec.category_id = t.category_id
                WHERE ec.entry_id = ?
                GROUP BY t.category_id
            SQL;
            $entryCategories = <<<SQL
                SELECT ec.entry_id,
                       {$aggregate}({$object}(
                           'id', c.id,
                           'collection_id', c.collection_id,
                           'translations', COALESCE(ct.translations_json, {$emptyArray})
                       ){$aggregateSuffix}) AS entry_categories_json
                FROM cms_entry_categories ec
                INNER JOIN cms_categories c ON c.id = ec.category_id AND c.is_active = 1
                LEFT JOIN ({$entryCategoryTranslations}) ct ON ct.category_id = c.id
                WHERE ec.entry_id = ?
                GROUP BY ec.entry_id
            SQL;
        }
        if ($taxonomyPermissions && in_array('cms.tags.read', $permissions, true)) {
            $entryTagTranslations = <<<SQL
                SELECT t.tag_id,
                       {$aggregate}({$object}(
                           'id', t.id,
                           'language_id', t.language_id,
                           'slug', t.slug,
                           'name', t.name
                       ){$aggregateSuffix}) AS translations_json
                FROM cms_tag_translations t
                INNER JOIN cms_entry_tags et ON et.tag_id = t.tag_id
                WHERE et.entry_id = ?
                GROUP BY t.tag_id
            SQL;
            $entryTags = <<<SQL
                SELECT et.entry_id,
                       {$aggregate}({$object}(
                           'id', t.id,
                           'translations', COALESCE(tt.translations_json, {$emptyArray})
                       ){$aggregateSuffix}) AS entry_tags_json
                FROM cms_entry_tags et
                INNER JOIN cms_tags t ON t.id = et.tag_id AND t.is_active = 1
                LEFT JOIN ({$entryTagTranslations}) tt ON tt.tag_id = t.id
                WHERE et.entry_id = ?
                GROUP BY et.entry_id
            SQL;
        }

        $ownerTable = $ownerType === 'entry' ? 'cms_entries' : 'cms_pages';
        $ownerTranslations = $ownerType === 'entry' ? $entryTranslations : $pageTranslations;
        $ownerSelect = $ownerType === 'entry'
            ? 'o.id, o.collection_id, o.author_id, o.workflow_status, o.published_at, o.scheduled_at, o.is_featured, o.view_count, o.sort_order, o.wizard_extra, o.sitemap_priority, o.sitemap_changefreq, o.is_in_sitemap, o.created_at, o.updated_at'
            : 'o.id, o.parent_id, o.collection_id, o.page_type, o.status, o.published_at, o.scheduled_at, o.sort_order, o.sitemap_priority, o.sitemap_changefreq, o.is_in_sitemap, o.created_at, o.updated_at';

        $sql = <<<SQL
            SELECT {$ownerSelect},
                   COALESCE(owner_translations.translations_json, {$emptyArray}) AS owner_translations_json,
                   COALESCE(block_projection.blocks_json, {$emptyArray}) AS blocks_json,
                   COALESCE(block_type_projection.block_types_json, {$emptyArray}) AS block_types_json,
                   COALESCE(language_projection.languages_json, {$emptyArray}) AS languages_json,
                   COALESCE(collection_projection.collections_json, {$emptyArray}) AS collections_json,
                   COALESCE(page_projection.pages_json, {$emptyArray}) AS pages_json,
                   COALESCE(entry_projection.entries_json, {$emptyArray}) AS entries_json,
                   COALESCE(form_projection.forms_json, {$emptyArray}) AS forms_json,
                   COALESCE(category_projection.categories_json, {$emptyArray}) AS categories_json,
                   COALESCE(entry_category_projection.entry_categories_json, {$emptyArray}) AS entry_categories_json,
                   COALESCE(entry_tag_projection.entry_tags_json, {$emptyArray}) AS entry_tags_json
            FROM {$ownerTable} o
            LEFT JOIN ({$ownerTranslations}) owner_translations
                ON owner_translations.resource_id = o.id
            LEFT JOIN ({$blocks}) block_projection
                ON block_projection.owner_type = '{$ownerType}'
               AND block_projection.owner_id = o.id
            LEFT JOIN ({$blockTypes}) block_type_projection ON 1 = 1
            LEFT JOIN ({$languages}) language_projection ON 1 = 1
            LEFT JOIN ({$collections}) collection_projection ON 1 = 1
            LEFT JOIN ({$pages}) page_projection ON 1 = 1
            LEFT JOIN ({$entries}) entry_projection ON 1 = 1
            LEFT JOIN ({$forms}) form_projection ON 1 = 1
            LEFT JOIN ({$categories}) category_projection ON 1 = 1
            LEFT JOIN ({$entryCategories}) entry_category_projection ON 1 = 1
            LEFT JOIN ({$entryTags}) entry_tag_projection ON 1 = 1
            WHERE o.id = ?
              AND o.deleted_at IS NULL
            LIMIT 1
        SQL;

        $rows = ReadOnlyQuery::sql(
            $db,
            $sql,
            self::bindings($ownerId, $ownerType, $permissions),
            'CMS admin ' . $ownerType . ' workspace projection',
        );

        return $rows[0] ?? null;
    }

    /**
     * @param list<string> $permissions
     * @return list<int>
     */
    private static function bindings(int $ownerId, string $ownerType, array $permissions): array
    {
        $bindings = [$ownerId, $ownerId, $ownerId, $ownerId];
        if ($ownerType === 'entry') {
            if (in_array('cms.categories.read', $permissions, true)) {
                $bindings[] = $ownerId;
                $bindings[] = $ownerId;
            }
            if (in_array('cms.tags.read', $permissions, true)) {
                $bindings[] = $ownerId;
                $bindings[] = $ownerId;
            }
        }

        return $bindings;
    }
}
