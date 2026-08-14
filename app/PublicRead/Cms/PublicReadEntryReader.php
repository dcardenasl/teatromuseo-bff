<?php

declare(strict_types=1);

namespace App\PublicRead\Cms;

use App\PublicRead\Support\PublicReadEnvelope;
use CodeIgniter\Database\BaseConnection;
use dcardenasl\Ci4ApiCore\Support\ApiResult;

/**
 * SQL-first CMS entry projection. It intentionally has no model/service
 * dependencies so the same read model can run inside the BFF.
 */
final class PublicReadEntryReader
{
    /** @param BaseConnection<mixed, mixed> $db */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly FileUrlResolver $fileUrlResolver,
        private readonly BlockInstanceSerializer $blockSerializer,
        private readonly string $fallbackLocale = 'es',
    ) {
    }

    /** @param list<string> $fields */
    public function index(PublicReadEntryRequestDTO $request, array $fields): ApiResult
    {
        [$languageId, $defaultLanguageId, $languageCodes] = $this->languageIds($request->locale);
        $collectionQuery = $this->db->table('cms_collections')->select('id')->where('collection_key', $request->collection)->where('is_active', 1)->get();
        $collection = $collectionQuery !== false ? $collectionQuery->getRowArray() : null;
        if (! is_array($collection)) {
            return $this->notFound($request->locale, 'Collection not found.');
        }
        $builder = $this->publicEntriesBuilder((int) $collection['id'], $request, $languageId, $defaultLanguageId);
        $countBuilder = clone $builder;
        $total = (int) $countBuilder->countAllResults();
        $order = $request->orderBy === 'title' ? 'et_order.title' : 'e.' . $request->orderBy;
        $builder->select('e.id, e.collection_id, e.author_id, e.workflow_status, e.published_at, e.scheduled_at, e.is_featured, e.view_count, e.sort_order, e.sitemap_priority, e.sitemap_changefreq, e.is_in_sitemap, e.created_at, e.updated_at')
            ->orderBy($order, $request->orderDirection)->orderBy('e.id', 'ASC')
            ->limit($request->perPage, ($request->page - 1) * $request->perPage);
        $query = $builder->get();
        $rows = $query !== false ? array_values($query->getResultArray()) : [];
        $data = $this->hydrate($rows, $request->locale, $languageId, $defaultLanguageId, $languageCodes, $fields);

        return PublicReadEnvelope::success(
            locale: $request->locale,
            data: $data,
            sourceRevision: $this->revision($rows),
            domain: 'cms',
            page: $request->page,
            perPage: $request->perPage,
            total: $total,
            meta: ['fields' => $fields, 'collection' => $request->collection, 'query' => $request->toArray()],
        );
    }

    /** @param list<string> $fields */
    public function show(string $locale, string $collectionKey, string $slug, array $fields): ApiResult
    {
        [$languageId, $defaultLanguageId, $languageCodes] = $this->languageIds($locale);
        $collectionQuery = $this->db->table('cms_collections')->select('id')->where('collection_key', $collectionKey)->where('is_active', 1)->get();
        $collection = $collectionQuery !== false ? $collectionQuery->getRowArray() : null;
        if (! is_array($collection)) {
            return $this->notFound($locale, 'Collection not found.');
        }
        $builder = $this->db->table('cms_entries e')->select('e.id, e.collection_id, e.author_id, e.workflow_status, e.published_at, e.scheduled_at, e.is_featured, e.view_count, e.sort_order, e.sitemap_priority, e.sitemap_changefreq, e.is_in_sitemap, e.created_at, e.updated_at')
            ->join('cms_entry_translations et_lookup', 'et_lookup.entry_id = e.id')
            ->where('et_lookup.slug', trim($slug, '/'))->whereIn('et_lookup.language_id', array_values(array_filter([$languageId, $defaultLanguageId])))
            ->where('e.collection_id', (int) $collection['id'])->where('e.workflow_status', 'published')->where('e.deleted_at', null)
            ->groupStart()->where('e.published_at', null)->orWhere('e.published_at <=', date('Y-m-d H:i:s'))->groupEnd()
            ->groupStart()->where('e.scheduled_at', null)->orWhere('e.scheduled_at <=', date('Y-m-d H:i:s'))->groupEnd()
            ->orderBy('et_lookup.language_id', 'ASC')->limit(1);
        $query = $builder->get();
        $rows = $query !== false ? array_values($query->getResultArray()) : [];
        if ($rows === []) {
            return $this->notFound($locale, 'Entry not found.');
        }
        $data = $this->hydrate($rows, $locale, $languageId, $defaultLanguageId, $languageCodes, $fields, true);

        return PublicReadEnvelope::success(locale: $locale, data: $data[0] ?? [], sourceRevision: $this->revision($rows), domain: 'cms', meta: ['fields' => $fields, 'collection' => $collectionKey, 'query' => ['slug' => $slug]]);
    }

    /** @return \CodeIgniter\Database\BaseBuilder */
    private function publicEntriesBuilder(int $collectionId, PublicReadEntryRequestDTO $request, int $languageId, int $defaultLanguageId): \CodeIgniter\Database\BaseBuilder
    {
        $builder = $this->db->table('cms_entries e')
            ->join('(SELECT entry_id, COALESCE(MAX(CASE WHEN language_id = ' . $languageId . ' THEN title END), MAX(CASE WHEN language_id = ' . $defaultLanguageId . ' THEN title END)) AS title FROM cms_entry_translations WHERE language_id IN (' . $languageId . ', ' . $defaultLanguageId . ') GROUP BY entry_id) et_order', 'et_order.entry_id = e.id', 'left')
            ->where('e.collection_id', $collectionId)->where('e.workflow_status', 'published')->where('e.deleted_at', null)
            ->groupStart()->where('e.published_at', null)->orWhere('e.published_at <=', date('Y-m-d H:i:s'))->groupEnd()
            ->groupStart()->where('e.scheduled_at', null)->orWhere('e.scheduled_at <=', date('Y-m-d H:i:s'))->groupEnd();
        if ($request->categoryId !== null) {
            $builder->where('EXISTS (SELECT 1 FROM cms_entry_categories ec WHERE ec.entry_id = e.id AND ec.category_id = ' . (int) $request->categoryId . ')', null, false);
        }
        if ($request->category !== null) {
            $builder->where('EXISTS (SELECT 1 FROM cms_entry_categories ec JOIN cms_category_translations ct ON ct.category_id = ec.category_id WHERE ec.entry_id = e.id AND ct.slug = ' . $this->db->escape($request->category) . ' AND ct.language_id IN (' . $languageId . ', ' . $defaultLanguageId . '))', null, false);
        }
        if ($request->tag !== null) {
            $builder->where('EXISTS (SELECT 1 FROM cms_entry_tags et JOIN cms_tag_translations tt ON tt.tag_id = et.tag_id WHERE et.entry_id = e.id AND tt.slug = ' . $this->db->escape($request->tag) . ' AND tt.language_id IN (' . $languageId . ', ' . $defaultLanguageId . '))', null, false);
        }
        if ($request->search !== null) {
            $needle = $this->db->escape('%' . $request->search . '%');
            $condition = 'EXISTS (SELECT 1 FROM cms_entry_translations es WHERE es.entry_id = e.id AND es.language_id IN ('
                . $languageId . ', ' . $defaultLanguageId . ') AND (es.title LIKE ' . $needle . ' OR es.excerpt LIKE ' . $needle . '))';
            $builder->where($condition, null, false);
        }

        return $builder;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<int, string> $languageCodes
     * @param list<string> $fields
     * @return list<array<string, mixed>>
     */
    private function hydrate(array $rows, string $locale, int $languageId, int $defaultLanguageId, array $languageCodes, array $fields, bool $includeBlocks = false): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_values(array_map(static fn (array $row): int => (int) $row['id'], $rows));
        $query = $this->db->table('cms_entry_translations')->select('entry_id, language_id, slug, title, excerpt, featured_file_id, featured_image_url, meta_title, meta_description, og_image_file_id, og_type, canonical_url, robots, schema_data')->whereIn('entry_id', $ids)->get();
        $translationRows = $query !== false ? $query->getResultArray() : [];
        $fileIds = [];
        foreach ($translationRows as $translation) {
            foreach (['featured_file_id', 'og_image_file_id'] as $key) {
                if ((int) ($translation[$key] ?? 0) > 0) {
                    $fileIds[] = (int) $translation[$key];
                }
            }
        }
        $media = $this->fileUrlResolver->resolveManyMeta(array_values(array_unique($fileIds)), 'public');
        $byEntry = [];
        foreach ($translationRows as $translation) {
            $entryId = (int) $translation['entry_id'];
            $normalized = $this->fileUrlResolver->normalizeEntryTranslation($translation, 'public', $media);
            $byEntry[$entryId][(int) $translation['language_id']] = $normalized;
        }
        $categories = $this->taxonomy($ids, 'category');
        $tags = $this->taxonomy($ids, 'tag');
        $result = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $translations = $byEntry[$id] ?? [];
            if ($translations === []) {
                continue;
            }
            $selectedId = isset($translations[$languageId]) ? $languageId : (isset($translations[$defaultLanguageId]) ? $defaultLanguageId : (int) array_key_first($translations));
            $selected = $translations[$selectedId];
            $localizedSlugs = [];
            foreach ($translations as $translationId => $translation) {
                if (($languageCodes[$translationId] ?? '') !== '' && ($translation['slug'] ?? '') !== '') {
                    $localizedSlugs[$languageCodes[$translationId]] = $translation['slug'];
                }
            }
            $item = array_merge($row, $selected, ['categories' => $categories[$id] ?? [], 'tags' => $tags[$id] ?? [], 'localized_slugs' => $localizedSlugs, 'is_fallback' => $selectedId !== $languageId]);
            if ($includeBlocks || $fields === [] || in_array('blocks', $fields, true)) {
                $item['blocks'] = $this->blockSerializer->forContent('entry', $id, $locale);
            }
            $result[] = $fields === [] ? $item : array_intersect_key($item, array_flip($fields));
        }

        return $result;
    }

    /**
     * @param list<int> $ids
     * @return array<int, list<array<string, mixed>>>
     */
    private function taxonomy(array $ids, string $type): array
    {
        $pivot = $type === 'category' ? 'cms_entry_categories' : 'cms_entry_tags';
        $idColumn = $type . '_id';
        $translationTable = 'cms_' . $type . '_translations';
        $query = $this->db->table($pivot . ' p')->select('p.entry_id, t.id, t.' . $idColumn . ', t.slug, t.name')->join($translationTable . ' t', 't.' . $idColumn . ' = p.' . $idColumn)->whereIn('p.entry_id', $ids)->orderBy('t.id', 'ASC')->get();
        $result = [];
        foreach (($query !== false ? $query->getResultArray() : []) as $row) {
            $result[(int) $row['entry_id']][] = ['id' => (int) $row['id'], 'slug' => (string) $row['slug'], 'name' => (string) $row['name']];
        }

        return $result;
    }

    /** @return array{0: int, 1: int, 2: array<int, string>} */
    private function languageIds(string $locale): array
    {
        $query = $this->db->table('cms_languages')->select('id, code, is_default')->where('is_active', 1)->get();
        $rows = $query !== false ? $query->getResultArray() : [];
        $languageId = 0;
        $defaultId = 0;
        $fallbackId = 0;
        $codes = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $code = strtolower((string) $row['code']);
            $codes[$id] = $code;
            if ($code === strtolower($locale)) {
                $languageId = $id;
            }
            if ($code === strtolower($this->fallbackLocale)) {
                $fallbackId = $id;
            }
            if ((int) $row['is_default'] === 1) {
                $defaultId = $id;
            }
        }
        $languageId = $languageId > 0 ? $languageId : $fallbackId;
        $languageId = $languageId > 0 ? $languageId : $defaultId;
        $defaultId = $defaultId > 0 ? $defaultId : $languageId;

        return [$languageId, $defaultId, $codes];
    }

    /** @param list<array<string, mixed>> $rows */
    private function revision(array $rows): string
    {
        $latest = '';
        $maxId = 0;
        foreach ($rows as $row) {
            $latest = max($latest, (string) ($row['updated_at'] ?? ''));
            $maxId = max($maxId, (int) ($row['id'] ?? 0));
        }

        return 'cms-entries:' . ($latest !== '' ? $latest : 'empty') . ':' . $maxId;
    }

    private function notFound(string $locale, string $message): ApiResult
    {
        return new ApiResult(['version' => 1, 'ok' => false, 'data' => null, 'meta' => ['locale' => $locale, 'source_revision' => 'cms:empty'], 'source' => ['domain' => 'cms', 'state' => 'unavailable', 'stale' => false], 'messages' => [$message]], 404);
    }
}
