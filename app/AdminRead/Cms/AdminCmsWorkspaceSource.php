<?php

declare(strict_types=1);

namespace App\AdminRead\Cms;

use App\AdminRead\Contracts\AdminCmsWorkspaceSourceInterface;
use App\AdminRead\Support\JsonArrayAggregateSql;
use App\AdminRead\Support\ReadOnlyQuery;
use App\PublicRead\Cms\FileUrlResolver;
use CodeIgniter\Database\BaseConnection;
use dcardenasl\Ci4ApiCore\Exceptions\AuthorizationException;
use RuntimeException;

/**
 * Read-only CMS workspace projection used by the Admin page/block screens.
 *
 * The Admin makes one request to the BFF. The BFF reads the CMS and Hub
 * read-only connections directly, avoiding an HTTP fan-out through several
 * PHP processes on the constrained hosting plan.
 */
final class AdminCmsWorkspaceSource implements AdminCmsWorkspaceSourceInterface
{
    /** @param BaseConnection<mixed, mixed> $db */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly FileUrlResolver $fileUrlResolver,
    ) {
    }

    /**
     * @param list<string> $permissions
     * @return array<string, mixed>
     */
    public function pageWorkspace(int $pageId, ?int $instanceId, array $permissions): array
    {
        return $this->workspace('page', $pageId, $instanceId, $permissions);
    }

    /**
     * @param list<string> $permissions
     * @return array<string, mixed>
     */
    public function entryWorkspace(int $entryId, ?int $instanceId, array $permissions): array
    {
        return $this->workspace('entry', $entryId, $instanceId, $permissions);
    }

    /**
     * @param 'page'|'entry' $ownerType
     * @param list<string> $permissions
     * @return array<string, mixed>
     */
    private function workspace(string $ownerType, int $ownerId, ?int $instanceId, array $permissions): array
    {
        if ($ownerId < 1) {
            throw new RuntimeException('A positive CMS owner identifier is required.');
        }
        $permission = $ownerType === 'entry' ? 'cms.entries.read' : 'cms.pages.read';
        if (! in_array($permission, $permissions, true)) {
            throw new AuthorizationException('The ' . $permission . ' permission is required.');
        }

        $projection = $this->workspaceProjection($ownerType, $ownerId, $permissions);
        if ($projection === null) {
            throw new RuntimeException('CMS ' . $ownerType . ' not found.');
        }

        $owner = $projection['owner'];
        $languages = $projection['languages'];
        $blocks = $projection['blocks'];
        $collections = $projection['collections'];
        $pages = $projection['pages'];
        $entries = $projection['entries'];
        $blockTypes = $this->withDynamicOptions(
            $projection['blockTypes'],
            $collections,
            $permissions,
            $projection['forms'],
            $pages,
            $entries,
            $projection['categories'],
        );

        $sections = [
            $ownerType => $owner,
            'pages' => $pages,
            'collections' => $collections,
            'languages' => $languages,
            'blocks' => $blocks,
            'blockTypes' => $blockTypes,
            'collectionsMap' => $this->collectionsMap($collections),
            'listingFieldCatalog' => $this->listingFieldCatalog($collections, $blockTypes),
            'quality' => [],
            'blockTranslationStatus' => [],
        ];
        if ($ownerType === 'entry') {
            $sections['entries'] = $entries;
        }

        if ($instanceId !== null && $instanceId > 0) {
            $selected = $this->findBlock($blocks, $instanceId);
            if ($selected !== null) {
                $sections['block'] = $selected;
                $sections['blockType'] = $blockTypes[(int) ($selected['block_id'] ?? 0)] ?? [];
                $parentId = (int) ($selected['parent_instance_id'] ?? 0);
                if ($parentId > 0) {
                    $sections['parentBlock'] = $this->findBlock($blocks, $parentId) ?? [];
                    $sections['parentType'] = $blockTypes[(int) ($sections['parentBlock']['block_id'] ?? 0)] ?? [];
                }
                $sections['children'] = array_values(array_filter(
                    $blocks,
                    static fn (array $block): bool => (int) ($block['parent_instance_id'] ?? 0) === $instanceId,
                ));
            }
        }

        return $sections;
    }

    /**
     * Execute the complete CMS workspace projection in one database round
     * trip. Each relation is reduced by the database engine first and then
     * joined to the owner row; PHP only decodes the already-shaped JSON.
     *
     * @return array<string, mixed>|null
     */
    private function workspaceProjection(string $ownerType, int $ownerId, array $permissions): ?array
    {
        $jsonSql = JsonArrayAggregateSql::forDatabase($this->db);
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
                       'is_active', c.is_active,
                       'sort_order', c.sort_order,
                       'translations', COALESCE(ct.translations_json, {$emptyArray})
                   ){$aggregateSuffix}) AS collections_json
            FROM (
                SELECT c.id, c.collection_key, c.collection_type, c.is_active, c.sort_order
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
                   COALESCE(category_projection.categories_json, {$emptyArray}) AS categories_json
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
            WHERE o.id = ?
              AND o.deleted_at IS NULL
            LIMIT 1
        SQL;

        $rows = ReadOnlyQuery::sql(
            $this->db,
            $sql,
            [$ownerId, $ownerId, $ownerId, $ownerId],
            'CMS admin ' . $ownerType . ' workspace projection',
        );
        $row = $rows[0] ?? null;
        if ($row === null) {
            return null;
        }

        return $this->hydrateWorkspaceProjection($row, $ownerType);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateWorkspaceProjection(array $row, string $ownerType): array
    {
        $translations = $this->decodeJsonList($row['owner_translations_json'] ?? null);
        foreach ($translations as &$translation) {
            $translation['schema_data'] = $this->decodeJson($translation['schema_data'] ?? null);
        }
        unset($translation);

        $owner = $row;
        unset(
            $owner['owner_translations_json'],
            $owner['blocks_json'],
            $owner['block_types_json'],
            $owner['languages_json'],
            $owner['collections_json'],
            $owner['pages_json'],
            $owner['entries_json'],
            $owner['forms_json'],
            $owner['categories_json'],
        );
        $owner['id'] = (int) $owner['id'];
        $owner['collection_id'] = $owner['collection_id'] === null ? null : (int) $owner['collection_id'];
        if ($ownerType === 'page') {
            $owner['parent_id'] = $owner['parent_id'] === null ? null : (int) $owner['parent_id'];
            $owner['is_in_sitemap'] = (bool) $owner['is_in_sitemap'];
        } else {
            foreach (['author_id', 'view_count', 'sort_order'] as $field) {
                if ($owner[$field] !== null) {
                    $owner[$field] = (int) $owner[$field];
                }
            }
            $owner['is_featured'] = (bool) $owner['is_featured'];
            $owner['is_in_sitemap'] = (bool) $owner['is_in_sitemap'];
            $owner['wizard_extra'] = $this->decodeJson($owner['wizard_extra'] ?? null);
        }
        $owner['translations'] = $translations;
        $default = $translations[0] ?? [];
        $owner['title'] = (string) ($default['title'] ?? '');
        $owner['slug'] = (string) ($default['slug'] ?? '');
        if ($ownerType === 'entry') {
            $owner['excerpt'] = (string) ($default['excerpt'] ?? '');
        }

        $blocks = $this->decodeJsonList($row['blocks_json'] ?? null);
        $fileIds = [];
        foreach ($blocks as &$block) {
            $block['id'] = (int) ($block['id'] ?? 0);
            $block['block_id'] = (int) ($block['block_id'] ?? 0);
            $block['owner_id'] = (int) ($block['owner_id'] ?? 0);
            $block['parent_instance_id'] = $block['parent_instance_id'] === null ? null : (int) $block['parent_instance_id'];
            $block['sort_order'] = (int) ($block['sort_order'] ?? 0);
            $block['column_index'] = $block['column_index'] === null ? null : (int) $block['column_index'];
            $block['is_active'] = (bool) ($block['is_active'] ?? false);
            $block['block_config'] = $this->decodeJson($block['block_config'] ?? null);
            $block['translations'] = $this->decodeJsonList($block['translations'] ?? null);
            foreach ($block['translations'] as &$translation) {
                $translation['language_id'] = (int) ($translation['language_id'] ?? 0);
                $translation['is_published'] = (bool) ($translation['is_published'] ?? false);
                $translation['block_data'] = $this->decodeJson($translation['block_data'] ?? null);
                $fileIds = array_merge($fileIds, $this->fileIds($translation['block_data']));
            }
            unset($translation);
            $fileIds = array_merge($fileIds, $this->fileIds($block['block_config']));
        }
        unset($block);

        $media = $this->fileUrlResolver->resolveManyMeta(array_values(array_unique($fileIds)), 'admin');
        foreach ($blocks as &$block) {
            $block['block_config'] = $this->hydrateMedia($block['block_config'], $media);
            foreach ($block['translations'] as &$translation) {
                $translation['block_data'] = $this->hydrateMedia($translation['block_data'], $media);
            }
            unset($translation);
        }
        unset($block);

        $blockTypes = [];
        foreach ($this->decodeJsonList($row['block_types_json'] ?? null) as $blockType) {
            $blockType['id'] = (int) ($blockType['id'] ?? 0);
            $schema = $this->decodeJson($blockType['schema_definition'] ?? null);
            $blockType['schema_definition'] = $schema;
            $blockType['fields'] = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];
            $blockType['config_fields'] = is_array($schema['config_fields'] ?? null) ? $schema['config_fields'] : [];
            $blockType['supports_pages'] = (bool) ($blockType['supports_pages'] ?? false);
            $blockType['supports_entries'] = (bool) ($blockType['supports_entries'] ?? false);
            $blockType['is_container'] = (bool) ($blockType['is_container'] ?? false);
            $blockType['is_active'] = (bool) ($blockType['is_active'] ?? false);
            $blockTypes[$blockType['id']] = $blockType;
        }

        $collections = $this->decodeJsonList($row['collections_json'] ?? null);
        foreach ($collections as &$collection) {
            $collection['id'] = (int) ($collection['id'] ?? 0);
            $collection['is_active'] = (bool) ($collection['is_active'] ?? false);
            $collection['translations'] = $this->decodeJsonList($collection['translations'] ?? null);
            $collection['name'] = (string) ($collection['translations'][0]['name'] ?? $collection['collection_key'] ?? '');
        }
        unset($collection);

        $pages = $this->decodeJsonList($row['pages_json'] ?? null);
        foreach ($pages as &$page) {
            $page['id'] = (int) ($page['id'] ?? 0);
            $page['translations'] = $this->decodeJsonList($page['translations'] ?? null);
        }
        unset($page);

        $entries = $this->decodeJsonList($row['entries_json'] ?? null);
        foreach ($entries as &$entry) {
            $entry['id'] = (int) ($entry['id'] ?? 0);
            $entry['collection_id'] = $entry['collection_id'] === null ? null : (int) $entry['collection_id'];
            $entry['translations'] = $this->decodeJsonList($entry['translations'] ?? null);
            $entry['title'] = (string) ($entry['translations'][0]['title'] ?? $entry['translations'][0]['slug'] ?? $entry['id']);
        }
        unset($entry);

        $categories = [];
        $collectionNames = $this->collectionNames($collections);
        foreach ($this->decodeJsonList($row['categories_json'] ?? null) as $category) {
            $category['id'] = (int) ($category['id'] ?? 0);
            $category['collection_id'] = (int) ($category['collection_id'] ?? 0);
            $category['translations'] = $this->decodeJsonList($category['translations'] ?? null);
            $categories[] = [
                'value' => (string) $category['id'],
                'label' => (string) ($collectionNames[$category['collection_id']] ?? 'Colección')
                    . ' · ' . (string) ($category['translations'][0]['name'] ?? $category['id']),
            ];
        }

        $forms = [];
        foreach ($this->decodeJsonList($row['forms_json'] ?? null) as $form) {
            $key = trim((string) ($form['form_key'] ?? ''));
            if ($key !== '') {
                $forms[] = $key;
            }
        }

        $languages = $this->decodeJsonList($row['languages_json'] ?? null);
        foreach ($languages as &$language) {
            $language['id'] = (int) ($language['id'] ?? 0);
            $language['is_default'] = (bool) ($language['is_default'] ?? false);
            $language['is_active'] = (bool) ($language['is_active'] ?? false);
        }
        unset($language);

        return [
            'owner' => $owner,
            'blocks' => $blocks,
            'blockTypes' => $blockTypes,
            'languages' => $languages,
            'collections' => $collections,
            'pages' => $pages,
            'entries' => $entries,
            'forms' => array_values(array_unique($forms)),
            'categories' => $categories,
        ];
    }

    /** @param list<array<string, mixed>> $collections @return array<int, string> */
    private function collectionNames(array $collections): array
    {
        $names = [];
        foreach ($collections as $collection) {
            $names[(int) ($collection['id'] ?? 0)] = (string) ($collection['name'] ?? $collection['collection_key'] ?? 'Colección');
        }

        return $names;
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

    /** @param list<array<string, mixed>> $collections @return array<string, int> */
    private function collectionsMap(array $collections): array
    {
        $map = [];
        foreach ($collections as $collection) {
            $key = trim((string) ($collection['collection_key'] ?? ''));
            $id = (int) ($collection['id'] ?? 0);
            if ($key !== '' && $id > 0) {
                $map[$key] = $id;
            }
        }

        return $map;
    }

    /**
     * @param array<int, array<string, mixed>> $blockTypes
     * @param list<array<string, mixed>> $collections
     * @return array<int, array<string, mixed>>
     */
    private function withCollectionOptions(array $blockTypes, array $collections): array
    {
        $options = [];
        foreach ($collections as $collection) {
            $key = trim((string) ($collection['collection_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $options[] = $key;
        }

        foreach ($blockTypes as &$blockType) {
            $configFields = is_array($blockType['config_fields'] ?? null) ? $blockType['config_fields'] : [];
            if (! isset($configFields['collection_key']) || ! is_array($configFields['collection_key'])) {
                continue;
            }
            if ($options !== []) {
                $configFields['collection_key']['type'] = 'select';
                $configFields['collection_key']['options'] = $options;
            }
            $blockType['config_fields'] = $configFields;
            $schema = is_array($blockType['schema_definition'] ?? null) ? $blockType['schema_definition'] : [];
            $schema['config_fields'] = $configFields;
            $blockType['schema_definition'] = $schema;
        }
        unset($blockType);

        return $blockTypes;
    }

    /**
     * Fill the dynamic selects used by the block create/edit form in the same
     * bounded read as the workspace. This keeps the Admin entry path from
     * reopening the old forms/collections/pages/entries fan-out.
     *
     * @param array<int, array<string, mixed>> $blockTypes
     * @param list<array<string, mixed>> $collections
     * @param list<string> $permissions
     * @param list<string> $preloadedForms
     * @param list<array<string, mixed>> $preloadedPages
     * @param list<array<string, mixed>> $preloadedEntries
     * @param list<array{value: string, label: string}> $preloadedCategories
     * @return array<int, array<string, mixed>>
     */
    private function withDynamicOptions(
        array $blockTypes,
        array $collections,
        array $permissions,
        array $preloadedForms,
        array $preloadedPages,
        array $preloadedEntries,
        array $preloadedCategories,
    ): array {
        $blockTypes = $this->withCollectionOptions($blockTypes, $collections);
        $collectionKeys = [];
        $collectionIds = [];
        foreach ($collections as $collection) {
            $key = trim((string) ($collection['collection_key'] ?? ''));
            $id = (int) ($collection['id'] ?? 0);
            if ($key !== '') {
                $collectionKeys[] = $key;
            }
            if ($id > 0) {
                $collectionIds[] = ['value' => $id, 'label' => (string) ($collection['name'] ?? $key ?? $id)];
            }
        }
        $pages = $preloadedPages;
        $entries = $preloadedEntries;
        $forms = $preloadedForms;
        $categories = $preloadedCategories;

        foreach ($blockTypes as &$blockType) {
            $schema = is_array($blockType['schema_definition'] ?? null)
                ? $blockType['schema_definition']
                : $this->decodeJson($blockType['schema_definition'] ?? null);
            if (! is_array($schema)) {
                $schema = [];
            }
            $configFields = array_replace(
                is_array($schema['config_fields'] ?? null) ? $schema['config_fields'] : [],
                is_array($blockType['config_fields'] ?? null) ? $blockType['config_fields'] : [],
            );
            $schema['config_fields'] = $configFields;
            $blockType['config_fields'] = $configFields;
            $blockType['fields'] = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];

            $this->setSelectOptions($configFields, 'collection_key', $collectionKeys);
            $this->setSelectOptions($configFields, 'collection_id', $collectionIds);
            if (isset($configFields['form_key'])) {
                $this->setSelectOptions($configFields, 'form_key', $forms !== [] ? $forms : ['contact']);
            }
            if (isset($configFields['page_id'])) {
                $this->setSelectOptions($configFields, 'page_id', $this->optionRows($pages, ['name', 'title', 'label', 'slug']));
            }
            if (isset($configFields['entry_id'])) {
                $this->setSelectOptions($configFields, 'entry_id', $this->optionRows($entries, ['title', 'name', 'slug']));
            }
            if (isset($configFields['category_id']) && in_array('cms.categories.read', $permissions, true)) {
                $this->setSelectOptions($configFields, 'category_id', $categories);
            }

            foreach ($blockType['fields'] as $fieldKey => &$field) {
                if (! is_array($field)) {
                    continue;
                }
                $fieldType = (string) ($field['type'] ?? '');
                if (! in_array($fieldType, ['entry_reference', 'entry_reference_list'], true)) {
                    continue;
                }
                $allowed = $field['collection_keys'] ?? $field['allowed_collections'] ?? [];
                if (isset($field['collection_key']) && is_string($field['collection_key'])) {
                    $allowed = [$field['collection_key']];
                }
                $field['options'] = [];
                foreach (is_array($allowed) ? $allowed : [] as $collectionKey) {
                    $collectionKey = trim((string) $collectionKey);
                    foreach ($collections as $collection) {
                        if ((string) ($collection['collection_key'] ?? '') !== $collectionKey) {
                            continue;
                        }
                        $collectionEntries = array_values(array_filter(
                            $entries,
                            static fn (array $entry): bool => (int) ($entry['collection_id'] ?? 0) === (int) ($collection['id'] ?? 0),
                        ));
                        foreach ($this->optionRows($collectionEntries, ['title', 'name', 'slug']) as $option) {
                            $field['options'][] = [
                                'value' => $collectionKey . ':' . $option['value'],
                                'label' => $option['label'] . ' · ' . $collectionKey,
                            ];
                        }
                    }
                }
            }
            unset($field);
            $schema['config_fields'] = $configFields;
            $blockType['schema_definition'] = $schema;
        }
        unset($blockType);

        return $blockTypes;
    }

    /** @param array<string, mixed> $fields @param list<mixed> $options */
    private function setSelectOptions(array &$fields, string $key, array $options): void
    {
        if (! isset($fields[$key]) || ! is_array($fields[$key])) {
            return;
        }
        $fields[$key]['type'] = 'select';
        $fields[$key]['options'] = $options;
    }

    /** @param list<array<string, mixed>> $rows @param list<string> $fallbackKeys @return list<array{value: string, label: string}> */
    private function optionRows(array $rows, array $fallbackKeys): array
    {
        $options = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $label = null;
            foreach ($row['translations'] ?? [] as $translation) {
                if (is_array($translation) && trim((string) ($translation['title'] ?? '')) !== '') {
                    $label = (string) $translation['title'];
                    break;
                }
            }
            foreach ($fallbackKeys as $key) {
                if ($label === null && trim((string) ($row[$key] ?? '')) !== '') {
                    $label = (string) $row[$key];
                }
            }
            $options[] = ['value' => (string) $id, 'label' => $label ?? (string) $id];
        }

        return $options;
    }

    /**
     * @param list<array<string, mixed>> $collections
     * @param array<int, array<string, mixed>> $blockTypes
     * @return array<string|int, list<array<string, mixed>>>
     */
    private function listingFieldCatalog(array $collections, array $blockTypes): array
    {
        $base = [
            ['value' => 'entry.title', 'label' => 'Título', 'group' => 'Datos de la entrada', 'type' => 'text', 'sortable' => true, 'filterable' => true],
            ['value' => 'entry.excerpt', 'label' => 'Resumen', 'group' => 'Datos de la entrada', 'type' => 'text', 'sortable' => false, 'filterable' => true],
            ['value' => 'entry.slug', 'label' => 'Slug', 'group' => 'Datos de la entrada', 'type' => 'text', 'sortable' => true, 'filterable' => true],
            ['value' => 'entry.published_at', 'label' => 'Fecha de publicación', 'group' => 'Datos de la entrada', 'type' => 'date', 'sortable' => true, 'filterable' => true],
            ['value' => 'entry.created_at', 'label' => 'Fecha de creación', 'group' => 'Datos de la entrada', 'type' => 'date', 'sortable' => true, 'filterable' => true],
            ['value' => 'taxonomy.categories', 'label' => 'Categorías', 'group' => 'Taxonomía', 'type' => 'taxonomy', 'sortable' => false, 'filterable' => true],
            ['value' => 'taxonomy.tags', 'label' => 'Etiquetas', 'group' => 'Taxonomía', 'type' => 'taxonomy', 'sortable' => false, 'filterable' => true],
        ];
        $catalog = ['event_items' => $base, 'catalog_items' => $base];
        foreach ($collections as $collection) {
            $id = (int) ($collection['id'] ?? 0);
            $key = trim((string) ($collection['collection_key'] ?? ''));
            if ($id > 0) {
                $catalog[$id] = $base;
            }
            if ($key !== '') {
                $catalog[$key] = $base;
            }
        }

        return $catalog;
    }

    /** @param list<array<string, mixed>> $blocks @return array<string, mixed>|null */
    private function findBlock(array $blocks, int $instanceId): ?array
    {
        foreach ($blocks as $block) {
            if ((int) ($block['id'] ?? 0) === $instanceId) {
                return $block;
            }
        }

        return null;
    }

    /** @return list<int> */
    private function fileIds(mixed $value): array
    {
        $ids = [];
        if (! is_array($value)) {
            return $ids;
        }
        if (isset($value['file_id']) && is_numeric($value['file_id']) && (int) $value['file_id'] > 0) {
            $ids[] = (int) $value['file_id'];
        }
        foreach ($value as $child) {
            $ids = array_merge($ids, $this->fileIds($child));
        }

        return array_values(array_unique($ids));
    }

    /** @param array<int, array<string, mixed>> $media @return array<string, mixed> */
    private function hydrateMedia(mixed $value, array $media): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (isset($value['file_id']) && is_numeric($value['file_id'])) {
            $fileId = (int) $value['file_id'];
            $meta = $media[$fileId] ?? [];
            $variants = is_array($meta['variants'] ?? null) ? $meta['variants'] : [];
            $thumb = $variants['thumb']['url'] ?? $variants['sm']['url'] ?? $variants['md']['url'] ?? $meta['url'] ?? null;
            if (is_string($thumb) && $thumb !== '') {
                $value['thumb_url'] = $thumb;
                $value['url'] ??= $meta['url'] ?? null;
            }
        }
        foreach ($value as $key => $child) {
            $value[$key] = $this->hydrateMedia($child, $media);
        }

        return $value;
    }

    private function decodeJson(mixed $value): mixed
    {
        if (is_array($value) || $value === null) {
            return $value ?? [];
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : [];
    }
}
