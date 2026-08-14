<?php

declare(strict_types=1);

namespace App\PublicRead\Page;

use App\PublicRead\Cms\PublicReadLayoutReader;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/** Composes the routing, layout, and block context delivery envelope. */
final readonly class PageEnvelope
{
    public function __construct(
        private PageResolver $resolver,
        private PublicReadLayoutReader $layout,
        private BlockTreeResolver $blocks,
    ) {
    }

    /** @param array<string, mixed> $query
     *  @return array<string, mixed>
     */
    public function resolve(
        string $locale,
        string $route,
        bool $preview = false,
        array $query = [],
    ): array {
        $resolution = $this->resolver->resolve($locale, $route, $preview);
        $base = [
            'outcome' => $resolution['outcome'],
            'redirect' => $resolution['redirect'],
            'page' => $resolution['page'],
            'layout' => [],
            'block_context' => [],
            'meta' => $this->meta($locale, $route, $query),
            'source' => [
                'domain' => 'bff',
                'state' => 'unavailable',
                'stale' => false,
            ],
            'messages' => [],
        ];

        if ($resolution['outcome'] !== 'page' || ! is_array($resolution['page'])) {
            if ($resolution['outcome'] === 'not_found') {
                $base['messages'] = ['Public page was not found.'];
            }

            return $base;
        }

        $page = $resolution['page'];
        $base['layout'] = $this->layout($locale);
        $base['block_context'] = $this->blockContext($page, $locale, $query);
        $stale = $this->hasStaleBlock($base['block_context']);
        $base['source'] = [
            'domain' => 'bff',
            'state' => $stale ? 'stale' : 'fresh',
            'stale' => $stale,
        ];
        $base['meta']['instances'] = $this->instances($base['block_context']);

        return $base;
    }

    /** @param array<string, mixed> $envelope */
    public function httpStatus(array $envelope): int
    {
        if (($envelope['outcome'] ?? '') === 'redirect') {
            return max(300, min(399, (int) ($envelope['redirect']['status'] ?? 301)));
        }
        if (($envelope['outcome'] ?? '') === 'not_found') {
            return 404;
        }

        return 200;
    }

    /** @param array<string, mixed> $query
     *  @return array<string, mixed>
     */
    private function meta(string $locale, string $route, array $query): array
    {
        return [
            'version' => 1,
            'locale' => strtolower(trim($locale)),
            'route' => trim($route, '/'),
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            'expires_at' => null,
            'query' => $query,
        ];
    }

    /** @return array<string, mixed> */
    private function layout(string $locale): array
    {
        try {
            $result = $this->layout->show($locale);
            $data = is_array($result->body['data'] ?? null) ? $result->body['data'] : [];
            $navigation = is_array($data['navigation'] ?? null) ? $data['navigation'] : [];
            $collections = array_values(array_filter(
                is_array($data['collections'] ?? null) ? $data['collections'] : [],
                static fn (mixed $collection): bool => is_array($collection),
            ));
            $collectionSlugs = $this->collectionSlugsById($collections, $locale);
            $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];

            return [
                'settings' => $settings,
                'mainMenu' => $this->menu($navigation['main'] ?? null, $locale, $collectionSlugs),
                'footerMenu' => $this->menu($navigation['footer'] ?? null, $locale, $collectionSlugs),
                'legalMenu' => $this->menu($navigation['legal'] ?? null, $locale, $collectionSlugs),
                'socialLinks' => $this->socialLinks($settings),
            ];
        } catch (Throwable) {
            return [
                'mainMenu' => ['items' => []],
                'footerMenu' => ['items' => []],
                'legalMenu' => ['items' => []],
                'settings' => [],
                'socialLinks' => [],
            ];
        }
    }

    /** @param array<string, mixed> $page
     *  @param array<string, mixed> $query
     *  @return array<string, mixed>
     */
    private function blockContext(array $page, string $locale, array $query): array
    {
        $blocks = is_array($page['blocks'] ?? null)
            ? array_values(array_filter($page['blocks'], static fn (mixed $block): bool => is_array($block)))
            : [];

        try {
            return $this->blocks->resolve($blocks, $locale, $query);
        } catch (Throwable) {
            return [
                'block_prefetch' => [],
                'block_prefetch_complete' => true,
                'form_definitions' => [],
                'cacheScopes' => [],
            ];
        }
    }

    /** @param array<string, mixed> $context */
    private function hasStaleBlock(array $context): bool
    {
        foreach ($context['block_prefetch'] ?? [] as $result) {
            if (is_array($result) && (($result['stale'] ?? false) === true || ($result['meta']['stale'] ?? false) === true)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $context
     *  @return list<array<string, mixed>>
     */
    private function instances(array $context): array
    {
        $instances = [];
        foreach ($context['block_prefetch'] ?? [] as $path => $result) {
            if (! is_array($result) || ! is_array($result['instance'] ?? null)) {
                continue;
            }
            $instances[] = array_merge(['path' => (string) $path], $result['instance']);
        }

        return $instances;
    }

    /** @param list<array<string, mixed>> $collections
     *  @return array<int, string>
     */
    private function collectionSlugsById(array $collections, string $locale): array
    {
        $result = [];
        foreach ($collections as $collection) {
            $id = (int) ($collection['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $localized = is_array($collection['localized_slugs'] ?? null) ? $collection['localized_slugs'] : [];
            $slug = trim((string) ($localized[$locale] ?? $collection['slug'] ?? ''), '/');
            if ($slug !== '') {
                $result[$id] = $slug;
            }
        }

        return $result;
    }

    /** @param array<string, mixed>|null $menu
     *  @param array<int, string> $collectionSlugs
     *  @return array<string, mixed>
     */
    private function menu(?array $menu, string $locale, array $collectionSlugs): array
    {
        if ($menu === null) {
            return ['items' => []];
        }
        $items = is_array($menu['items'] ?? null) ? $menu['items'] : [];
        $menu['items'] = $this->menuItems($items, $locale, $collectionSlugs);

        return $menu;
    }

    /** @param array<int|string, mixed> $items
     *  @param array<int, string> $collectionSlugs
     *  @return list<array<string, mixed>>
     */
    private function menuItems(array $items, string $locale, array $collectionSlugs): array
    {
        $result = [];
        foreach ($items as $rawItem) {
            if (! is_array($rawItem)) {
                continue;
            }
            $item = $rawItem;
            $navigation = is_array($item['navigation'] ?? null) ? $item['navigation'] : [];
            $routeKey = (string) ($navigation['route_key'] ?? '');
            $targetType = (string) ($navigation['target_type'] ?? '');
            $collectionSlug = trim((string) ($navigation['collection_slug'] ?? ''), '/');
            if ($collectionSlug === '') {
                $collectionSlug = $collectionSlugs[(int) ($navigation['target_id'] ?? 0)] ?? '';
            }
            $entrySlug = trim((string) ($navigation['slug'] ?? ''), '/');
            if (in_array($targetType, ['collection_listing', 'entry'], true) && $collectionSlug !== '') {
                $item['custom_url'] = '/' . $collectionSlug . ($targetType === 'entry' && $entrySlug !== '' ? '/' . $entrySlug : '');
            } else {
                $routePath = PublicPagePaths::routePath($routeKey, $locale);
                if ($routePath !== '') {
                    $item['custom_url'] = '/' . $routePath;
                } else {
                    $candidate = (string) ($item['custom_url'] ?? $item['url'] ?? '');
                    if ($routeKey === 'pages' && $entrySlug !== '') {
                        $candidate = PublicPagePaths::canonicalPath($entrySlug, $locale) !== null
                            ? '/' . PublicPagePaths::canonicalPath($entrySlug, $locale)
                            : '/' . $entrySlug;
                    }
                    if ($candidate !== '') {
                        $item['custom_url'] = $candidate;
                    }
                }
            }
            $children = $item['children'] ?? [];
            $item['children'] = is_array($children) ? $this->menuItems($children, $locale, $collectionSlugs) : [];
            $result[] = $item;
        }

        return $result;
    }

    /** @param array<string, mixed> $settings
     *  @return list<array{key: string, label: string, url: string}>
     */
    private function socialLinks(array $settings): array
    {
        $networks = [
            ['key' => 'social_facebook', 'label' => 'Facebook'],
            ['key' => 'social_instagram', 'label' => 'Instagram'],
            ['key' => 'social_youtube', 'label' => 'YouTube'],
        ];
        $links = [];
        foreach ($networks as $network) {
            $url = $settings[$network['key']] ?? null;
            if (! is_string($url) || ! $this->validSocialUrl($url)) {
                continue;
            }
            $links[] = ['key' => $network['key'], 'label' => $network['label'], 'url' => trim($url)];
        }

        return $links;
    }

    private function validSocialUrl(string $url): bool
    {
        if (str_starts_with($url, '[') || str_ends_with($url, ']')) {
            return false;
        }
        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            return false;
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && trim($path, '/') !== '';
    }
}
