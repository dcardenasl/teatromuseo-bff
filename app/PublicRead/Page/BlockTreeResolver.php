<?php

declare(strict_types=1);

namespace App\PublicRead\Page;

use dcardenasl\Ci4ApiCore\Support\ApiResult;
use Throwable;

/** Resolves the complete dynamic block tree through direct read sources. */
final class BlockTreeResolver
{
    private const EVENT_DETAIL_FIELDS = [
        'id', 'uuid', 'title', 'event_type', 'description', 'slug', 'slugs',
        'cover_file_id', 'cover_image', 'gallery_file_ids', 'gallery_images',
        'translations', 'localized', 'occurrences', 'status', 'created_at', 'updated_at',
    ];

    private const CATALOG_DETAIL_FIELDS = [
        'id', 'name', 'category_id', 'inventory_code', 'status', 'summary', 'curiosidad',
        'contenido', 'origin', 'period', 'creator', 'ubicacion', 'materials', 'cover_file_id',
        'cover_image', 'gallery_file_ids', 'gallery_images', 'collection_number',
        'collection_group', 'physical_description', 'dimensions', 'ingress_type', 'donated_by',
        'tags', 'links', 'company_history', 'localized', 'translations', 'slug', 'slugs',
        'techniques', 'created_at', 'updated_at',
    ];

