<?php

declare(strict_types=1);

namespace App\PublicRead\Catalog;

use App\PublicRead\Support\FileMetaResolverInterface;
use App\PublicRead\Support\PublicReadEnvelope;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use dcardenasl\Ci4ApiCore\Support\ApiResult;

/**
 * Set-based public reader for catalog collection items.
 *
 * The reader owns the public projection and never delegates to
 * CollectionItemService, whose interface is intentionally CRUD-oriented.
 */
final class PublicReadCollectionItemReader
{
    private const RESOURCE_TYPE = 'collection_item';

    /** @var list<string> */
    private const ITEM_TRANSLATABLE_FIELDS = [
        'name', 'summary', 'contenido', 'curiosidad', 'physical_description', 'ubicacion',
    ];

    /** @var list<string> */
    private const PUBLIC_COLUMNS = [
        'id', 'name', 'category_id', 'inventory_code', 'status', 'summary',
        'curiosidad', 'contenido', 'origin', 'period', 'creator', 'ubicacion',
        'materials', 'cover_file_id', 'gallery_file_ids', 'collection_number',
        'collection_group', 'physical_description', 'dimensions', 'ingress_type',
        'donated_by', 'tags', 'links', 'company_history', 'is_active',
        'created_at', 'updated_at',
    ];

