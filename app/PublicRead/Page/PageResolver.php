<?php

declare(strict_types=1);

namespace App\PublicRead\Page;

use dcardenasl\Ci4ApiCore\Exceptions\NotFoundException;
use Throwable;

/** Resolves public routing before layout and block composition. */
final class PageResolver
{
    public function __construct(
        private readonly RedirectReaderInterface $redirects,
        private readonly PageReaderInterface $pages,
        private readonly ?CollectionReaderInterface $collections = null,
        private readonly ?EntryReaderInterface $entries = null,
        private readonly ?EventReaderInterface $events = null,
        private readonly ?CatalogItemReaderInterface $catalogItems = null,
    ) {
    }

    /**
     * @return array{
     *     outcome: 'redirect'|'page'|'not_found',
     *     redirect: array{path: string, status: int}|null,
     *     page: array<string, mixed>|null,
     *     context: array<string, mixed>,
     * }
     */
    public function resolve(
        string $locale,
        string $route,
        bool $preview = false,
    ): array {
        $locale = strtolower(trim($locale));
        $route = trim($route, '/');
        $path = $route === '' || $route === 'home'
            ? PublicPagePaths::homepageSegment($locale)
            : $route;

        if (PublicPagePaths::isLegacyPublicBasePath($path, $locale)) {
            return $this->redirectResult('/' . PublicPagePaths::homepageSegment($locale), 301);
        }

        $redirect = $this->redirectFor($path, $locale);
        if ($redirect !== null) {
            return $this->redirectResult($redirect['path'], $redirect['status']);
        }

        $page = $this->pageFor($locale, $path, $preview);
        if ($page !== null) {
            return $this->pageResult($page);
        }

        foreach (PublicPagePaths::aliasesFor($path, $locale) as $alias) {
            $page = $this->pageFor($locale, $alias, $preview);
            if ($page !== null) {
                return $this->pageResult($page);
            }
        }

        $domainDetail = $this->domainDetailFor($locale, $path);
        if ($domainDetail !== null) {
            if (isset($domainDetail['not_found'])) {
                return ['outcome' => 'not_found', 'redirect' => null, 'page' => null, 'context' => []];
            }

            return $this->pageResult(
                $domainDetail['page'],
                'cms_page',
                $domainDetail['context'],
            );
        }

        $collectionCandidates = $this->collectionCandidatesFor($locale, $path);
        $entryPage = $this->entryFor($locale, $collectionCandidates, $preview);
        if ($entryPage !== null) {
            return $this->pageResult($entryPage, 'collection_entry');
        }

        $fallbackPage = $this->fallbackFor($locale, $collectionCandidates);
        if ($fallbackPage !== null) {
            return $this->pageResult($fallbackPage, 'collection_fallback_index');
        }

        return ['outcome' => 'not_found', 'redirect' => null, 'page' => null, 'context' => []];
    }

    /** @return array{path: string, status: int}|null */
    private function redirectFor(string $path, string $locale): ?array
    {
        try {
            $redirect = $this->redirects->resolve([$path]);
        } catch (NotFoundException) {
            return null;
        }

        $target = (string) ($redirect['new_url'] ?? '');
        $parsed = parse_url(trim($target));
        $isExternal = is_array($parsed)
            && (($parsed['scheme'] ?? '') !== '' || ($parsed['host'] ?? '') !== '');
        if (! $isExternal) {
            $canonical = PublicPagePaths::canonicalPath($target, $locale);
            if ($canonical !== null) {
                $target = '/' . ltrim($canonical, '/');
            }
        }

        $redirectType = $redirect['redirect_type'] ?? null;
        $status = $redirectType === 302 || $redirectType === 'temporary' ? 302 : 301;

        return ['path' => $target, 'status' => $status];
    }

