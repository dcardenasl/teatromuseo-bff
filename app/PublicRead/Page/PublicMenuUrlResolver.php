<?php

declare(strict_types=1);

namespace App\PublicRead\Page;

/** Resolves CMS navigation metadata into an optional public destination. */
final class PublicMenuUrlResolver
{
    /**
     * @param array<string, mixed> $item
     * @param array<int, string> $collectionSlugs
     */
    public function resolve(array $item, string $locale, array $collectionSlugs): ?string
    {
        $navigation = is_array($item['navigation'] ?? null) ? $item['navigation'] : [];
        $routeKey = (string) ($navigation['route_key'] ?? '');
        $targetType = (string) ($navigation['target_type'] ?? '');
        $collectionSlug = trim((string) ($navigation['collection_slug'] ?? ''), '/');
        if ($collectionSlug === '') {
            $collectionSlug = $collectionSlugs[(int) ($navigation['target_id'] ?? 0)] ?? '';
        }
        $entrySlug = trim((string) ($navigation['slug'] ?? ''), '/');

        if (in_array($targetType, ['collection_listing', 'entry'], true) && $collectionSlug !== '') {
            return '/' . $collectionSlug . ($targetType === 'entry' && $entrySlug !== '' ? '/' . $entrySlug : '');
        }

        $routePath = PublicPagePaths::routePath($routeKey, $locale);
        if ($routePath !== '') {
            return '/' . $routePath;
        }

        $rawCandidate = $item['custom_url'] ?? $item['url'] ?? null;
        $candidate = is_scalar($rawCandidate) ? trim((string) $rawCandidate) : '';
        if ($routeKey === 'pages' && $entrySlug !== '') {
            $canonicalPath = PublicPagePaths::canonicalPath($entrySlug, $locale);
            $candidate = $canonicalPath !== null ? '/' . $canonicalPath : '/' . $entrySlug;
        }

        return $candidate !== '' && $candidate !== '#' ? $candidate : null;
    }
}