    /** @param BaseConnection<mixed, mixed> $db */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly FileMetaResolverInterface $fileMetaResolver,
        private readonly string $fallbackLocale = 'es',
    ) {
    }

    /** @param list<string> $fields */
    public function index(PublicReadCollectionItemRequestDTO $request, array $fields): ApiResult
    {
        $builder = $this->publicItemsBuilder($fields);

        if ($request->categoryId !== null) {
            $builder->where('ci.category_id', $request->categoryId);
            $builder->where(
                'EXISTS (SELECT 1 FROM categories cfilter WHERE cfilter.id = ci.category_id AND cfilter.deleted_at IS NULL)',
                null,
                false,
            );
        }
        if ($request->categorySlug !== null) {
            $builder->where(
                'EXISTS (SELECT 1 FROM categories cfilter WHERE cfilter.id = ci.category_id AND cfilter.slug = ' . $this->db->escape($request->categorySlug) . ' AND cfilter.deleted_at IS NULL)',
                null,
                false,
            );
        }

        if ($request->techniqueId !== null) {
            $builder->where(
                'EXISTS (SELECT 1 FROM collection_item_technique cit JOIN techniques tfilter ON tfilter.id = cit.technique_id WHERE cit.collection_item_id = ci.id AND cit.technique_id = ' . $this->db->escape($request->techniqueId) . ' AND tfilter.deleted_at IS NULL)',
                null,
                false,
            );
        }
        if ($request->technique !== null) {
            $techniqueSlugs = array_values(array_filter(array_map(
                static fn (string $slug): string => trim($slug),
                explode(',', $request->technique),
            )));
            if ($techniqueSlugs !== []) {
                $escapedSlugs = implode(', ', array_map(
                    fn (string $slug): string => (string) $this->db->escape($slug),
                    $techniqueSlugs,
                ));
                $builder->where(
                    'EXISTS (SELECT 1 FROM collection_item_technique citfilter JOIN techniques tfilter ON tfilter.id = citfilter.technique_id WHERE citfilter.collection_item_id = ci.id AND tfilter.slug IN (' . $escapedSlugs . ') AND tfilter.deleted_at IS NULL)',
                    null,
                    false,
                );
            }
        }

        if ($request->search !== '') {
            // Search the canonical value and both requested/fallback localized
            // names without joining the EAV table into the result set. The
            // EXISTS predicate keeps pagination and count cardinality stable.
            $escapedSearchValue = $this->db->escapeString($request->search, true);
            $escapedSearch = is_string($escapedSearchValue) ? $escapedSearchValue : '';
            $searchPattern = (string) $this->db->escape('%' . $escapedSearch . '%');
            $likeEscape = sprintf($this->db->likeEscapeStr, $this->db->likeEscapeChar);
            $locales = implode(', ', array_map(
                fn (string $locale): string => (string) $this->db->escape($locale),
                array_values(array_unique([$request->locale, $this->fallbackLocale])),
            ));

            $builder->groupStart()
                ->like('ci.name', $request->search)
                ->orWhere(
                    "EXISTS (SELECT 1 FROM catalog_translations cts WHERE cts.translatable_type = '" . self::RESOURCE_TYPE . "' AND cts.translatable_id = ci.id AND cts.locale IN ({$locales}) AND cts.field = 'name' AND cts.value LIKE {$searchPattern}{$likeEscape})",
                    null,
                    false,
                )
                ->groupEnd();
        }

        $countBuilder = clone $builder;
        $total = (int) $countBuilder->countAllResults();

        $sort = in_array($request->sort, ['name', 'created_at', 'id'], true) ? $request->sort : 'name';
        // Every supported order has an id tie-breaker, so page boundaries are
        // stable even when the primary sort value is duplicated.
        $builder->orderBy('ci.' . $sort, 'ASC')->orderBy('ci.id', 'ASC');
        $builder->limit($request->perPage, ($request->page - 1) * $request->perPage);
        $result = $builder->get();
        $rows = $result !== false ? array_values($result->getResultArray()) : [];
        $data = $this->hydrate($rows, $request->locale, false, $fields);

        return PublicReadEnvelope::success(
            locale: $request->locale,
            data: $data,
            sourceRevision: $this->revision(
                $rows,
                $data,
                [
                    'kind' => 'listing',
                    'query' => $request->toArray(),
                    'fields' => $fields,
                    'total' => $total,
                ],
            ),
            page: $request->page,
            perPage: $request->perPage,
            total: $total,
            domain: 'catalog',
            meta: ['fields' => $fields, 'query' => $request->toArray()],
        );
    }

    /** @param list<string> $fields */
    public function show(string $locale, string $idOrSlug, array $fields): ApiResult
    {
        $idOrSlug = trim($idOrSlug);
        $row = null;

        if (ctype_digit($idOrSlug)) {
            $row = $this->firstRow(
                $this->publicItemsBuilder($fields)->where('ci.id', (int) $idOrSlug)
            );
        } else {
            // Preserve the legacy identifier contract, but make its precedence
            // explicit: an exact inventory code wins over a routing slug.
            $row = $this->firstRow(
                $this->publicItemsBuilder($fields)
                    ->where('ci.inventory_code', $idOrSlug)
                    ->orderBy('ci.id', 'ASC')
            );

            if ($row === null) {
                $locales = array_values(array_unique([$locale, $this->fallbackLocale]));
                $row = $this->firstRow(
                    $this->publicItemsBuilder($fields)
                        ->join('catalog_public_slugs ps', 'ps.resource_id = ci.id', 'inner')
                        ->where('ps.resource_type', self::RESOURCE_TYPE)
                        ->where('ps.slug', $idOrSlug)
                        ->whereIn('ps.locale', $locales)
                        ->orderBy(
                            'CASE WHEN ps.locale = ' . $this->db->escape($locale) . ' THEN 0 ELSE 1 END',
                            'ASC',
                            false,
                        )
                        ->orderBy('ci.id', 'ASC')
                );
            }
        }

        if ($row === null) {
            return new ApiResult([
                'version' => 1,
                'ok' => false,
                'data' => null,
                'meta' => ['locale' => $locale, 'source_revision' => 'catalog:empty'],
                'source' => ['domain' => 'catalog', 'state' => 'unavailable', 'stale' => false],
                'messages' => ['Collection item not found.'],
            ], 404);
        }

        $data = $this->hydrate([$row], $locale, true, $fields)[0] ?? [];

        return PublicReadEnvelope::success(
            locale: $locale,
            data: $data,
            sourceRevision: $this->revision(
                [$row],
                [$data],
                ['kind' => 'detail', 'locale' => $locale, 'id_or_slug' => $idOrSlug, 'fields' => $fields],
            ),
            domain: 'catalog',
            meta: ['fields' => $fields, 'query' => ['id_or_slug' => $idOrSlug]],
        );
    }

    /** @param list<string> $fields */
    private function publicItemsBuilder(array $fields): BaseBuilder
    {
        $builder = $this->db->table('collection_items ci');
        $builder->select(implode(', ', array_map(
            static fn (string $column): string => 'ci.' . $column,
            $this->columnsFor($fields),
        )));
        $builder->where('ci.is_active', 1)->where('ci.status', 'published')->where('ci.deleted_at', null);

        return $builder;
    }

    /** @return array<string, mixed>|null */
    private function firstRow(BaseBuilder $builder): ?array
    {
        $query = $builder->limit(1)->get();

        return $query !== false ? $query->getRowArray() : null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $fields
     * @return list<array<string, mixed>>
     */
    private function hydrate(array $rows, string $locale, bool $detail, array $fields): array
    {
        if ($rows === []) {
            return [];
        }

        $ids = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['id'], $rows)));
        $categoryIds = $this->relationIds($rows, 'category_id', $fields, 'category');
        /** @var list<string> $categoryTranslationFields */
        $categoryTranslationFields = ['name', 'short_description'];
        /** @var list<string> $techniqueTranslationFields */
        $techniqueTranslationFields = ['name', 'summary'];
        /** @var list<array{type: string, ids: list<int>, fields: list<string>}> $translationContexts */
        $translationContexts = [];
        $itemTranslationFields = $this->itemTranslationFields($fields);
        if ($itemTranslationFields !== []) {
            $translationContexts[] = [
                'type' => self::RESOURCE_TYPE,
                'ids' => $ids,
                'fields' => $itemTranslationFields,
            ];
        }
        if ($categoryIds !== []) {
            $translationContexts[] = [
                'type' => 'category',
                'ids' => $categoryIds,
                'fields' => $categoryTranslationFields,
            ];
        }

        /** @var array<int, list<array{id: int, name: string, slug: string, summary: mixed}>> $techniqueMap */
        $techniqueMap = [];
        $techniqueIds = [];
        if ($detail && $this->needsField($fields, 'techniques')) {
            $techniqueResult = $this->db->table('collection_item_technique cit')
                ->select('cit.collection_item_id, t.id, t.name, t.slug, t.summary, t.sort_order')
                ->join('techniques t', 't.id = cit.technique_id', 'inner')
                ->whereIn('cit.collection_item_id', $ids)
                ->where('t.deleted_at', null)
                ->orderBy('t.sort_order', 'ASC')->orderBy('t.name', 'ASC')->orderBy('t.id', 'ASC')
                ->get();
            $techniques = $techniqueResult !== false ? $techniqueResult->getResultArray() : [];
            foreach ($techniques as $technique) {
                $techniqueId = (int) $technique['id'];
                $techniqueIds[] = $techniqueId;
                $techniqueMap[(int) $technique['collection_item_id']][] = [
                    'id' => $techniqueId,
                    'name' => (string) $technique['name'],
                    'slug' => (string) $technique['slug'],
                    'summary' => $technique['summary'],
                ];
            }
            $techniqueIds = array_values(array_unique($techniqueIds));
            if ($techniqueIds !== []) {
                $translationContexts[] = [
                    'type' => 'technique',
                    'ids' => $techniqueIds,
                    'fields' => $techniqueTranslationFields,
                ];
            }
        }

        $translationMap = $this->batchLoadTranslations(
            $translationContexts,
            array_values(array_unique([$locale, $this->fallbackLocale])),
        );

        $slugMap = [];
        if ($this->needsSlugs($fields)) {
            $slugResult = $this->db->table('catalog_public_slugs')
                ->select('resource_id, locale, slug')
                ->where('resource_type', self::RESOURCE_TYPE)
                ->whereIn('resource_id', $ids)
                ->whereIn('locale', array_values(array_unique([$locale, $this->fallbackLocale])))
                ->orderBy('resource_id', 'ASC')->orderBy('locale', 'ASC')
                ->get();
            $slugs = $slugResult !== false ? $slugResult->getResultArray() : [];

            foreach ($slugs as $slug) {
                $slugMap[(int) $slug['resource_id']][(string) $slug['locale']] = (string) $slug['slug'];
            }
        }

        $categoryMap = [];
        if ($categoryIds !== []) {
            $categoryResult = $this->db->table('categories c')
                ->select('c.id, c.name, c.slug, c.short_description')
                ->whereIn('c.id', $categoryIds)->where('c.deleted_at', null)->get();
            if ($categoryResult !== false) {
                foreach ($categoryResult->getResultArray() as $category) {
                    $categoryId = (int) $category['id'];
                    $categoryTranslations = $translationMap['category'][$categoryId] ?? [];
                    $categoryMap[$categoryId] = [
                        'id' => $categoryId,
                        'name' => $this->resolveTranslationValue(
                            $categoryTranslations,
                            'name',
                            $locale,
                            (string) $category['name'],
                        ),
                        'slug' => (string) $category['slug'],
                        'summary' => $this->resolveTranslationValue(
                            $categoryTranslations,
                            'short_description',
                            $locale,
                            (string) ($category['short_description'] ?? ''),
                        ),
                    ];
                }
            }
        }

        $resolveCover = $fields === [] || in_array('cover_image', $fields, true);
        $resolveGallery = $fields === [] || in_array('gallery_images', $fields, true);
        /** @var list<int> $fileIds */
        $fileIds = [];
        foreach ($rows as $row) {
            $fileIds = array_merge($fileIds, $this->fileIds($row, $resolveCover, $resolveGallery));
        }
        $media = $this->resolveMedia($fileIds);

        $result = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $itemTranslations = $translationMap[self::RESOURCE_TYPE][$id] ?? [];
            $localized = $this->localized($row, $itemTranslations, $locale, $itemTranslationFields);
            $slug = $slugMap[$id][$locale] ?? $slugMap[$id][$this->fallbackLocale] ?? '';
            $payload = [
                'id' => $id,
                'name' => $localized['name'] ?? (string) ($row['name'] ?? ''),
                'category_id' => (int) ($row['category_id'] ?? 0),
                'category' => $categoryMap[(int) ($row['category_id'] ?? 0)] ?? null,
                'inventory_code' => $row['inventory_code'] ?? null,
                'status' => (string) ($row['status'] ?? ''),
                'summary' => $localized['summary'] ?? ($row['summary'] ?? null),
                'curiosidad' => $localized['curiosidad'] ?? ($row['curiosidad'] ?? null),
                'contenido' => $localized['contenido'] ?? ($row['contenido'] ?? null),
                'origin' => $row['origin'] ?? null,
                'period' => $row['period'] ?? null,
                'creator' => $row['creator'] ?? null,
                'ubicacion' => $localized['ubicacion'] ?? ($row['ubicacion'] ?? null),
                'materials' => $row['materials'] ?? null,
                'cover_file_id' => isset($row['cover_file_id']) ? (int) $row['cover_file_id'] : null,
                'gallery_file_ids' => $row['gallery_file_ids'] ?? null,
                'cover_image' => $this->mediaItem($media, (int) ($row['cover_file_id'] ?? 0)),
                'gallery_images' => $this->galleryMedia($media, $row['gallery_file_ids'] ?? null),
                'collection_number' => $row['collection_number'] ?? null,
                'collection_group' => $row['collection_group'] ?? null,
                'physical_description' => $localized['physical_description'] ?? ($row['physical_description'] ?? null),
                'dimensions' => $row['dimensions'] ?? null,
                'ingress_type' => $row['ingress_type'] ?? null,
                'donated_by' => $row['donated_by'] ?? null,
                'tags' => $row['tags'] ?? null,
                'links' => $row['links'] ?? null,
                'company_history' => $row['company_history'] ?? null,
                'is_active' => (int) ($row['is_active'] ?? 0),
                'localized' => $localized,
                'translations' => $this->translationPayload($itemTranslations),
                'slug' => $slug,
                'slugs' => $slugMap[$id] ?? [],
                'created_at' => $row['created_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
            ];

            if ($detail) {
                $localizedTechniques = [];
                foreach ($techniqueMap[$id] ?? [] as $technique) {
                    $techniqueTranslations = $translationMap['technique'][(int) $technique['id']] ?? [];
                    $localizedTechniques[] = [
                        'id' => $technique['id'],
                        'name' => $this->resolveTranslationValue(
                            $techniqueTranslations,
                            'name',
                            $locale,
                            $technique['name'],
                        ),
                        'slug' => $technique['slug'],
                        'summary' => $this->resolveTranslationValue(
                            $techniqueTranslations,
                            'summary',
                            $locale,
                            (string) ($technique['summary'] ?? ''),
                        ),
                    ];
                }
                $payload['techniques'] = $localizedTechniques;
            }

            $result[] = $this->filterFields($payload, $fields);
        }

        return $result;
    }

    /**
     * @param list<array{type: string, ids: list<int>, fields: list<string>}> $contexts
     * @param list<string> $locales
     * @return array<string, array<int, array<string, array<string, string>>>>
     */
    private function batchLoadTranslations(array $contexts, array $locales): array
    {
        if ($contexts === []) {
            return [];
        }

        $builder = $this->db->table('catalog_translations');
        $builder->select('translatable_type, translatable_id, locale, field, value');
        $builder->whereIn('locale', $locales);
        $builder->groupStart();
        foreach ($contexts as $index => $context) {
            if ($index === 0) {
                $builder->groupStart();
            } else {
                $builder->orGroupStart();
            }
            $builder->where('translatable_type', $context['type'])
                ->whereIn('translatable_id', $context['ids'])
                ->whereIn('field', $context['fields'])
                ->groupEnd();
        }
        $builder->groupEnd();
        $result = $builder
            ->orderBy('translatable_type', 'ASC')
            ->orderBy('translatable_id', 'ASC')
            ->orderBy('locale', 'ASC')
            ->orderBy('field', 'ASC')
            ->get();
        $rows = $result !== false ? $result->getResultArray() : [];

        $map = [];
        foreach ($rows as $row) {
            $type = (string) $row['translatable_type'];
            $id = (int) $row['translatable_id'];
            $locale = (string) $row['locale'];
            $field = (string) $row['field'];
            $map[$type][$id][$locale][$field] = (string) $row['value'];
        }

        return $map;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $fields
     * @return list<int>
     */
    private function relationIds(array $rows, string $column, array $fields, string $field): array
    {
        if (! $this->needsField($fields, $field)) {
            return [];
        }

        $ids = array_map(static fn (array $row): int => (int) ($row[$column] ?? 0), $rows);

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /**
     * @param list<string> $fields
     * @return list<string>
     */
    private function itemTranslationFields(array $fields): array
    {
        if ($fields === [] || in_array('localized', $fields, true) || in_array('translations', $fields, true)) {
            return self::ITEM_TRANSLATABLE_FIELDS;
        }

        return array_values(array_intersect(self::ITEM_TRANSLATABLE_FIELDS, $fields));
    }

    /** @param array<string, array<string, string>> $rows */
    private function resolveTranslationValue(array $rows, string $field, string $locale, string $legacy): string
    {
        return $rows[$locale][$field]
            ?? $rows[$this->fallbackLocale][$field]
            ?? $legacy;
    }

    /** @param list<string> $fields */
    private function needsSlugs(array $fields): bool
    {
        return $fields === [] || $this->needsOneOf($fields, ['slug', 'slugs']);
    }

    /** @param list<string> $fields */
    private function needsField(array $fields, string $field): bool
    {
        return $fields === [] || in_array($field, $fields, true);
    }

    /**
     * @param list<string> $fields
     * @param list<string> $required
     */
    private function needsOneOf(array $fields, array $required): bool
    {
        return $fields === [] || array_intersect($required, $fields) !== [];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array<string, string>> $rows
     * @param list<string> $fields
     * @return array<string, string>
     */
    private function localized(array $row, array $rows, string $locale, array $fields): array
    {
        $localized = [];
        foreach ($fields as $field) {
            $localized[$field] = $this->resolveTranslationValue(
                $rows,
                $field,
                $locale,
                (string) ($row[$field] ?? ''),
            );
        }

        return $localized;
    }

    /**
     * @param array<string, array<string, string>> $rows
     * @return list<array<string, string>>
     */
    private function translationPayload(array $rows): array
    {
        $payload = [];
        foreach ($rows as $locale => $fields) {
            $payload[] = ['locale' => $locale, ...$fields];
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $fields
     * @return array<string, mixed>
     */
    private function filterFields(array $payload, array $fields): array
    {
        if ($fields === []) {
            return $payload;
        }

        return array_intersect_key($payload, array_flip($fields));
    }

    /**
     * @param array<string, mixed> $row
     * @return list<int>
     */
    private function fileIds(array $row, bool $resolveCover, bool $resolveGallery): array
    {
        $ids = [];
        if ($resolveCover && (int) ($row['cover_file_id'] ?? 0) > 0) {
            $ids[] = (int) $row['cover_file_id'];
        }
        if ($resolveGallery) {
            foreach (explode(',', (string) ($row['gallery_file_ids'] ?? '')) as $rawId) {
                if ((int) trim($rawId) > 0) {
                    $ids[] = (int) trim($rawId);
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function resolveMedia(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->fileMetaResolver->resolveMany(array_values(array_unique(array_map(
            static fn (mixed $id): int => (int) $id,
            $ids,
        ))));
    }

    /**
     * @param array<int, array<string, mixed>> $media
     * @return array<string, mixed>|null
     */
    private function mediaItem(array $media, int $id): ?array
    {
        if ($id <= 0 || ! isset($media[$id])) {
            return null;
        }

        $meta = $media[$id];

        return [
            'source_kind' => 'hub_file',
            'file_id' => $id,
            'url' => $meta['url'] ?? null,
            'variants' => is_string($meta['variants'] ?? null)
                ? json_decode($meta['variants'], true)
                : ($meta['variants'] ?? null),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $media
     * @return list<array<string, mixed>>
     */
    private function galleryMedia(array $media, mixed $rawIds): array
    {
        $result = [];
        foreach (explode(',', (string) $rawIds) as $rawId) {
            $item = $this->mediaItem($media, (int) trim($rawId));
            if ($item !== null) {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * Publication revision for the delivered projection, not a global database
     * revision. It changes whenever the selected page/projection changes,
     * including selected translations, relations, slugs and media metadata.
     * Fields omitted by the caller are intentionally outside this revision.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<array<string, mixed>> $projection
     * @param array<string, mixed> $context
     */
    private function revision(array $rows, array $projection, array $context): string
    {
        $encoded = json_encode(
            ['rows' => $rows, 'projection' => $projection, 'context' => $context],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        return 'catalog:publication:v2:' . hash('sha256', $encoded);
    }

    /**
     * @param list<string> $fields
     * @return list<string>
     */
    private function columnsFor(array $fields): array
    {
        if ($fields === []) {
            return self::PUBLIC_COLUMNS;
        }

        $columns = ['id', 'updated_at'];
        foreach (array_intersect(self::PUBLIC_COLUMNS, $fields) as $field) {
            $columns[] = $field;
        }
        $localizedFields = $this->localizedBaseFields($fields);
        if ($localizedFields !== []) {
            foreach (array_intersect(self::PUBLIC_COLUMNS, $localizedFields) as $field) {
                $columns[] = $field;
            }
        }
        if ($this->needsField($fields, 'category')) {
            $columns[] = 'category_id';
        }
        if (in_array('cover_image', $fields, true)) {
            $columns[] = 'cover_file_id';
        }
        if (in_array('gallery_images', $fields, true)) {
            $columns[] = 'gallery_file_ids';
        }

        return array_values(array_unique($columns));
    }

    /**
     * @param list<string> $fields
     * @return list<string>
     */
    private function localizedBaseFields(array $fields): array
    {
        if ($fields === [] || in_array('localized', $fields, true)) {
            return self::ITEM_TRANSLATABLE_FIELDS;
        }

        return array_values(array_intersect(self::ITEM_TRANSLATABLE_FIELDS, $fields));
    }
}