    /** @return array<string, mixed>|null */
    private function pageFor(
        string $locale,
        string $path,
        bool $preview,
    ): ?array {
        $result = $this->pages->show($locale, $path, [], $preview);
        $data = $result->body['data'] ?? null;
        if (is_array($data) && ($result->body['ok'] ?? false) === true) {
            return $data;
        }

        if ($path === PublicPagePaths::homepageSegment($locale) || $path === 'home') {
            $homeResult = $this->pages->byType($locale, 'home');
            $home = $homeResult->body['data'] ?? null;
            if (is_array($home) && ($homeResult->body['ok'] ?? false) === true) {
                return $home;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $page
     * @param array<string, mixed> $context
     * @return array{outcome: 'page', redirect: null, page: array<string, mixed>, context: array<string, mixed>}
     */
    private function pageResult(array $page, string $pageType = 'cms_page', array $context = []): array
    {
        if ($pageType === 'cms_page') {
            $sourcePageType = (string) ($page['page_type'] ?? '');
            $page['source_page_type'] = $sourcePageType;
            if (! array_key_exists('showPageHeading', $page)) {
                $page['showPageHeading'] = ! $this->hasHeadingOwner($page['blocks'] ?? []);
            }
        }
        $page['page_type'] = $pageType;

        return ['outcome' => 'page', 'redirect' => null, 'page' => $page, 'context' => $context];
    }

    /** @param mixed $blocks */
    private function hasHeadingOwner(mixed $blocks): bool
    {
        if (! is_array($blocks)) {
            return false;
        }

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $presentation = $block['presentation'] ?? null;
            if (is_array($presentation) && ($presentation['owns_page_heading'] ?? false) === true) {
                return true;
            }

            if ($this->hasHeadingOwner($block['children'] ?? [])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve event/catalog detail routes with the same singleton template
     * shell used by the Web, returning the detail as pre-seeded block context.
     *
     * @return array{page: array<string, mixed>, context: array<string, mixed>}|array{not_found: true}|null
     */
    private function domainDetailFor(string $locale, string $path): ?array
    {
        foreach ([
            ['events', $this->events, 'template_event_item', 'event_item'],
            ['catalog', $this->catalogItems, 'template_catalog_item', 'catalog_item'],
        ] as [$routeKey, $reader, $templateType, $contextKey]) {
            if (! $reader instanceof DomainDetailReaderInterface) {
                continue;
            }

            $prefix = PublicPagePaths::routePath((string) $routeKey, $locale);
            if ($prefix === '' || ! str_starts_with($path, $prefix . '/')) {
                continue;
            }

            $identifier = trim(substr($path, strlen($prefix) + 1), '/');
            if ($identifier === '' || str_contains($identifier, '/')) {
                return ['not_found' => true];
            }

            $result = $reader->show($locale, $identifier, []);
            $detail = $result->body['data'] ?? null;
            if (($result->body['ok'] ?? false) !== true || ! is_array($detail)) {
                return ['not_found' => true];
            }

            $templateResult = $this->pages->byType($locale, (string) $templateType);
            $template = $templateResult->body['data'] ?? null;
            if (($templateResult->body['ok'] ?? false) !== true || ! is_array($template)) {
                return ['not_found' => true];
            }

            return [
                'page' => $this->domainDetailPage(
                    $template,
                    $detail,
                    $locale,
                    (string) $routeKey,
                ),
                'context' => [(string) $contextKey => $detail],
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $template
     * @param array<string, mixed> $detail
     * @return array<string, mixed>
     */
    private function domainDetailPage(
        array $template,
        array $detail,
        string $locale,
        string $routeKey,
    ): array {
        $localized = is_array($detail['localized'] ?? null) ? $detail['localized'] : [];
        $isEvent = $routeKey === 'events';
        $title = (string) ($localized[$isEvent ? 'title' : 'name'] ?? $detail[$isEvent ? 'title' : 'name'] ?? '');
        $excerpt = (string) ($localized[$isEvent ? 'description' : 'summary'] ?? $detail[$isEvent ? 'description' : 'summary'] ?? '');
        $slug = trim((string) ($detail['slug'] ?? ''), '/');
        if ($slug === '') {
            $slug = trim((string) ($detail['id'] ?? ''), '/');
        }

        $localizedSlugs = [];
        $slugs = is_array($detail['slugs'] ?? null) ? $detail['slugs'] : [];
        foreach ($slugs as $language => $localizedSlug) {
            if (is_scalar($localizedSlug) && trim((string) $localizedSlug) !== '') {
                $localizedSlugs[(string) $language] = PublicPagePaths::routePath($routeKey, (string) $language)
                    . '/' . trim((string) $localizedSlug, '/');
            }
        }
        if ($localizedSlugs === []) {
            $localizedSlugs[$locale] = PublicPagePaths::routePath($routeKey, $locale) . '/' . $slug;
        }

        $localizedUrls = [];
        foreach ($localizedSlugs as $language => $localizedPath) {
            $language = trim((string) $language, '/');
            $localizedPath = trim((string) $localizedPath, '/');
            if ($language === '' || $localizedPath === '') {
                continue;
            }
            $localizedUrls[$language] = '/' . $language . '/' . $localizedPath;
        }

        $page = $template;
        $page['title'] = $title;
        $page['excerpt'] = $excerpt;
        $page['meta_title'] = $title;
        $page['meta_description'] = $this->detailMetaDescription($excerpt, $template['blocks'] ?? []);
        $page['slug'] = $localizedSlugs[$locale] ?? ($localizedSlugs[array_key_first($localizedSlugs)] ?? $slug);
        $page['localized_slugs'] = $localizedSlugs;
        $page['localized_urls'] = $localizedUrls;
        $page['canonical_url'] = '/' . $locale . '/' . ltrim($page['slug'], '/');

        // The CMS template owns the page presentation and SEO policy, while
        // publication timestamps belong to the domain record being rendered.
        // This keeps Article metadata tied to the actual event/catalog item,
        // never to the singleton template shell.
        foreach (['created_at' => 'published_at', 'updated_at' => 'updated_at'] as $detailKey => $pageKey) {
            $value = trim((string) ($detail[$detailKey] ?? ''));
            if ($value !== '') {
                $page[$pageKey] = $value;
            }
        }

        return $page;
    }

    /** @param mixed $blocks */
    private function detailMetaDescription(string $excerpt, mixed $blocks): string
    {
        $maxLength = $this->seoDescriptionMaxLength($blocks);
        if ($maxLength === null || $maxLength <= 0 || mb_strlen($excerpt) <= $maxLength) {
            return $excerpt;
        }

        return rtrim(mb_strimwidth($excerpt, 0, $maxLength, '…', 'UTF-8'));
    }

    /** @param mixed $blocks */
    private function seoDescriptionMaxLength(mixed $blocks): ?int
    {
        if (! is_array($blocks)) {
            return null;
        }

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $presentation = $block['presentation'] ?? null;
            $seo = is_array($presentation) && is_array($presentation['seo'] ?? null)
                ? $presentation['seo']
                : [];
            $maxLength = filter_var($seo['description_max_length'] ?? null, FILTER_VALIDATE_INT);
            if (is_int($maxLength) && $maxLength > 0) {
                return $maxLength;
            }

            $nestedMaxLength = $this->seoDescriptionMaxLength($block['children'] ?? []);
            if ($nestedMaxLength !== null) {
                return $nestedMaxLength;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    /**
     * @param list<array{collection: array<string, mixed>, remainder: string}> $candidates
     * @return array<string, mixed>|null
     */
    private function entryFor(string $locale, array $candidates, bool $preview): ?array
    {
        if (! $this->entries instanceof EntryReaderInterface) {
            return null;
        }

        foreach ($candidates as $candidate) {
            if ($candidate['remainder'] === '') {
                continue;
            }

            $collection = $candidate['collection'];
            $collectionKey = trim((string) ($collection['collection_key'] ?? ''));
            if ($collectionKey === '') {
                continue;
            }
            $result = $preview
                ? $this->entries->show($locale, $collectionKey, $candidate['remainder'], [], true)
                : $this->entries->show($locale, $collectionKey, $candidate['remainder'], []);
            $entry = $result->body['data'] ?? null;
            if (($result->body['ok'] ?? false) !== true || ! is_array($entry)) {
                continue;
            }

            try {
                $entry['related_entries'] = $preview
                    ? $this->entries->related($locale, $collectionKey, $entry, 3, true)
                    : $this->entries->related($locale, $collectionKey, $entry, 3);
            } catch (Throwable) {
                $entry['related_entries'] = [];
            }
            $entry['collection'] = $collection;

            return $entry;
        }

        return null;
    }

    /**
     * @param list<array{collection: array<string, mixed>, remainder: string}> $candidates
     * @return array<string, mixed>|null
     */
    private function fallbackFor(string $locale, array $candidates): ?array
    {
        foreach ($candidates as $candidate) {
            if ($candidate['remainder'] !== '') {
                continue;
            }

            $collection = $candidate['collection'];
            if (is_array($collection['index_page'] ?? null)) {
                continue;
            }

            $collectionKey = trim((string) ($collection['collection_key'] ?? ''));
            if ($collectionKey === '') {
                continue;
            }
            $title = trim((string) ($collection['listing_title'] ?? ''));
            if ($title === '') {
                $title = trim((string) ($collection['name'] ?? ''));
            }
            $intro = trim((string) ($collection['listing_intro'] ?? ''));
            if ($intro === '') {
                $intro = trim((string) ($collection['description'] ?? ''));
            }
            $collectionPath = $this->collectionPath($collection, $locale);
            $localizedUrls = $this->localizedCollectionUrls($collection);

            return [
                'page_type' => 'collection_fallback_index',
                'title' => $title,
                'excerpt' => $intro,
                'showPageHeading' => true,
                'pageTitle' => $title,
                'metaDescription' => $intro,
                'canonicalUrl' => '/' . $locale . '/' . $collectionPath,
                'ogImage' => '',
                'metaRobots' => 'index, follow',
                'schemaData' => null,
                'localized_urls' => $localizedUrls,
                'blocks' => [[
                    'block_key' => 'collection_listing',
                    'block_config' => [
                        'collection_id' => (int) ($collection['id'] ?? 0),
                        'collection_key' => $collectionKey,
                        'items_limit' => 12,
                        'order_by' => 'published_at',
                        'order_direction' => 'desc',
                        'layout_variant' => 'cards',
                    ],
                    'block_data' => [],
                    'children' => [],
                ]],
            ];
        }

        return null;
    }

    /** @return list<array{collection: array<string, mixed>, remainder: string}> */
    private function collectionCandidatesFor(string $locale, string $path): array
    {
        if (! $this->collections instanceof CollectionReaderInterface) {
            return [];
        }

        return $this->collectionCandidates($this->collections->list($locale), $locale, $path);
    }

    /**
     * @param list<array<string, mixed>> $collections
     * @return list<array{collection: array<string, mixed>, remainder: string}>
     */
    private function collectionCandidates(array $collections, string $locale, string $path): array
    {
        $normalizedPath = trim($path, '/');
        if ($normalizedPath === '') {
            return [];
        }

        $candidates = [];
        foreach ($collections as $collection) {
            $prefix = $this->collectionPath($collection, $locale);
            if ($prefix === '') {
                continue;
            }
            if ($normalizedPath === $prefix) {
                $candidates[] = ['collection' => $collection, 'remainder' => ''];
                continue;
            }
            if (str_starts_with($normalizedPath, $prefix . '/')) {
                $candidates[] = [
                    'collection' => $collection,
                    'remainder' => substr($normalizedPath, strlen($prefix) + 1),
                ];
            }
        }

        return $candidates;
    }

    /** @param array<string, mixed> $collection */
    private function collectionPath(array $collection, string $locale): string
    {
        $indexPage = $collection['index_page'] ?? null;
        if (is_array($indexPage)) {
            $localizedSlugs = $indexPage['localized_slugs'] ?? null;
            if (is_array($localizedSlugs)) {
                $slug = trim((string) ($localizedSlugs[$locale] ?? ''), '/');
                if ($slug !== '') {
                    return $slug;
                }
            }
        }

        return trim((string) ($collection['collection_key'] ?? ''), '/');
    }

    /** @param array<string, mixed> $collection
     *  @return array<string, string>
     */
    private function localizedCollectionUrls(array $collection): array
    {
        $localizedSlugs = $collection['localized_slugs'] ?? null;
        $locales = is_array($localizedSlugs) ? array_keys($localizedSlugs) : [];
        $urls = [];
        foreach ($locales as $locale) {
            $locale = (string) $locale;
            $path = $this->collectionPath($collection, $locale);
            if ($path !== '') {
                $urls[$locale] = '/' . $locale . '/' . $path;
            }
        }

        return $urls;
    }

    /**
     * @return array{outcome: 'redirect', redirect: array{path: string, status: int}, page: null, context: array<string, mixed>}
     */
    private function redirectResult(string $path, int $status): array
    {
        return [
            'outcome' => 'redirect',
            'redirect' => ['path' => $path, 'status' => $status],
            'page' => null,
            'context' => [],
        ];
    }
}
