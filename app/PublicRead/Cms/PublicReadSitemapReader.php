<?php

declare(strict_types=1);

namespace App\PublicRead\Cms;

use App\PublicRead\Support\PublicReadEnvelope;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use dcardenasl\Ci4ApiCore\Support\ApiResult;

/**
 * Bounded CMS sitemap projection.
 *
 * Pages, collections and entries remain separate read models because they do
 * not have one meaningful relational grain. They are loaded set-wise from the
 * same read-only database and merged into one stable public contract; the Web
 * therefore makes one request per sitemap document instead of one request per
 * collection page.
 */
final class PublicReadSitemapReader
{
    private const MAX_URLS = 50000;

    /** @param BaseConnection<mixed, mixed> $db */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly string $fallbackLocale = 'es',
    ) {
    }

    public function show(string $locale): ApiResult
    {
        $locale = strtolower(trim($locale));
        [$languages, $codeById, $defaultLocale] = $this->languages();
        if ($languages === []) {
            return PublicReadEnvelope::success($locale, $this->emptyData(), 'empty', 'cms', meta: [
                'url_count' => 0,
                'truncated' => false,
            ]);
        }

        $pages = $this->loadPages($languages, $codeById, $defaultLocale);
        $collections = $this->loadCollections($languages, $codeById, $pages);
        $entries = $this->loadEntries($languages, $codeById);

        $data = ['pages' => [], 'collections' => [], 'entries' => []];
        $urlCount = 0;
        $truncated = false;
        $add = static function (array &$target, array $item) use (&$urlCount, &$truncated): void {
            if ($urlCount >= self::MAX_URLS) {
                $truncated = true;

                return;
            }
            $target[] = $item;
            $urlCount++;
        };

        foreach ($pages as $page) {
            if ((int) ($page['is_in_sitemap'] ?? 1) !== 1) {
                continue;
            }
            $path = $this->localizedPath($page['translations'] ?? [], $locale, $defaultLocale);
            if ($path === '') {
                continue;
            }
            $add($data['pages'], [
                'id' => (int) $page['id'],
                'page_type' => (string) ($page['page_type'] ?? ''),
                'slug' => $path,
                'localized_slugs' => $this->localizedPathMap($page['translations'] ?? [], $defaultLocale),
                'updated_at' => $page['updated_at'] ?? null,
                'sitemap_changefreq' => $page['sitemap_changefreq'] ?? null,
                'sitemap_priority' => $page['sitemap_priority'] ?? null,
            ]);
        }

        foreach ($collections as $collection) {
            $path = $this->localizedPath($collection['translations'] ?? [], $locale, $defaultLocale);
            if ($path === '') {
                continue;
            }
            $add($data['collections'], [
                'id' => (int) $collection['id'],
                'collection_key' => (string) $collection['collection_key'],
                'slug' => $path,
                'localized_slugs' => $this->localizedPathMap($collection['translations'] ?? [], $defaultLocale),
                'updated_at' => $collection['updated_at'] ?? null,
            ]);
        }

        foreach ($entries as $entry) {
            $slug = $this->localizedPath($entry['translations'] ?? [], $locale, $defaultLocale);
            if ($slug === '') {
                continue;
            }
            $add($data['entries'], [
                'id' => (int) $entry['id'],
                'collection_key' => (string) $entry['collection_key'],
                'slug' => $slug,
                'updated_at' => $entry['updated_at'] ?? null,
                'sitemap_changefreq' => $entry['sitemap_changefreq'] ?? null,
                'sitemap_priority' => $entry['sitemap_priority'] ?? null,
            ]);
        }

        return PublicReadEnvelope::success(
            locale: $locale,
            data: $data,
            sourceRevision: $this->revision($data),
            domain: 'cms',
            meta: [
                'url_count' => $urlCount,
                'truncated' => $truncated,
                'max_urls' => self::MAX_URLS,
            ],
        );
    }

    /** @return array{pages:list<array<string,mixed>>,collections:list<array<string,mixed>>,entries:list<array<string,mixed>>} */
    private function emptyData(): array
    {
        return ['pages' => [], 'collections' => [], 'entries' => []];
    }

    /** @return array{0:list<array<string,mixed>>,1:array<int,string>,2:string} */
    private function languages(): array
    {
        $query = $this->db->table('cms_languages')
            ->select('id, code, is_default')
            ->where('is_active', 1)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->get();
        $rows = $query !== false ? array_values($query->getResultArray()) : [];
        $codeById = [];
        $default = strtolower($this->fallbackLocale);
        foreach ($rows as &$row) {
            $id = (int) ($row['id'] ?? 0);
            $code = strtolower(trim((string) ($row['code'] ?? '')));
            $row['id'] = $id;
            $row['code'] = $code;
            $codeById[$id] = $code;
            if ((int) ($row['is_default'] ?? 0) === 1) {
                $default = $code;
            }
        }
        unset($row);

        return [$rows, $codeById, $default];
    }

    /**
     * @param list<array<string,mixed>> $languages
     * @param array<int,string> $codeById
     * @param string $defaultLocale
     * @return list<array<string,mixed>>
     */
    private function loadPages(array $languages, array $codeById, string $defaultLocale): array
    {
        $languageIds = array_keys($codeById);
        $builder = $this->db->table('cms_pages p')
            ->select('p.id, p.parent_id, p.collection_id, p.page_type, p.status, p.updated_at, p.sitemap_priority, p.sitemap_changefreq, p.is_in_sitemap, pt.language_id, pt.slug')
            ->join('cms_page_translations pt', 'pt.page_id = p.id', 'inner')
            ->where('p.status', 'published')
            ->where('p.deleted_at', null)
            ->whereIn('pt.language_id', $languageIds)
            ->orderBy('p.sort_order', 'ASC')
            ->orderBy('p.id', 'ASC');
        $this->applyEffectivePublication($builder, 'p.');
        $query = $builder->get();
        $rows = $query !== false ? $query->getResultArray() : [];
        $pages = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $pages[$id] ??= [
                'id' => $id,
                'parent_id' => $row['parent_id'] === null ? null : (int) $row['parent_id'],
                'collection_id' => $row['collection_id'] === null ? null : (int) $row['collection_id'],
                'page_type' => (string) ($row['page_type'] ?? ''),
                'updated_at' => $row['updated_at'] ?? null,
                'sitemap_priority' => $row['sitemap_priority'] ?? null,
                'sitemap_changefreq' => $row['sitemap_changefreq'] ?? null,
                'is_in_sitemap' => $row['is_in_sitemap'] ?? 1,
                'translations' => [],
            ];
            $code = $codeById[(int) ($row['language_id'] ?? 0)] ?? '';
            if ($code !== '') {
                $pages[$id]['translations'][$code] = ['slug' => trim((string) ($row['slug'] ?? ''), '/')];
            }
        }

        $pages = array_values($pages);
        $pageById = [];
        foreach ($pages as $page) {
            $pageById[(int) $page['id']] = $page;
        }
        foreach ($pages as &$page) {
            $page['translations'] = $this->pathTranslations((int) $page['id'], $pageById, $codeById, $page['translations'], $defaultLocale);
        }
        unset($page);

        return $pages;
    }

    /**
     * @param list<array<string,mixed>> $languages
     * @param array<int,string> $codeById
     * @param list<array<string,mixed>> $pages
     * @return list<array<string,mixed>>
     */
    private function loadCollections(array $languages, array $codeById, array $pages): array
    {
        $query = $this->db->table('cms_collections c')
            ->select('c.id, c.collection_key, c.updated_at, ct.language_id, ct.slug')
            ->join('cms_collection_translations ct', 'ct.collection_id = c.id', 'inner')
            ->where('c.is_active', 1)
            ->whereIn('ct.language_id', array_keys($codeById))
            ->orderBy('c.sort_order', 'ASC')
            ->orderBy('c.id', 'ASC')
            ->get();
        $rows = $query !== false ? $query->getResultArray() : [];
        $indexPaths = [];
        foreach ($pages as $page) {
            if ((string) ($page['page_type'] ?? '') !== 'collection_index') {
                continue;
            }
            $indexPaths[(int) ($page['collection_id'] ?? 0)] = $page['translations'] ?? [];
        }
        $collections = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $collections[$id] ??= [
                'id' => $id,
                'collection_key' => (string) ($row['collection_key'] ?? ''),
                'updated_at' => $row['updated_at'] ?? null,
                'translations' => [],
            ];
            $code = $codeById[(int) ($row['language_id'] ?? 0)] ?? '';
            if ($code !== '') {
                $collections[$id]['translations'][$code] = ['slug' => trim((string) ($row['slug'] ?? ''), '/')];
            }
            if (isset($indexPaths[$id])) {
                $collections[$id]['translations'] = $indexPaths[$id];
            }
        }

        return array_values($collections);
    }

    /**
     * @param list<array<string,mixed>> $languages
     * @param array<int,string> $codeById
     * @return list<array<string,mixed>>
     */
    private function loadEntries(array $languages, array $codeById): array
    {
        $builder = $this->db->table('cms_entries e')
            ->select('e.id, e.collection_id, e.updated_at, e.sitemap_priority, e.sitemap_changefreq, c.collection_key, et.language_id, et.slug')
            ->join('cms_collections c', 'c.id = e.collection_id AND c.is_active = 1', 'inner')
            ->join('cms_entry_translations et', 'et.entry_id = e.id', 'inner')
            ->where('e.deleted_at', null)
            ->where('e.workflow_status', 'published')
            ->where('e.is_in_sitemap', 1)
            ->whereIn('et.language_id', array_keys($codeById))
            ->orderBy('e.collection_id', 'ASC')
            ->orderBy('e.sort_order', 'ASC')
            ->orderBy('e.id', 'ASC');
        $this->applyEffectivePublication($builder, 'e.');
        $query = $builder->get();
        $rows = $query !== false ? $query->getResultArray() : [];
        $entries = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $entries[$id] ??= [
                'id' => $id,
                'collection_key' => (string) ($row['collection_key'] ?? ''),
                'updated_at' => $row['updated_at'] ?? null,
                'sitemap_priority' => $row['sitemap_priority'] ?? null,
                'sitemap_changefreq' => $row['sitemap_changefreq'] ?? null,
                'translations' => [],
            ];
            $code = $codeById[(int) ($row['language_id'] ?? 0)] ?? '';
            if ($code !== '') {
                $entries[$id]['translations'][$code] = ['slug' => trim((string) ($row['slug'] ?? ''), '/')];
            }
        }

        return array_values($entries);
    }

    /**
     * @param array<int,array<string,mixed>> $pageById
     * @param array<int,string> $codeById
     * @param array<string,array<string,mixed>> $ownTranslations
     * @param string $defaultLocale
     * @return array<string,array<string,mixed>>
     */
    private function pathTranslations(int $pageId, array $pageById, array $codeById, array $ownTranslations, string $defaultLocale): array
    {
        $result = [];
        foreach (array_values($codeById) as $code) {
            $segments = [];
            $current = $pageId;
            $visited = [];
            while ($current > 0 && ! isset($visited[$current])) {
                $visited[$current] = true;
                $page = $pageById[$current] ?? null;
                if ($page === null) {
                    $segments = [];
                    break;
                }
                $slug = trim((string) ($page['translations'][$code]['slug'] ?? ''), '/');
                if ($slug === '') {
                    $slug = trim((string) ($page['translations'][$defaultLocale]['slug'] ?? ''), '/');
                }
                if ($slug === '') {
                    $segments = [];
                    break;
                }
                array_unshift($segments, $slug);
                $current = (int) ($page['parent_id'] ?? 0);
            }
            if ($segments !== []) {
                $result[$code] = ['slug' => implode('/', $segments)];
            }
        }

        return $result !== [] ? $result : $ownTranslations;
    }

    /** @param array<string,array<string,mixed>> $translations */
    private function localizedPath(array $translations, string $locale, string $defaultLocale): string
    {
        return trim((string) (($translations[$locale]['slug'] ?? '') ?: ($translations[$defaultLocale]['slug'] ?? '')), '/');
    }

    /**
     * @param array<string,array<string,mixed>> $translations
     * @return array<string,string>
     */
    private function localizedPathMap(array $translations, string $defaultLocale): array
    {
        $result = [];
        foreach ($translations as $code => $translation) {
            $slug = trim((string) ($translation['slug'] ?? ''), '/');
            if ($slug !== '') {
                $result[(string) $code] = $slug;
            }
        }
        if ($result === [] && $defaultLocale !== '') {
            $fallback = $this->localizedPath($translations, $defaultLocale, $defaultLocale);
            if ($fallback !== '') {
                $result[$defaultLocale] = $fallback;
            }
        }

        return $result;
    }

    private function applyEffectivePublication(BaseBuilder $builder, string $prefix): void
    {
        $now = date('Y-m-d H:i:s');
        $builder->groupStart()
            ->where($prefix . 'published_at IS NULL', null, false)
            ->orWhere($prefix . 'published_at <=', $now)
            ->groupEnd()
            ->groupStart()
            ->where($prefix . 'scheduled_at IS NULL', null, false)
            ->orWhere($prefix . 'scheduled_at <=', $now)
            ->groupEnd();
    }

    /** @param array<string,list<array<string,mixed>>> $data */
    private function revision(array $data): string
    {
        return sha1((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
