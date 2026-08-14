<?php

declare(strict_types=1);

namespace App\PublicRead\Page;

/** Shapes one isolated dynamic block result for Web's existing ViewModels. */
final readonly class BlockResultMaterializer
{
    /** @param array<string, mixed> $query */
    public function __construct(private array $query = [])
    {
    }

    /** @return array<string, mixed> */
    public function empty(): array
    {
        return [
            'ok' => false,
            'status' => 0,
            'data' => [],
            'meta' => [],
            'facets' => ['categories' => [], 'tags' => []],
            'collection' => null,
            'stale' => false,
            'messages' => [],
        ];
    }

    /** @return array<string, mixed> */
    public function failed(int $status, string $message): array
    {
        $result = $this->empty();
        $result['status'] = $status;
        $result['messages'] = [$message];

        return $result;
    }

    /** @param array<string, mixed> $plan
     *  @param array<string, mixed> $result
     *  @return array<string, mixed>
     */
    public function finish(array $plan, array $result): array
    {
        $result['instance'] = $this->instance($plan, $result);

        return $result;
    }

    /** @param array<string, mixed> $plan
     *  @param array<string, mixed> $response
     *  @param array<string, list<array<string, mixed>>> $facetData
     *  @return array<string, mixed>
     */
    public function materialize(array $plan, array $response, array $facetData = []): array
    {
        $result = is_array($plan['result'] ?? null) && $plan['result'] !== []
            ? $plan['result']
            : $this->empty();
        $main = isset($plan['seeded_item'])
            ? ['ok' => true, 'status' => 200, 'data' => [$plan['seeded_item']], 'meta' => [], 'messages' => []]
            : $response;

        if ($main !== []) {
            $result['ok'] = (bool) ($main['ok'] ?? false);
            $result['status'] = (int) ($main['status'] ?? 0);
            $result['data'] = $this->items($main['data'] ?? null);
            $result['meta'] = is_array($main['meta'] ?? null) ? $main['meta'] : [];
            if ($plan['kind'] === 'list' && ! isset($result['meta']['pagination'])) {
                $result['meta']['pagination'] = $this->pagination(
                    $result['meta'],
                    is_array($plan['main_query'] ?? null) ? $plan['main_query'] : [],
                    count($result['data']),
                );
            }
            $result['stale'] = (bool) ($result['meta']['stale'] ?? false);
            $result['messages'] = $this->messages($main['messages'] ?? []);
        }

        if ($plan['kind'] === 'detail' && $result['data'] !== []) {
            $result['data'] = [$result['data'][0]];
        }
        if (is_array($plan['collection'] ?? null)) {
            $result['collection'] = $plan['collection'];
        }
        foreach ($facetData as $facet => $data) {
            $result['facets'][$facet] = $data;
        }

        return $this->finish($plan, $result);
    }

    /** @param array<string, mixed> $data
     *  @return list<array<string, mixed>>
     */
    public function items(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }
        if (isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }
        if (! array_is_list($data)) {
            return [$data];
        }

        return array_values(array_filter($data, static fn (mixed $item): bool => is_array($item)));
    }

    /** @param array<string, mixed> $meta
     *  @param array<string, mixed> $query
     *  @return array<string, int|bool>
     */
    private function pagination(array $meta, array $query, int $dataCount): array
    {
        $total = (int) ($meta['total'] ?? $meta['total_items'] ?? $meta['count'] ?? $dataCount);
        $page = max(1, (int) ($meta['page'] ?? $meta['current_page'] ?? $query['page'] ?? 1));
        $perPage = max(1, (int) ($meta['per_page'] ?? $meta['perPage'] ?? $query['per_page'] ?? max(1, $dataCount)));
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);

        return [
            'total' => $total,
            'total_items' => $total,
            'page' => $page,
            'current_page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'has_next_page' => $page < $totalPages,
            'has_previous_page' => $page > 1,
        ];
    }

    /** @param array<string, mixed> $plan
     *  @param array<string, mixed> $result
     *  @return array<string, mixed>
     */
    private function instance(array $plan, array $result): array
    {
        $query = is_array($plan['main_query'] ?? null) ? $plan['main_query'] : [];
        $payload = is_array($plan['payload'] ?? null) ? $plan['payload'] : [];
        $filters = [];
        foreach (['category', 'tag', 'q', 'search', 'filter_by', 'filter_value', 'filter_operator'] as $key) {
            if (array_key_exists($key, $query)) {
                $filters[$key] = $query[$key];
            }
        }
        $state = ! ($result['ok'] ?? false)
            ? 'unavailable'
            : (($result['stale'] ?? false) === true ? 'stale' : 'fresh');

        return [
            'path' => (string) ($plan['block_path'] ?? ''),
            'type' => (string) ($plan['block_key'] ?? ''),
            'config' => $payload,
            'page' => max(1, (int) ($query['page'] ?? 1)),
            'limit' => max(1, (int) ($query['per_page'] ?? $query['limit'] ?? 0)),
            'filters' => $filters,
            'order' => [
                'sort' => (string) ($query['sort'] ?? $query['order_by'] ?? ''),
                'direction' => (string) ($query['order_direction'] ?? ''),
            ],
            'facets' => array_keys($plan['facet_data'] ?? []),
            'preview' => $this->isPreview(),
            'source' => $state,
        ];
    }

    /** @param mixed $messages
     *  @return list<string>
     */
    private function messages(mixed $messages): array
    {
        return is_array($messages)
            ? array_values(array_filter(array_map('strval', $messages), static fn (string $message): bool => $message !== ''))
            : [];
    }

    private function isPreview(): bool
    {
        $value = $this->query['preview'] ?? '';

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes'], true);
    }
}
