<?php

declare(strict_types=1);

namespace App\AdminRead\Cms;

use App\PublicRead\Cms\FileUrlResolver;

/**
 * Decodes and types the raw row {@see CmsWorkspaceProjectionQuery} returns:
 * JSON-per-relation columns become typed arrays, block/entry media file ids
 * are resolved in one batched Hub call and hydrated back into their block
 * config/data, and derived fields (owner title/slug, taxonomy names) are
 * computed.
 *
 * Extracted from `AdminCmsWorkspaceSource::hydrateWorkspaceProjection()` and
 * its private helpers as a pure move: no decoding/hydration logic changed.
 */
final class CmsWorkspaceRowHydrator
{
    public function __construct(private readonly FileUrlResolver $fileUrlResolver)
    {
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function hydrate(array $row, string $ownerType): array
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
            $owner['entry_categories_json'],
            $owner['entry_tags_json'],
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
            $owner['categories'] = $this->decodeJsonList($row['entry_categories_json'] ?? null);
            $owner['tags'] = $this->decodeJsonList($row['entry_tags_json'] ?? null);
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
            $collection['enables_categories'] = (bool) ($collection['enables_categories'] ?? false);
            $collection['enables_tags'] = (bool) ($collection['enables_tags'] ?? false);
            $collection['block_template'] = $this->decodeJson($collection['block_template'] ?? null);
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

        $languageCodes = [];
        foreach ($languages as $language) {
            $languageCodes[(int) ($language['id'] ?? 0)] = strtolower((string) ($language['code'] ?? ''));
        }
        foreach ($collections as &$collection) {
            $collection['localized_slugs'] = [];
            foreach ($collection['translations'] ?? [] as $translation) {
                if (! is_array($translation)) {
                    continue;
                }
                $code = $languageCodes[(int) ($translation['language_id'] ?? 0)] ?? '';
                $slug = trim((string) ($translation['slug'] ?? ''));
                if ($code !== '' && $slug !== '') {
                    $collection['localized_slugs'][$code] = $slug;
                }
            }
            $collection['slug'] = (string) (array_values($collection['localized_slugs'])[0] ?? '');
        }
        unset($collection);

        if ($ownerType === 'entry') {
            foreach (['categories', 'tags'] as $taxonomy) {
                foreach ($owner[$taxonomy] ?? [] as &$item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $item['id'] = (int) ($item['id'] ?? 0);
                    $item['collection_id'] = $item['collection_id'] ?? null;
                    $item['translations'] = $this->decodeJsonList($item['translations'] ?? null);
                    $firstTranslation = $item['translations'][0] ?? [];
                    $item['name'] = (string) ($firstTranslation['name'] ?? $item['id']);
                    $item['slug'] = (string) ($firstTranslation['slug'] ?? $item['id']);
                }
                unset($item);
            }
        }

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

    /**
     * @param list<array<string, mixed>> $collections
     * @return array<int, string>
     */
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

    /**
     * Recursively walks an arbitrary block-config/block-data structure,
     * enriching any `file_id` it finds with a resolved thumbnail/url. A
     * non-array `$value` (or one with no `file_id`) passes through
     * unchanged — the return type is genuinely `mixed`, not just an array.
     *
     * @param array<int, array<string, mixed>> $media
     */
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
}
