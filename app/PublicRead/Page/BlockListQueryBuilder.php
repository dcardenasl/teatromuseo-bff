<?php

declare(strict_types=1);

namespace App\PublicRead\Page;

/** Ports Web's per-block query semantics to the BFF request array. */
final readonly class BlockListQueryBuilder
{
    private const EVENT_LIST_FIELDS = 'id,uuid,title,event_type,slug,cover_file_id,cover_image,localized,next_occurrence_at,status';
    private const EVENT_GRID_FIELDS = 'id,title,slug,cover_image,localized,next_occurrence_at';
    private const CATALOG_LIST_FIELDS = 'id,name,category_id,inventory_code,status,summary,cover_image,slug,localized,category,created_at,updated_at';
    private const CATALOG_GRID_FIELDS = 'id,name,cover_image,slug,localized';

    /** @param array<string, mixed> $request */
    public function __construct(private array $request = [])
    {
    }

    /** @param array<string, mixed> $plan
     *  @return array<string, mixed>
     */
    public function build(array $plan, string $sourceType): array
    {
        $payload = is_array($plan['payload'] ?? null) ? $plan['payload'] : [];
        $blockKey = (string) ($plan['block_key'] ?? '');
        $isListing = $blockKey === 'collection_listing';
        $defaultLimit = $blockKey === 'collection_timeline' ? 100 : ($isListing ? 12 : 3);
        $configuredLimit = (int) ($payload['per_page'] ?? $payload['items_limit'] ?? $defaultLimit);
        $requestLimit = $isListing ? $this->value('limit', $this->value('per_page')) : '';
        $limit = $requestLimit !== '' && ctype_digit($requestLimit) && (int) $requestLimit > 0
            ? (int) $requestLimit
            : $configuredLimit;
        $limit = max(1, min(100, $limit));
        $page = $isListing ? max(1, (int) $this->value('page', '1')) : 1;
        $projection = $payload['listing_projection'] ?? [];
        if (is_string($projection)) {
            $decoded = json_decode($projection, true);
            $projection = is_array($decoded) ? $decoded : [];
        }
        $projection = is_array($projection) ? $projection : [];
        $projectionOrder = is_array($projection['order'] ?? null) ? $projection['order'] : [];
        $publicOrdering = $this->truthy($projectionOrder['public'] ?? false);
        $orderBy = trim((string) ($payload['order_by'] ?? $projectionOrder['field'] ?? ''));
        $configuredDirection = strtolower((string) ($payload['order_direction'] ?? $projectionOrder['direction'] ?? 'desc'));
        $allowedDirections = $sourceType === 'cms_collection' ? ['asc', 'desc', 'upcoming'] : ['asc', 'desc'];
        $direction = in_array($configuredDirection, $allowedDirections, true) ? $configuredDirection : 'desc';
        if ($isListing && $publicOrdering) {
            $requestedDirection = strtolower($this->value('order_direction'));
            if (in_array($requestedDirection, $allowedDirections, true)) {
                $direction = $requestedDirection;
            }
            if ($this->value('order_by') !== '') {
                $orderBy = $this->value('order_by');
            }
        }
        if ($orderBy === '') {
            $orderBy = $blockKey === 'collection_timeline'
                ? 'published_at'
                : ($sourceType === 'catalog_items' ? 'name' : 'published_at');
        }

        if ($sourceType === 'event_items') {
            $query = [
                'page' => $page,
                'per_page' => $limit,
                'sort' => in_array($orderBy, ['entry.title', 'title'], true) ? 'title' : 'agenda',
                'fields' => $isListing ? self::EVENT_LIST_FIELDS : self::EVENT_GRID_FIELDS,
            ];
            if ($isListing && ($value = $this->value('q')) !== '') {
                $query['search'] = $value;
            }
            if (($tag = $isListing ? $this->value('tag') : '') !== '') {
                $query['event_type'] = $tag;
            }

            return $query;
        }

        if ($sourceType === 'catalog_items') {
            $sort = match ($orderBy) {
                'entry.title', 'title', 'name' => 'name',
                'entry.slug', 'slug' => 'slug',
                'entry.origin', 'origin' => 'origin',
                'entry.period', 'period' => 'period',
                default => 'name',
            };
            $query = [
                'page' => $page,
                'per_page' => $limit,
                'sort' => $sort,
                'fields' => $isListing ? self::CATALOG_LIST_FIELDS : self::CATALOG_GRID_FIELDS,
            ];
            $categoryId = max(0, (int) ($plan['category_id'] ?? $payload['category_id'] ?? 0));
            if ($categoryId > 0) {
                $query['category_id'] = $categoryId;
            }
            if ($isListing && ($value = $this->value('q')) !== '') {
                $query['search'] = $value;
            }

            return $query;
        }

        $include = match ($blockKey) {
            'collection_grid' => 'listing_content.fields,listing_content.video',
            'collection_timeline' => 'listing_content.publication_date,listing_content.documents',
            default => 'listing_content.image,listing_content.secondary_action,listing_content.rich_text,listing_content.video,listing_content.publication_date,listing_content.date_fields,listing_content.fields',
        };
        $query = [
            'page' => $page,
            'per_page' => $limit,
            'order_by' => $this->cmsOrderField($orderBy),
            'order_direction' => $direction,
            'include' => $include,
            'fields' => 'id,slug,title,excerpt,published_at,featured_image,listing_content',
        ];
        if ($isListing) {
            foreach (['category', 'tag', 'q', 'filter_by', 'filter_value', 'filter_operator'] as $key) {
                $value = $this->value($key);
                if ($value !== '' && ($key !== 'filter_operator' || $value === 'contains')) {
                    $query[$key] = $value;
                }
            }
        } elseif (($categoryId = max(0, (int) ($payload['category_id'] ?? 0))) > 0) {
            $query['category_id'] = $categoryId;
        }

        return $query;
    }

    /** @param array<string, mixed> $plan */
    public function needsCatalogCategory(array $plan): bool
    {
        return ($plan['kind'] ?? '') === 'list' && $this->categoryValue($plan) !== '';
    }

    /** @param array<string, mixed> $plan */
    public function categoryValue(array $plan): string
    {
        $payload = is_array($plan['payload'] ?? null) ? $plan['payload'] : [];
        $configured = trim((string) ($payload['category'] ?? ''));

        return $configured !== '' ? $configured : $this->value('category');
    }

    /** @param array<string, mixed> $plan */
    public function wantsFacet(array $plan, string $facet): bool
    {
        $key = $facet === 'categories' ? 'show_categories' : 'show_tags';
        $payload = is_array($plan['payload'] ?? null) ? $plan['payload'] : [];
        if (array_key_exists($key, $payload)) {
            return $this->truthy($payload[$key]);
        }

        return $facet === 'categories'
            ? in_array($plan['source_type'] ?? '', ['cms_collection', 'catalog_items'], true)
            : ($plan['source_type'] ?? '') === 'event_items';
    }

    private function value(string $key, string $default = ''): string
    {
        $value = $this->request[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    private function cmsOrderField(string $orderBy): string
    {
        return str_starts_with($orderBy, 'entry.')
            || str_starts_with($orderBy, 'block.')
            || str_starts_with($orderBy, 'taxonomy.')
            ? 'field:' . $orderBy
            : $orderBy;
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
