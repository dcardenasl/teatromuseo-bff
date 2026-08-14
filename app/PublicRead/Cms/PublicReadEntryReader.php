<?php

declare(strict_types=1);

namespace App\PublicRead\Cms;

use App\PublicRead\Page\EntryReaderInterface;
use App\PublicRead\Support\PublicReadEnvelope;
use CodeIgniter\Database\BaseConnection;
use dcardenasl\Ci4ApiCore\Support\ApiResult;

/**
 * SQL-first CMS entry projection. It intentionally has no model/service
 * dependencies so the same read model can run inside the BFF.
 */
final class PublicReadEntryReader implements EntryReaderInterface
{
    private const ENTRY_DIRECT_COLUMNS = ['published_at', 'created_at', 'sort_order'];

    private const ENTRY_TRANSLATION_FIELDS = [
        'slug', 'title', 'excerpt', 'meta_title', 'meta_description',
        'canonical_url', 'robots', 'og_type', 'featured_image_url',
    ];

    /** @param BaseConnection<mixed, mixed> $db */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly FileUrlResolver $fileUrlResolver,
        private readonly BlockInstanceSerializer $blockSerializer,
        private readonly EntryListingContentResolver $listingContentResolver,
        private readonly string $fallbackLocale = 'es',
    ) {
    }

    /** @param list<string> $fields */
    public function index(PublicReadEntryRequestDTO $request, array $fields, bool $preview = false): ApiResult
    {
        [$languageId, $defaultLanguageId, $languageCodes] = $this->languageIds($request->locale);
        $collectionQuery = $this->db->table('cms_collections')->select('id')->where('collection_key', $request->collection)->where('is_active', 1)->get();
        $collection = $collectionQuery !== false ? $collectionQuery->getRowArray() : null;
        if (! is_array($collection)) {
            return $this->notFound($request->locale, 'Collection not found.');
        }
        $builder = $this->publicEntriesBuilder((int) $collection['id'], $request->toArray(), $languageId, $defaultLanguageId, $preview);
        $countBuilder = clone $builder;
        $total = (int) $countBuilder->countAllResults();
        $builder->select('e.id, e.collection_id, e.author_id, e.workflow_status, e.published_at, e.scheduled_at, e.is_featured, e.view_count, e.sort_order, e.sitemap_priority, e.sitemap_changefreq, e.is_in_sitemap, e.created_at, e.updated_at');
        $this->applyOrdering($builder, $request, $languageId, $defaultLanguageId);
        $builder->orderBy('e.id', 'ASC')
            ->limit($request->perPage, ($request->page - 1) * $request->perPage);
        $query = $builder->get();
        $rows = $query !== false ? array_values($query->getResultArray()) : [];
        $data = $this->hydrate($rows, $request->locale, $languageId, $defaultLanguageId, $languageCodes, $fields, false, $request);

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
    public function show(string $locale, string $collectionKey, string $slug, array $fields, bool $preview = false): ApiResult
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
            ->where('e.collection_id', (int) $collection['id'])->where('e.deleted_at', null)
            ->orderBy('et_lookup.language_id', 'ASC')->limit(1);
        if (! $preview) {
            $builder->where('e.workflow_status', 'published')
                ->groupStart()->where('e.published_at', null)->orWhere('e.published_at <=', date('Y-m-d H:i:s'))->groupEnd()
                ->groupStart()->where('e.scheduled_at', null)->orWhere('e.scheduled_at <=', date('Y-m-d H:i:s'))->groupEnd();
        }
        $query = $builder->get();
        $rows = $query !== false ? array_values($query->getResultArray()) : [];
        if ($rows === []) {
            return $this->notFound($locale, 'Entry not found.');
        }
        $data = $this->hydrate($rows, $locale, $languageId, $defaultLanguageId, $languageCodes, $fields, true);

        return PublicReadEnvelope::success(locale: $locale, data: $data[0] ?? [], sourceRevision: $this->revision($rows), domain: 'cms', meta: ['fields' => $fields, 'collection' => $collectionKey, 'query' => ['slug' => $slug]]);
    }

    /**
     * Return related entries with the same bounded two-pass algorithm as Web:
     * category candidates first, then a generic collection fill with stable
     * de-duplication and self-exclusion.
     *
     * @param array<string, mixed> $entry
     * @return list<array<string, mixed>>
     */
    public function related(string $locale, string $collectionKey, array $entry, int $limit = 3, bool $preview = false): array
    {
        $limit = max(0, $limit);
        if ($limit === 0) {
            return [];
        }

        $currentSlug = (string) ($entry['slug'] ?? '');
        $categories = is_array($entry['categories'] ?? null) ? $entry['categories'] : [];
        $categorySlug = is_array($categories[0] ?? null) ? (string) ($categories[0]['slug'] ?? '') : '';
        $fields = ['id', 'slug', 'title', 'excerpt', 'published_at', 'featured_image', 'categories', 'localized'];

        $related = [];
        if ($categorySlug !== '') {
            $related = $this->relatedList($locale, $collectionKey, $fields, $limit + 1, $categorySlug, $preview);
            $related = array_values(array_filter(
                $related,
                static fn (array $candidate): bool => (string) ($candidate['slug'] ?? '') !== $currentSlug,
            ));
            usort($related, static function (array $left, array $right) use ($categorySlug): int {
                $leftMatch = self::hasCategory($left, $categorySlug) ? 0 : 1;
                $rightMatch = self::hasCategory($right, $categorySlug) ? 0 : 1;

                return $leftMatch <=> $rightMatch;
            });
        }

        if (count($related) < $limit) {
            $candidates = $this->relatedList($locale, $collectionKey, $fields, $limit + 1, null, $preview);
            $knownSlugs = array_fill_keys(array_map(
                static fn (array $candidate): string => (string) ($candidate['slug'] ?? ''),
                $related,
            ), true);
            foreach ($candidates as $candidate) {
                $slug = (string) ($candidate['slug'] ?? '');
                if ($slug === $currentSlug || ($slug !== '' && isset($knownSlugs[$slug]))) {
                    continue;
                }

                $related[] = $candidate;
                if ($slug !== '') {
                    $knownSlugs[$slug] = true;
                }
                if (count($related) >= $limit) {
                    break;
                }
            }
        }

        return array_slice($related, 0, $limit);
    }

    /** @return \CodeIgniter\Database\BaseBuilder */
    /** @param array<string, mixed> $request */
    private function publicEntriesBuilder(int $collectionId, array $request, int $languageId, int $defaultLanguageId, bool $preview = false): \CodeIgniter\Database\BaseBuilder
    {
        $builder = $this->db->table('cms_entries e')
            ->join('(SELECT entry_id, COALESCE(MAX(CASE WHEN language_id = ' . $languageId . ' THEN title END), MAX(CASE WHEN language_id = ' . $defaultLanguageId . ' THEN title END)) AS title FROM cms_entry_translations WHERE language_id IN (' . $languageId . ', ' . $defaultLanguageId . ') GROUP BY entry_id) et_order', 'et_order.entry_id = e.id', 'left')
            ->where('e.collection_id', $collectionId)->where('e.deleted_at', null);
        if (! $preview) {
            $builder->where('e.workflow_status', 'published')
                ->groupStart()->where('e.published_at', null)->orWhere('e.published_at <=', date('Y-m-d H:i:s'))->groupEnd()
                ->groupStart()->where('e.scheduled_at', null)->orWhere('e.scheduled_at <=', date('Y-m-d H:i:s'))->groupEnd();
        }
        $categoryId = $request['category_id'] ?? null;
        if ($categoryId !== null && $categoryId !== '') {
            $builder->where('EXISTS (SELECT 1 FROM cms_entry_categories ec WHERE ec.entry_id = e.id AND ec.category_id = ' . (int) $categoryId . ')', null, false);
        }
        $category = $request['category'] ?? null;
        if ($category !== null && $category !== '') {
            $builder->where('EXISTS (SELECT 1 FROM cms_entry_categories ec JOIN cms_category_translations ct ON ct.category_id = ec.category_id WHERE ec.entry_id = e.id AND ct.slug = ' . $this->db->escape((string) $category) . ' AND ct.language_id IN (' . $languageId . ', ' . $defaultLanguageId . '))', null, false);
        }
        $tag = $request['tag'] ?? null;
        if ($tag !== null && $tag !== '') {
            $builder->where('EXISTS (SELECT 1 FROM cms_entry_tags et JOIN cms_tag_translations tt ON tt.tag_id = et.tag_id WHERE et.entry_id = e.id AND tt.slug = ' . $this->db->escape((string) $tag) . ' AND tt.language_id IN (' . $languageId . ', ' . $defaultLanguageId . '))', null, false);
        }
        $search = $request['q'] ?? null;
        if ($search !== null && $search !== '') {
            $needle = $this->db->escape('%' . (string) $search . '%');
            $condition = 'EXISTS (SELECT 1 FROM cms_entry_translations es WHERE es.entry_id = e.id AND es.language_id IN ('
                . $languageId . ', ' . $defaultLanguageId . ') AND (es.title LIKE ' . $needle . ' OR es.excerpt LIKE ' . $needle . '))';
            $builder->where($condition, null, false);
        }
        $filterBy = $request['filter_by'] ?? null;
        $filterValue = $request['filter_value'] ?? null;
        if ($filterBy !== null && $filterValue !== null) {
            $field = $this->classifyField((string) $filterBy);
            $this->applyFieldFilter(
                $builder,
                $field,
                (string) $filterBy,
                $languageId,
                $defaultLanguageId,
                (string) ($request['filter_operator'] ?? 'equals'),
                (string) $filterValue,
            );
        }

        return $builder;
    }

    /**
     * @param list<string> $fields
     * @return list<array<string, mixed>>
     */
    private function relatedList(string $locale, string $collectionKey, array $fields, int $limit, ?string $category = null, bool $preview = false): array
    {
        [$languageId, $defaultLanguageId, $languageCodes] = $this->languageIds($locale);
        $collectionQuery = $this->db->table('cms_collections')->select('id')->where('collection_key', $collectionKey)->where('is_active', 1)->get();
        $collection = $collectionQuery !== false ? $collectionQuery->getRowArray() : null;
        if (! is_array($collection)) {
            return [];
        }

        $builder = $this->publicEntriesBuilder((int) $collection['id'], [
            'category' => $category,
            'category_id' => null,
            'tag' => null,
            'q' => null,
            'filter_by' => null,
            'filter_value' => null,
            'filter_operator' => 'equals',
        ], $languageId, $defaultLanguageId, $preview);
        $builder->select('e.id, e.collection_id, e.author_id, e.workflow_status, e.published_at, e.scheduled_at, e.is_featured, e.view_count, e.sort_order, e.sitemap_priority, e.sitemap_changefreq, e.is_in_sitemap, e.created_at, e.updated_at')
            ->orderBy('e.published_at', 'DESC')
            ->orderBy('e.created_at', 'DESC')
            ->orderBy('e.id', 'DESC')
            ->limit($limit);
        $query = $builder->get();
        $rows = $query !== false ? array_values($query->getResultArray()) : [];

        return $this->hydrate($rows, $locale, $languageId, $defaultLanguageId, $languageCodes, $fields);
    }

    private function applyOrdering(
        \CodeIgniter\Database\BaseBuilder $builder,
        PublicReadEntryRequestDTO $request,
        int $languageId,
        int $defaultLanguageId,
    ): void {
        if ($request->listingField === null) {
            $order = match ($request->orderBy) {
                'title' => 'et_order.title',
                'published_at', 'created_at', 'sort_order' => 'e.' . $request->orderBy,
                default => 'e.sort_order',
            };
            $direction = $request->orderDirection === 'DESC' ? 'DESC' : 'ASC';
            $builder->orderBy($order, $direction);

            return;
        }

        $field = $this->classifyField($request->listingField);
        $column = null;
        $valueType = 'string';
        if ($field['mode'] === 'entry_column') {
            $column = 'e.' . $field['column'];
        } elseif ($field['mode'] === 'entry_translation') {
            $this->joinTranslationValueSubquery($builder, $field['column'], $languageId, $defaultLanguageId, 'order_trans');
            $column = 'order_trans.resolved_value';
        } elseif ($field['mode'] === 'facet') {
            $this->joinFacetSubquery($builder, $request->listingField, $languageId, $defaultLanguageId, 'order_facet');
            $column = 'order_facet.value_string';
            $valueType = $this->resolveFacetValueType($request->listingField);
        }

        if ($column !== null) {
            $this->applyFieldOrder($builder, $column, $valueType, $request->orderDirection);
        }
        $builder->orderBy('e.published_at', 'DESC')
            ->orderBy('e.created_at', 'DESC')
            ->orderBy('e.id', 'DESC');
    }

    /** @param array{mode: string, column: string} $field */
    private function applyFieldFilter(
        \CodeIgniter\Database\BaseBuilder $builder,
        array $field,
        string $rawField,
        int $languageId,
        int $defaultLanguageId,
        string $operator,
        string $value,
    ): void {
        $column = match ($field['mode']) {
            'entry_column' => 'e.' . $field['column'],
            'entry_translation' => (function () use ($builder, $field, $languageId, $defaultLanguageId): string {
                $this->joinTranslationValueSubquery($builder, $field['column'], $languageId, $defaultLanguageId, 'filter_trans');

                return 'filter_trans.resolved_value';
            })(),
            'facet' => (function () use ($builder, $rawField, $languageId, $defaultLanguageId): string {
                $this->joinFacetSubquery($builder, $rawField, $languageId, $defaultLanguageId, 'filter_facet', 'inner');

                return 'filter_facet.value_string';
            })(),
            default => null,
        };

        if ($column === null) {
            $builder->where('1 = 0', null, false);

            return;
        }

        if ($operator === 'contains') {
            $builder->where($column . ' LIKE ' . $this->db->escape('%' . $value . '%'), null, false);
        } else {
            $builder->where($column, $value);
        }
    }

    /** @return array{mode: string, column: string} */
    private function classifyField(string $field): array
    {
        if (str_starts_with($field, 'entry.')) {
            $name = substr($field, 6);
            if (in_array($name, self::ENTRY_DIRECT_COLUMNS, true)) {
                return ['mode' => 'entry_column', 'column' => $name];
            }
            if (in_array($name, self::ENTRY_TRANSLATION_FIELDS, true)) {
                return ['mode' => 'entry_translation', 'column' => $name];
            }

            return ['mode' => 'none', 'column' => ''];
        }

        if (str_starts_with($field, 'taxonomy.')) {
            return ['mode' => 'none', 'column' => ''];
        }

        return ['mode' => 'facet', 'column' => ''];
    }

    private function applyFieldOrder(
        \CodeIgniter\Database\BaseBuilder $builder,
        string $column,
        string $valueType,
        string $direction,
    ): void {
        if ($direction === 'UPCOMING' && $valueType === 'date') {
            $now = $this->db->escape(date('Y-m-d H:i:s'));
            $builder->orderBy("CASE WHEN {$column} IS NULL THEN 2 WHEN {$column} >= {$now} THEN 0 ELSE 1 END", 'ASC', false)
                ->orderBy("CASE WHEN {$column} >= {$now} THEN {$column} END", 'ASC', false)
                ->orderBy("CASE WHEN {$column} < {$now} THEN {$column} END", 'DESC', false);

            return;
        }

        $sqlDirection = $direction === 'DESC' ? 'DESC' : 'ASC';
        $builder->orderBy("({$column} IS NULL)", 'ASC', false)
            ->orderBy($column, $sqlDirection, false);
    }

    private function joinTranslationValueSubquery(
        \CodeIgniter\Database\BaseBuilder $builder,
        string $column,
        int $languageId,
        int $defaultLanguageId,
        string $alias,
        string $joinType = 'left',
    ): void {
        $quotedColumn = $this->db->escapeIdentifiers($column);
        $sql = '(SELECT entry_id, COALESCE(MAX(CASE WHEN language_id = ' . $languageId . ' THEN ' . $quotedColumn . ' END), MAX(CASE WHEN language_id = ' . $defaultLanguageId . ' THEN ' . $quotedColumn . ' END)) AS resolved_value FROM cms_entry_translations WHERE ' . $quotedColumn . ' IS NOT NULL AND ' . $quotedColumn . " <> '' AND language_id IN ({$languageId}, {$defaultLanguageId}) GROUP BY entry_id) {$alias}";
        $builder->join($sql, $alias . '.entry_id = e.id', $joinType);
    }

    private function joinFacetSubquery(
        \CodeIgniter\Database\BaseBuilder $builder,
        string $fieldKey,
        int $languageId,
        int $defaultLanguageId,
        string $alias,
        string $joinType = 'left',
    ): void {
        $escapedField = $this->db->escape($fieldKey);
        $sql = '(SELECT entry_id, COALESCE(MAX(CASE WHEN language_id = ' . $languageId . " THEN value_string END), MAX(CASE WHEN language_id = {$defaultLanguageId} THEN value_string END)) AS value_string, COALESCE(MAX(CASE WHEN language_id = {$languageId} THEN value_date END), MAX(CASE WHEN language_id = {$defaultLanguageId} THEN value_date END)) AS value_date FROM cms_entry_facet_values WHERE field_key = {$escapedField} AND language_id IN ({$languageId}, {$defaultLanguageId}) GROUP BY entry_id) {$alias}";
        $builder->join($sql, $alias . '.entry_id = e.id', $joinType);
    }

    private function resolveFacetValueType(string $fieldKey): string
    {
        $query = $this->db->table('cms_entry_facet_values')->select('value_type')->where('field_key', $fieldKey)->limit(1)->get();
        $row = $query !== false ? $query->getRowArray() : null;

        return is_array($row) ? (string) ($row['value_type'] ?? 'string') : 'string';
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<int, string> $languageCodes
     * @param list<string> $fields
     * @return list<array<string, mixed>>
     */
    private function hydrate(array $rows, string $locale, int $languageId, int $defaultLanguageId, array $languageCodes, array $fields, bool $includeBlocks = false, ?PublicReadEntryRequestDTO $request = null): array
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
            $item = array_merge($row, $selected, [
                'categories' => $categories[$id] ?? [],
                'tags' => $tags[$id] ?? [],
                'localized' => $selected,
                'localized_slugs' => $localizedSlugs,
                'is_fallback' => $selectedId !== $languageId,
            ]);
            if ($includeBlocks || $fields === [] || in_array('blocks', $fields, true)) {
                $item['blocks'] = $this->blockSerializer->forContent('entry', $id, $locale);
            }
            $result[] = $item;
        }

        if ($request?->includeListingContent === true) {
            $listingContent = $this->listingContentResolver->resolveBatch(
                $result,
                $locale,
                $fields,
                $request->listingContentFields,
            );
            foreach ($result as &$item) {
                $item['listing_content'] = $listingContent[(int) ($item['id'] ?? 0)] ?? [];
            }
            unset($item);
        }

        if ($fields !== []) {
            $result = array_map(
                static fn (array $item): array => array_intersect_key($item, array_flip($fields)),
                $result,
            );
        }

        return $result;
    }

    /** @param array<string, mixed> $entry */
    private static function hasCategory(array $entry, string $slug): bool
    {
        $categories = is_array($entry['categories'] ?? null) ? $entry['categories'] : [];
        foreach ($categories as $category) {
            if (is_array($category) && (string) ($category['slug'] ?? '') === $slug) {
                return true;
            }
        }

        return false;
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
