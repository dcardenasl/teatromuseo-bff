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
    ) {
    }

    /**
     * @return array{
     *     outcome: 'redirect'|'page'|'not_found',
     *     redirect: array{path: string, status: int}|null,
     *     page: array<string, mixed>|null,
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

        $entryPage = $this->entryFor($locale, $path);
        if ($entryPage !== null) {
            return $this->pageResult($entryPage, 'collection_entry');
        }

        return ['outcome' => 'not_found', 'redirect' => null, 'page' => null];
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
     * @return array{outcome: 'page', redirect: null, page: array<string, mixed>}
     */
    private function pageResult(array $page, string $pageType = 'cms_page'): array
    {
        if ($pageType === 'cms_page') {
            $sourcePageType = (string) ($page['page_type'] ?? '');
            $page['source_page_type'] = $sourcePageType;
        }
        $page['page_type'] = $pageType;

        return ['outcome' => 'page', 'redirect' => null, 'page' => $page];
    }

    /** @return array<string, mixed>|null */
    private function entryFor(string $locale, string $path): ?array
    {
        if (! $this->collections instanceof CollectionReaderInterface || ! $this->entries instanceof EntryReaderInterface) {
            return null;
        }

        foreach ($this->collectionCandidates($this->collections->list($locale), $locale, $path) as $candidate) {
            if ($candidate['remainder'] === '') {
                continue;
            }

            $collection = $candidate['collection'];
            $collectionKey = trim((string) ($collection['collection_key'] ?? ''));
            if ($collectionKey === '') {
                continue;
            }
            $result = $this->entries->show($locale, $collectionKey, $candidate['remainder'], []);
            $entry = $result->body['data'] ?? null;
            if (($result->body['ok'] ?? false) !== true || ! is_array($entry)) {
                continue;
            }

            try {
                $entry['related_entries'] = $this->entries->related($locale, $collectionKey, $entry, 3);
            } catch (Throwable) {
                $entry['related_entries'] = [];
            }
            $entry['collection'] = $collection;

            return $entry;
        }

        return null;
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

    /** @return array{outcome: 'redirect', redirect: array{path: string, status: int}, page: null} */
    private function redirectResult(string $path, int $status): array
    {
        return ['outcome' => 'redirect', 'redirect' => ['path' => $path, 'status' => $status], 'page' => null];
    }
}
