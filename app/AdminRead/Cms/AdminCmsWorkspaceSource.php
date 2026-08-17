<?php

declare(strict_types=1);

namespace App\AdminRead\Cms;

use App\AdminRead\Contracts\AdminCmsWorkspaceSourceInterface;
use App\AdminRead\Support\ReadOnlyQuery;
use App\PublicRead\Cms\FileUrlResolver;
use CodeIgniter\Cache\CacheInterface;
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
    private const CACHE_TTL = 30;

    /** @param BaseConnection<mixed, mixed> $db */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly FileUrlResolver $fileUrlResolver,
        private readonly CacheInterface $cache,
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

        $owner = $ownerType === 'entry' ? $this->entry($ownerId) : $this->page($ownerId);
        if ($owner === null) {
            throw new RuntimeException('CMS ' . $ownerType . ' not found.');
        }

        $languages = $this->languages();
        $blocks = $this->blocks($ownerType, $ownerId);
        $collections = $this->collections();
        $blockTypes = $this->withDynamicOptions($this->blockTypes(), $collections, $permissions);

        $sections = [
            $ownerType => $owner,
            'pages' => $this->pageOptions(),
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
            $sections['entries'] = $this->entryOptions();
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

    /** @return array<string, mixed>|null */
    private function page(int $pageId): ?array
    {
        $row = ReadOnlyQuery::rows(
            $this->db->table('cms_pages')
                ->select('id, parent_id, collection_id, page_type, status, published_at, scheduled_at, sort_order, sitemap_priority, sitemap_changefreq, is_in_sitemap, created_at, updated_at')
                ->where('id', $pageId)
                ->where('deleted_at', null)
                ->limit(1),
            'CMS admin page workspace',
        )[0] ?? null;

        if ($row === null) {
            return null;
        }

        $translations = ReadOnlyQuery::rows(
            $this->db->table('cms_page_translations')
                ->select('id, page_id, language_id, slug, title, excerpt, meta_title, meta_description, og_image_file_id, og_image_url, og_type, canonical_url, robots, schema_data, created_at, updated_at')
                ->where('page_id', $pageId)
                ->orderBy('language_id', 'ASC'),
            'CMS admin page translations',
        );
        foreach ($translations as &$translation) {
            $translation['schema_data'] = $this->decodeJson($translation['schema_data'] ?? null);
        }
        unset($translation);

        $row['id'] = (int) $row['id'];
        $row['parent_id'] = $row['parent_id'] === null ? null : (int) $row['parent_id'];
        $row['collection_id'] = $row['collection_id'] === null ? null : (int) $row['collection_id'];
        $row['is_in_sitemap'] = (bool) $row['is_in_sitemap'];
        $row['translations'] = $translations;

        $default = $translations[0] ?? [];
        $row['title'] = (string) ($default['title'] ?? '');
        $row['slug'] = (string) ($default['slug'] ?? '');

        return $row;
    }

    /** @return array<string, mixed>|null */
    private function entry(int $entryId): ?array
    {
        $row = ReadOnlyQuery::rows(
            $this->db->table('cms_entries')
                ->select('id, collection_id, author_id, workflow_status, published_at, scheduled_at, is_featured, view_count, sort_order, wizard_extra, sitemap_priority, sitemap_changefreq, is_in_sitemap, created_at, updated_at')
                ->where('id', $entryId)
                ->where('deleted_at', null)
                ->limit(1),
            'CMS admin entry workspace',
        )[0] ?? null;

        if ($row === null) {
            return null;
        }

        $translations = ReadOnlyQuery::rows(
            $this->db->table('cms_entry_translations')
                ->select('id, entry_id, language_id, slug, title, excerpt, featured_file_id, featured_image_url, meta_title, meta_description, og_image_file_id, og_type, canonical_url, robots, schema_data, created_at, updated_at')
                ->where('entry_id', $entryId)
                ->orderBy('language_id', 'ASC'),
            'CMS admin entry translations',
        );
        foreach ($translations as &$translation) {
            $translation['schema_data'] = $this->decodeJson($translation['schema_data'] ?? null);
        }
        unset($translation);

        foreach (['id', 'collection_id', 'author_id', 'view_count', 'sort_order'] as $field) {
            if ($row[$field] !== null) {
                $row[$field] = (int) $row[$field];
            }
        }
        $row['is_featured'] = (bool) $row['is_featured'];
        $row['is_in_sitemap'] = (bool) $row['is_in_sitemap'];
        $row['wizard_extra'] = $this->decodeJson($row['wizard_extra'] ?? null);
        $row['translations'] = $translations;
        $default = $translations[0] ?? [];
        $row['title'] = (string) ($default['title'] ?? '');
        $row['slug'] = (string) ($default['slug'] ?? '');
        $row['excerpt'] = (string) ($default['excerpt'] ?? '');

        return $row;
    }

    /** @return list<array<string, mixed>> */
    private function languages(): array
    {
        $cached = $this->cache->get('admin_cms_workspace_languages');
        if (is_array($cached)) {
            return $cached;
        }

        $rows = ReadOnlyQuery::rows(
            $this->db->table('cms_languages')
                ->select('id, code, name, native_name, is_default, is_active, fallback_language_id, sort_order')
                ->where('is_active', 1)
                ->orderBy('sort_order', 'ASC')
                ->orderBy('id', 'ASC'),
            'CMS admin languages',
        );
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['is_default'] = (bool) $row['is_default'];
            $row['is_active'] = (bool) $row['is_active'];
        }
        unset($row);
        $this->cache->save('admin_cms_workspace_languages', $rows, self::CACHE_TTL);

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function blocks(string $ownerType, int $ownerId): array
    {
        $instances = ReadOnlyQuery::rows(
            $this->db->table('cms_block_instances i')
                ->select('i.id, i.block_id, i.owner_type, i.owner_id, i.parent_instance_id, i.sort_order, i.column_index, i.is_active, i.block_config, i.created_at, i.updated_at')
                ->where('i.owner_type', $ownerType)
                ->where('i.owner_id', $ownerId)
                ->orderBy('i.sort_order', 'ASC')
                ->orderBy('i.id', 'ASC'),
            'CMS admin ' . $ownerType . ' blocks',
        );

        if ($instances === []) {
            return [];
        }

        $ids = array_values(array_map(static fn (array $row): int => (int) $row['id'], $instances));
        $translations = ReadOnlyQuery::rows(
            $this->db->table('cms_block_instance_translations')
                ->select('id, instance_id, language_id, block_data, is_published, created_at, updated_at')
                ->whereIn('instance_id', $ids)
                ->orderBy('language_id', 'ASC'),
            'CMS admin block translations',
        );
        $translationsByInstance = [];
        foreach ($translations as $translation) {
            $translation['language_id'] = (int) $translation['language_id'];
            $translation['is_published'] = (bool) $translation['is_published'];
            $translation['block_data'] = $this->decodeJson($translation['block_data'] ?? null);
            $translationsByInstance[(int) $translation['instance_id']][] = $translation;
        }

        $fileIds = [];
        foreach ($instances as &$instance) {
            $instance['id'] = (int) $instance['id'];
            $instance['block_id'] = (int) $instance['block_id'];
            $instance['owner_id'] = (int) $instance['owner_id'];
            $instance['parent_instance_id'] = $instance['parent_instance_id'] === null ? null : (int) $instance['parent_instance_id'];
            $instance['sort_order'] = (int) $instance['sort_order'];
            $instance['column_index'] = $instance['column_index'] === null ? null : (int) $instance['column_index'];
            $instance['is_active'] = (bool) $instance['is_active'];
            $instance['block_config'] = $this->decodeJson($instance['block_config'] ?? null);
            $instance['translations'] = $translationsByInstance[$instance['id']] ?? [];
            $fileIds = array_merge($fileIds, $this->fileIds($instance['block_config']));
            foreach ($instance['translations'] as $translation) {
                $fileIds = array_merge($fileIds, $this->fileIds($translation['block_data'] ?? []));
            }
        }
        unset($instance);

        $media = $this->fileUrlResolver->resolveManyMeta(array_values(array_unique($fileIds)), 'admin');
        foreach ($instances as &$instance) {
            $instance['block_config'] = $this->hydrateMedia($instance['block_config'], $media);
            foreach ($instance['translations'] as &$translation) {
                $translation['block_data'] = $this->hydrateMedia($translation['block_data'], $media);
            }
            unset($translation);
        }
        unset($instance);

        return $instances;
    }

    /** @return array<int, array<string, mixed>> */
    private function blockTypes(): array
    {
        $cached = $this->cache->get('admin_cms_workspace_block_types');
        if (is_array($cached)) {
            return $cached;
        }

        $rows = ReadOnlyQuery::rows(
            $this->db->table('cms_content_blocks')
                ->select('id, block_key, name, description, category, icon, schema_definition, supports_pages, supports_entries, is_container, is_active, sort_order')
                ->where('is_active', 1)
                ->orderBy('sort_order', 'ASC')
                ->orderBy('name', 'ASC')
                ->orderBy('id', 'ASC'),
            'CMS admin block types',
        );
        $indexed = [];
        foreach ($rows as $row) {
            $schema = $this->decodeJson($row['schema_definition'] ?? null);
            $row['id'] = (int) $row['id'];
            $row['schema_definition'] = $schema;
            $row['fields'] = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];
            $row['config_fields'] = is_array($schema['config_fields'] ?? null) ? $schema['config_fields'] : [];
            $row['supports_pages'] = (bool) $row['supports_pages'];
            $row['supports_entries'] = (bool) $row['supports_entries'];
            $row['is_container'] = (bool) $row['is_container'];
            $row['is_active'] = (bool) $row['is_active'];
            $indexed[$row['id']] = $row;
        }
        $this->cache->save('admin_cms_workspace_block_types', $indexed, self::CACHE_TTL);

        return $indexed;
    }

    /** @return list<array<string, mixed>> */
    private function pageOptions(): array
    {
        $cached = $this->cache->get('admin_cms_workspace_pages');
        if (is_array($cached)) {
            return $cached;
        }

        $pages = ReadOnlyQuery::rows(
            $this->db->table('cms_pages')
                ->select('id, parent_id, collection_id, page_type, status, sort_order, created_at, updated_at')
                ->where('deleted_at', null)
                ->orderBy('sort_order', 'ASC')
                ->orderBy('id', 'ASC')
                ->limit(250),
            'CMS admin page options',
        );
        $ids = array_values(array_map(static fn (array $row): int => (int) $row['id'], $pages));
        $translations = $ids === [] ? [] : ReadOnlyQuery::rows(
            $this->db->table('cms_page_translations')
                ->select('page_id, language_id, slug, title')
                ->whereIn('page_id', $ids)
                ->orderBy('language_id', 'ASC'),
            'CMS admin page option translations',
        );
        $byPage = [];
        foreach ($translations as $translation) {
            $byPage[(int) $translation['page_id']][] = $translation;
        }
        foreach ($pages as &$page) {
            $page['id'] = (int) $page['id'];
            $page['translations'] = $byPage[$page['id']] ?? [];
        }
        unset($page);
        $this->cache->save('admin_cms_workspace_pages', $pages, self::CACHE_TTL);

        return $pages;
    }

    /** @return list<array<string, mixed>> */
    private function collections(): array
    {
        $cached = $this->cache->get('admin_cms_workspace_collections');
        if (is_array($cached)) {
            return $cached;
        }

        $collections = ReadOnlyQuery::rows(
            $this->db->table('cms_collections')
                ->select('id, collection_key, collection_type, is_active, sort_order')
                ->where('is_active', 1)
                ->orderBy('sort_order', 'ASC')
                ->orderBy('id', 'ASC')
                ->limit(250),
            'CMS admin collection options',
        );
        $ids = array_values(array_map(static fn (array $row): int => (int) $row['id'], $collections));
        $translations = $ids === [] ? [] : ReadOnlyQuery::rows(
            $this->db->table('cms_collection_translations')
                ->select('collection_id, language_id, name, slug')
                ->whereIn('collection_id', $ids)
                ->orderBy('language_id', 'ASC'),
            'CMS admin collection option translations',
        );
        $byCollection = [];
        foreach ($translations as $translation) {
            $byCollection[(int) $translation['collection_id']][] = $translation;
        }
        foreach ($collections as &$collection) {
            $collection['id'] = (int) $collection['id'];
            $collection['translations'] = $byCollection[$collection['id']] ?? [];
            $collection['name'] = (string) ($collection['translations'][0]['name'] ?? $collection['collection_key']);
        }
        unset($collection);
        $this->cache->save('admin_cms_workspace_collections', $collections, self::CACHE_TTL);

        return $collections;
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
     * @return array<int, array<string, mixed>>
     */
    private function withDynamicOptions(array $blockTypes, array $collections, array $permissions): array
    {
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
        $pages = null;
        $entries = null;
        $forms = null;
        $categories = null;

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
                $forms ??= $this->formOptions();
                $this->setSelectOptions($configFields, 'form_key', $forms !== [] ? $forms : ['contact']);
            }
            if (isset($configFields['page_id'])) {
                $pages ??= $this->optionRows($this->pageOptions(), ['name', 'title', 'label', 'slug']);
                $this->setSelectOptions($configFields, 'page_id', $pages);
            }
            if (isset($configFields['entry_id'])) {
                $entries ??= $this->entryOptions();
                $this->setSelectOptions($configFields, 'entry_id', $entries);
            }
            if (isset($configFields['category_id']) && in_array('cms.categories.read', $permissions, true)) {
                $categories ??= $this->categoryOptions($collections);
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
                        foreach ($this->entryOptions((int) ($collection['id'] ?? 0)) as $option) {
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

    /** @return list<string> */
    private function formOptions(): array
    {
        $rows = ReadOnlyQuery::rows(
            $this->db->table('cms_forms')
                ->select('form_key')
                ->where('is_active', 1)
                ->orderBy('form_key', 'ASC')
                ->limit(100),
            'CMS admin form options',
        );

        return array_values(array_filter(array_map(
            static fn (array $row): string => trim((string) ($row['form_key'] ?? '')),
            $rows,
        ), static fn (string $key): bool => $key !== ''));
    }

    /** @param list<array<string, mixed>> $collections @return list<array{value: string, label: string}> */
    private function categoryOptions(array $collections): array
    {
        $names = [];
        foreach ($collections as $collection) {
            $names[(int) ($collection['id'] ?? 0)] = (string) ($collection['name'] ?? $collection['collection_key'] ?? 'Colección');
        }
        $rows = ReadOnlyQuery::rows(
            $this->db->table('cms_categories c')
                ->select('c.id, c.collection_id, t.name')
                ->join('cms_category_translations t', 't.category_id = c.id', 'left')
                ->where('c.is_active', 1)
                ->orderBy('c.sort_order', 'ASC')
                ->orderBy('c.id', 'ASC')
                ->limit(500),
            'CMS admin category options',
        );

        return array_values(array_map(static function (array $row) use ($names): array {
            $id = (int) ($row['id'] ?? 0);
            $collection = $names[(int) ($row['collection_id'] ?? 0)] ?? 'Colección';
            return ['value' => (string) $id, 'label' => $collection . ' · ' . (string) ($row['name'] ?? $id)];
        }, $rows));
    }

    /** @return list<array<string, mixed>> */
    private function entryOptions(?int $collectionId = null): array
    {
        $query = $this->db->table('cms_entries e')
            ->select('e.id, e.collection_id, e.workflow_status, e.published_at, e.sort_order, e.created_at, e.updated_at')
            ->where('e.deleted_at', null)
            ->orderBy('e.sort_order', 'ASC')
            ->orderBy('e.id', 'ASC')
            ->limit(250);
        if ($collectionId !== null && $collectionId > 0) {
            $query->where('e.collection_id', $collectionId);
        }
        $entries = ReadOnlyQuery::rows($query, 'CMS admin entry options');
        if ($entries === []) {
            return [];
        }
        $ids = array_values(array_map(static fn (array $row): int => (int) $row['id'], $entries));
        $translations = ReadOnlyQuery::rows(
            $this->db->table('cms_entry_translations')
                ->select('entry_id, language_id, slug, title')
                ->whereIn('entry_id', $ids)
                ->orderBy('language_id', 'ASC'),
            'CMS admin entry option translations',
        );
        $byEntry = [];
        foreach ($translations as $translation) {
            $byEntry[(int) $translation['entry_id']][] = $translation;
        }
        foreach ($entries as &$entry) {
            $entry['id'] = (int) $entry['id'];
            $entry['translations'] = $byEntry[$entry['id']] ?? [];
            $entry['title'] = (string) ($entry['translations'][0]['title'] ?? $entry['translations'][0]['slug'] ?? $entry['id']);
        }
        unset($entry);

        return $entries;
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