    public function __construct(private readonly BlockTreeSourceInterface $source)
    {
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param array<string, mixed> $query
     * @param array<string, list<array<string, mixed>>> $seededItems
     * @return array{
     *   block_prefetch: array<int|string, array<string, mixed>>,
     *   block_prefetch_complete: true,
     *   form_definitions: array<string, array<string, mixed>|null>,
     *   cacheScopes: list<string>
     * }
     */
    public function resolve(
        array $blocks,
        string $locale = 'es',
        array $query = [],
        array $seededItems = [],
    ): array {
        $locale = strtolower(trim($locale));
        $collector = new BlockPlanCollector();
        $queryBuilder = new BlockListQueryBuilder($query);
        $materializer = new BlockResultMaterializer($query);
        $plans = $collector->collect($blocks, $locale);
        $collections = [];
        $collectionsLoaded = false;
        /** @var list<array<string, mixed>> $catalogCategories */
        $catalogCategories = [];
        $catalogCategoriesLoaded = false;
        /** @var list<array<string, mixed>> $eventTypes */
        $eventTypes = [];
        $eventTypesLoaded = false;

        foreach ($plans as &$plan) {
            try {
                if ($plan['kind'] === 'detail') {
                    $this->resolveDetail($plan, $locale, $seededItems);

                    continue;
                }

                $sourceType = (string) $plan['source_type'];
                if (! in_array($sourceType, ['cms_collection', 'catalog_items', 'event_items'], true)) {
                    $plan['result'] = $materializer->failed(422, 'Dynamic block has an invalid source type.');

                    continue;
                }

                if ($sourceType === 'cms_collection') {
                    $collectionId = max(0, (int) ($plan['payload']['collection_id'] ?? 0));
                    $collectionKey = trim((string) ($plan['payload']['collection_key'] ?? ''));
                    if ($collectionId > 0) {
                        if (! $collectionsLoaded) {
                            $collections = $this->source->collections($locale);
                            $collectionsLoaded = true;
                        }
                        $collection = $this->findCollection($collections, $collectionId, $collectionKey);
                        if ($collection === null) {
                            $plan['result'] = $materializer->failed(404, 'CMS collection was not found.');

                            continue;
                        }
                        $plan['collection'] = $collection;
                        $collectionKey = trim((string) ($collection['collection_key'] ?? $collectionKey));
                    }
                    $plan['collection_key'] = $collectionKey;
                    if ($collectionKey === '') {
                        $plan['result'] = $materializer->failed(422, 'CMS collection key could not be resolved.');

                        continue;
                    }
                }

                if ($sourceType === 'catalog_items' && $queryBuilder->needsCatalogCategory($plan)) {
                    if (! $catalogCategoriesLoaded) {
                        try {
                            $catalogCategories = $this->source->catalogCategories();
                        } catch (Throwable) {
                            $catalogCategories = [];
                        }
                        $catalogCategoriesLoaded = true;
                    }
                    $plan['category_id'] = $this->findCategoryId(
                        $catalogCategories,
                        $queryBuilder->categoryValue($plan),
                    );
                }

                $mainQuery = $queryBuilder->build($plan, $sourceType);
                if ($sourceType === 'cms_collection') {
                    $mainQuery['collection'] = (string) ($plan['collection_key'] ?? '');
                }
                $plan['main_query'] = $mainQuery;
                $plan['response'] = $this->normalize($this->list($sourceType, $locale, $mainQuery));
                $plan['facet_data'] = $this->facets(
                    $plan,
                    $locale,
                    $queryBuilder,
                    $catalogCategories,
                    $catalogCategoriesLoaded,
                    $eventTypes,
                    $eventTypesLoaded,
                );
            } catch (Throwable) {
                // A source failure is local to this block. The page and every
                // other block must remain deliverable.
                $plan['result'] = $materializer->failed(503, 'Dynamic block source is unavailable.');
            }
        }
        unset($plan);

        $blockResults = [];
        foreach ($plans as $path => $plan) {
            $blockResults[(string) $path] = $materializer->materialize(
                $plan,
                is_array($plan['response'] ?? null) ? $plan['response'] : [],
                is_array($plan['facet_data'] ?? null) ? $plan['facet_data'] : [],
            );
        }

        $formDefinitions = [];
        foreach ($collector->formKeys($blocks) as $formKey) {
            try {
                $formDefinitions[$formKey] = $this->source->form($locale, $formKey);
            } catch (Throwable) {
                $formDefinitions[$formKey] = null;
            }
        }

        return [
            'block_prefetch' => $blockResults,
            'block_prefetch_complete' => true,
            'form_definitions' => $formDefinitions,
            'cacheScopes' => $collector->cacheScopes($blocks),
        ];
    }

    /** @param array<string, mixed> $plan
     *  @param array<string, list<array<string, mixed>>> $seededItems
     */
    private function resolveDetail(array &$plan, string $locale, array $seededItems): void
    {
        $blockKey = (string) $plan['block_key'];
        $payload = is_array($plan['payload'] ?? null) ? $plan['payload'] : [];
        $reference = $this->detailReference($payload, $blockKey);
        if ($reference === null) {
            $plan['result'] = $this->failed(422, 'Dynamic detail block has no id or slug.');

            return;
        }

        $client = str_starts_with($blockKey, 'event_item_') ? 'event' : 'catalog';
        $seeded = $this->findSeeded($seededItems, $client, $reference['value']);
        if ($seeded !== null) {
            $plan['seeded_item'] = $seeded;

            return;
        }

        $fields = $client === 'event' ? self::EVENT_DETAIL_FIELDS : self::CATALOG_DETAIL_FIELDS;
        $response = $client === 'event'
            ? $this->source->event($locale, $reference['value'], $fields)
            : $this->source->catalogItem($locale, $reference['value'], $fields);
        $plan['response'] = $this->normalize($response);
    }

    /**
     * @param array<string, mixed> $plan
     * @param list<array<string, mixed>> $catalogCategories
     * @param list<array<string, mixed>> $eventTypes
     * @return array<string, list<array<string, mixed>>>
     */
    private function facets(
        array &$plan,
        string $locale,
        BlockListQueryBuilder $queryBuilder,
        array &$catalogCategories,
        bool &$catalogCategoriesLoaded,
        array &$eventTypes,
        bool &$eventTypesLoaded,
    ): array {
        if ($plan['block_key'] !== 'collection_listing') {
            return [];
        }

        $sourceType = (string) $plan['source_type'];
        /** @var array<string, list<array<string, mixed>>> $facets */
        $facets = [];
        if ($queryBuilder->wantsFacet($plan, 'categories')) {
            if ($sourceType === 'cms_collection') {
                $facets['categories'] = $this->source->cmsCategories($locale, (string) $plan['collection_key']);
            } elseif ($sourceType === 'catalog_items') {
                if (! $catalogCategoriesLoaded) {
                    try {
                        $catalogCategories = $this->source->catalogCategories();
                    } catch (Throwable) {
                        $catalogCategories = [];
                    }
                    $catalogCategoriesLoaded = true;
                }
                $facets['categories'] = $catalogCategories;
            }
        }
        if ($queryBuilder->wantsFacet($plan, 'tags')) {
            if ($sourceType === 'cms_collection') {
                $facets['tags'] = $this->source->cmsTags($locale, (string) $plan['collection_key']);
            } elseif ($sourceType === 'event_items') {
                if (! $eventTypesLoaded) {
                    try {
                        $eventTypes = $this->source->eventTypes();
                    } catch (Throwable) {
                        $eventTypes = [];
                    }
                    $eventTypesLoaded = true;
                }
                $facets['tags'] = $eventTypes;
            }
        }

        return $facets;
    }

    /** @param list<array<string, mixed>> $collections
     *  @return array<string, mixed>|null
     */
    private function findCollection(array $collections, int $id, string $key): ?array
    {
        foreach ($collections as $collection) {
            if ($id > 0 && (int) ($collection['id'] ?? 0) === $id) {
                return $collection;
            }
            if ($key !== '' && (string) ($collection['collection_key'] ?? '') === $key) {
                return $collection;
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $categories */
    private function findCategoryId(array $categories, string $slug): int
    {
        foreach ($categories as $category) {
            if (trim((string) ($category['slug'] ?? ''), '/') === trim($slug, '/')) {
                return max(0, (int) ($category['id'] ?? 0));
            }
        }

        return 0;
    }

    /** @param array<string, mixed> $query */
    private function list(string $sourceType, string $locale, array $query): ApiResult
    {
        return match ($sourceType) {
            'cms_collection' => $this->source->cmsEntries($locale, $query),
            'catalog_items' => $this->source->catalogItems($locale, $query),
            'event_items' => $this->source->events($locale, $query),
            default => throw new \InvalidArgumentException('Unsupported block source.'),
        };
    }

    /** @return array<string, mixed> */
    private function normalize(ApiResult $result): array
    {
        $body = $result->body;
        $body['status'] ??= $result->status;

        return $body;
    }

    /** @param array<string, mixed> $payload
     *  @return array{value: string}|null
     */
    private function detailReference(array $payload, string $blockKey): ?array
    {
        $idKey = str_starts_with($blockKey, 'event_item_') ? 'event_id' : 'collection_item_id';
        if (isset($payload[$idKey]) && (is_int($payload[$idKey]) || ctype_digit((string) $payload[$idKey]))) {
            $id = (int) $payload[$idKey];
            if ($id > 0) {
                return ['value' => (string) $id];
            }
        }
        $slugKey = str_starts_with($blockKey, 'event_item_') ? 'event_slug' : 'collection_item_slug';
        $slug = trim((string) ($payload[$slugKey] ?? ''));

        return $slug !== '' ? ['value' => $slug] : null;
    }

    /** @param array<string, list<array<string, mixed>>> $seededItems
     *  @return array<string, mixed>|null
     */
    private function findSeeded(array $seededItems, string $client, string $reference): ?array
    {
        $sourceType = $client === 'event' ? 'event_items' : 'catalog_items';
        foreach ($seededItems[$sourceType] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $identifiers = [
                (string) ($item['id'] ?? ''),
                (string) ($item['uuid'] ?? ''),
                (string) ($item['inventory_code'] ?? ''),
                (string) ($item['slug'] ?? ''),
            ];
            foreach (is_array($item['slugs'] ?? null) ? $item['slugs'] : [] as $slug) {
                if (is_scalar($slug)) {
                    $identifiers[] = (string) $slug;
                }
            }
            if (in_array($reference, array_filter($identifiers), true)) {
                return $item;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function failed(int $status, string $message): array
    {
        return [
            'ok' => false,
            'status' => $status,
            'data' => [],
            'meta' => [],
            'messages' => [$message],
        ];
    }
}
