<?php

declare(strict_types=1);

namespace App\PublicRead\Page;

/** Collects dynamic block plans, forms, and invalidation scopes. */
final class BlockPlanCollector
{
    /** @var list<string> */
    private const LIST_BLOCKS = ['collection_grid', 'collection_listing', 'collection_timeline'];

    /** @var list<string> */
    private const DETAIL_PREFIXES = ['event_item_', 'catalog_item_'];

    /**
     * @param list<array<string, mixed>> $blocks
     * @return array<int|string, array<string, mixed>>
     */
    public function collect(array $blocks, string $locale): array
    {
        return $this->walkPlans($blocks, '', $locale);
    }

    /** @param list<array<string, mixed>> $blocks
     *  @return list<string>
     */
    public function formKeys(array $blocks): array
    {
        $keys = [];
        $this->walkForms($blocks, $keys);

        return array_keys($keys);
    }

    /** @param list<array<string, mixed>> $blocks
     *  @return list<string>
     */
    public function cacheScopes(array $blocks): array
    {
        $scopes = [];
        $this->walkScopes($blocks, $scopes);

        return array_values(array_unique($scopes));
    }

    public function isDynamicBlock(string $blockKey): bool
    {
        return in_array($blockKey, self::LIST_BLOCKS, true)
            || str_starts_with($blockKey, self::DETAIL_PREFIXES[0])
            || str_starts_with($blockKey, self::DETAIL_PREFIXES[1]);
    }

    /** @param array<string, mixed> $block
     *  @return array<string, mixed>
     */
    public function payload(array $block): array
    {
        $payload = [];
        foreach (['data', 'block_data', 'config', 'block_config'] as $key) {
            $value = $block[$key] ?? [];
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                $value = is_array($decoded) ? $decoded : [];
            }
            if (is_array($value)) {
                $payload = array_merge($payload, $value);
            }
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    public function sourceType(array $payload, string $blockKey = ''): string
    {
        $sourceType = strtolower(trim((string) ($payload['source_type'] ?? 'auto')));
        if ($sourceType !== 'auto') {
            return $sourceType;
        }
        if (str_starts_with($blockKey, 'event_item_')) {
            return 'event_items';
        }
        if (str_starts_with($blockKey, 'catalog_item_')) {
            return 'catalog_items';
        }

        return match (strtolower(trim((string) ($payload['collection_key'] ?? '')))) {
            'cartelera', 'events', 'eventos' => 'event_items',
            'museo', 'catalogo', 'catalog', 'fichas', 'collection_items' => 'catalog_items',
            default => 'cms_collection',
        };
    }

    /** @param list<array<string, mixed>> $blocks
     *  @return array<int|string, array<string, mixed>>
     */
    private function walkPlans(array $blocks, string $parentPath, string $locale): array
    {
        $plans = [];
        foreach ($blocks as $index => $block) {
            if (! is_array($block)) {
                continue;
            }
            $path = $parentPath === '' ? (string) $index : $parentPath . '.' . $index;
            $blockKey = (string) ($block['block_key'] ?? '');
            if ($this->isDynamicBlock($blockKey)) {
                $payload = $this->payload($block);
                $plans[$path] = [
                    'block' => $block,
                    'block_key' => $blockKey,
                    'block_path' => $path,
                    'locale' => $locale,
                    'payload' => $payload,
                    'source_type' => $this->sourceType($payload, $blockKey),
                    'kind' => in_array($blockKey, self::LIST_BLOCKS, true) ? 'list' : 'detail',
                    'main_query' => [],
                    'collection' => null,
                    'result' => [],
                ];
            }

            $children = $block['children'] ?? [];
            if (is_array($children)) {
                $nested = array_values(array_filter($children, static fn (mixed $child): bool => is_array($child)));
                $plans += $this->walkPlans($nested, $path, $locale);
            }
        }

        return $plans;
    }

    /** @param list<array<string, mixed>> $blocks
     *  @param array<string, true> $keys
     */
    private function walkForms(array $blocks, array &$keys): void
    {
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }
            if (($block['block_key'] ?? '') === 'form_embed') {
                $config = is_array($block['block_config'] ?? null) ? $block['block_config'] : [];
                $formKey = trim((string) ($config['form_key'] ?? 'contact'));
                if ($formKey !== '') {
                    $keys[$formKey] = true;
                }
            }
            $children = $block['children'] ?? [];
            if (is_array($children)) {
                $nested = array_values(array_filter($children, static fn (mixed $child): bool => is_array($child)));
                $this->walkForms($nested, $keys);
            }
        }
    }

    /** @param list<array<string, mixed>> $blocks
     *  @param list<string> $scopes
     */
    private function walkScopes(array $blocks, array &$scopes): void
    {
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }
            $blockKey = (string) ($block['block_key'] ?? '');
            if ($blockKey === 'form_embed') {
                $scopes[] = 'forms';
            }
            if ($this->isDynamicBlock($blockKey)) {
                $scopes = array_merge($scopes, match ($this->sourceType($this->payload($block), $blockKey)) {
                    'event_items' => ['events', 'event_types'],
                    'catalog_items' => ['collection_items', 'categories'],
                    'cms_collection' => ['collections', 'entries', 'taxonomies'],
                    default => [],
                });
            }
            $children = $block['children'] ?? [];
            if (is_array($children)) {
                $nested = array_values(array_filter($children, static fn (mixed $child): bool => is_array($child)));
                $this->walkScopes($nested, $scopes);
            }
        }
    }
}
