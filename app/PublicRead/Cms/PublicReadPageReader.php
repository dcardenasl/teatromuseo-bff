<?php

declare(strict_types=1);

namespace App\PublicRead\Cms;

use App\PublicRead\Page\PageCandidateReaderInterface;
use App\PublicRead\Page\PageReaderInterface;
use App\PublicRead\Support\PublicReadEnvelope;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use dcardenasl\Ci4ApiCore\Support\ApiResult;

/** Set-based CMS page reader with batch block serialization. */
final class PublicReadPageReader implements PageReaderInterface, PageCandidateReaderInterface
{
    private const FALLBACK_LOCALE = 'es';

    /** @var list<string> Singleton page types exposed to the public site. */
    private const PAGE_TEMPLATE_TYPES = ['template_catalog_item', 'template_event_item'];

    /** @var list<string> Page types accepted by the by-type public lookup. */
    private const PUBLIC_BY_TYPE = ['home', ...self::PAGE_TEMPLATE_TYPES];

    /** @param BaseConnection<mixed, mixed> $db */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly BlockInstanceSerializer $blockSerializer,
        private readonly ?FileUrlResolver $fileUrlResolver = null,
        private readonly string $fallbackLocale = self::FALLBACK_LOCALE,
    ) {
    }

    /** @param list<string> $fields */
    public function index(PublicReadPageRequestDTO $request, array $fields): ApiResult
    {
        [$pages, $translations, $languages, $defaultLocale] = $this->loadPublicGraph();
        $pathMap = $this->buildPathMap($pages, $translations, $languages, $request->locale, $defaultLocale);
        $data = [];
        foreach ($pages as $page) {
            $id = (int) $page['id'];
            if ((int) ($page['is_in_sitemap'] ?? 1) !== 1 || !isset($pathMap[$id])) {
                continue;
            }
            $translation = $this->resolveTranslation($translations[$id] ?? [], $request->locale, $defaultLocale);
            $data[] = $this->filterFields([
                'id' => $id,
                'page_type' => $page['page_type'],
                'title' => $translation['title'] ?? '',
                'excerpt' => $translation['excerpt'] ?? null,
                'slug' => $pathMap[$id]['path'],
                'localized_slugs' => $pathMap[$id]['localized'],
                'sitemap_priority' => $page['sitemap_priority'],
                'sitemap_changefreq' => $page['sitemap_changefreq'],
                'is_in_sitemap' => (bool) $page['is_in_sitemap'],
                'updated_at' => $page['updated_at'],
            ], $fields);
        }

        $total = count($data);
        $data = array_slice($data, ($request->page - 1) * $request->perPage, $request->perPage);

        return PublicReadEnvelope::success(
            locale: $request->locale,
            data: $data,
            sourceRevision: $this->revision($pages),
            page: $request->page,
            perPage: $request->perPage,
            total: $total,
            domain: 'cms',
            meta: [
                'fields' => $fields,
                'query' => ['page' => $request->page, 'per_page' => $request->perPage],
            ],
        );
    }

    /** @param list<string> $fields */
    public function show(string $locale, string $path, array $fields, bool $preview = false): ApiResult
    {
        return $this->showAny($locale, [$path], $fields, $preview);
    }

    /**
     * Resolve equivalent localized paths with one set-based candidate read.
     *
     * @param list<string> $paths
     * @param list<string> $fields
     */
    public function showAny(string $locale, array $paths, array $fields, bool $preview = false): ApiResult
    {
        $normalizedPaths = [];
        foreach ($paths as $path) {
            $normalized = trim($path, '/');
            $normalized = $normalized === '' ? 'home' : $normalized;
            if (! in_array($normalized, $normalizedPaths, true)) {
                $normalizedPaths[] = $normalized;
            }
        }

        if ($normalizedPaths === []) {
            return $this->notFound($locale);
        }

        [$languages, $codeById, $defaultLocale] = $this->loadPublicLanguages();
        $languageIds = array_keys($codeById);
        $segments = [];
        foreach ($normalizedPaths as $path) {
            foreach (explode('/', $path) as $segment) {
                if ($segment !== '' && ! in_array($segment, $segments, true)) {
                    $segments[] = $segment;
                }
            }
        }
        if ($languageIds === [] || $segments === []) {
            return $this->notFound($locale);
        }

        $candidateBuilder = $this->db->table('cms_pages p')
            ->select('p.id, p.parent_id, p.collection_id, p.page_type, p.published_at, p.sort_order, p.sitemap_priority, p.sitemap_changefreq, p.is_in_sitemap, p.updated_at, pt.language_id, pt.slug, pt.title, pt.excerpt, pt.meta_title, pt.meta_description, pt.og_image_file_id, pt.og_image_url, pt.og_type, pt.canonical_url, pt.robots, pt.schema_data')
            ->join('cms_page_translations pt', 'pt.page_id = p.id')
            ->where('p.deleted_at', null)
            ->whereIn('pt.language_id', $languageIds)
            ->groupStart()
                ->whereIn('pt.slug', $segments)
                ->orWhereIn('pt.slug', $normalizedPaths)
            ->groupEnd();
        if (! $preview) {
            $candidateBuilder->where('p.status', 'published');
            $this->applyEffectivePublication($candidateBuilder, 'p.');
        }
        $candidateQuery = $candidateBuilder->get();
        $candidateRows = $candidateQuery !== false ? $candidateQuery->getResultArray() : [];
        if ($candidateRows === []) {
            return $this->notFound($locale);
        }

        $pages = [];
        $translations = [];
        $codeById = array_map('strval', $codeById);
        foreach ($candidateRows as $row) {
            $pageId = (int) $row['id'];
            $pages[$pageId] ??= [
                'id' => $pageId,
                'parent_id' => $row['parent_id'],
                'collection_id' => $row['collection_id'],
                'page_type' => $row['page_type'],
                'published_at' => $row['published_at'],
                'sort_order' => $row['sort_order'],
                'sitemap_priority' => $row['sitemap_priority'],
                'sitemap_changefreq' => $row['sitemap_changefreq'],
                'is_in_sitemap' => $row['is_in_sitemap'],
                'updated_at' => $row['updated_at'],
            ];
            $language = $codeById[(int) $row['language_id']] ?? '';
            if ($language !== '') {
                $translations[$pageId][$language] = $row;
            }
        }

        $pathMap = $this->buildPathMap(array_values($pages), $translations, $languages, $locale, $defaultLocale);
        $pageId = null;
        foreach ($normalizedPaths as $candidatePath) {
            foreach ($pathMap as $candidateId => $pathData) {
                if ($pathData['path'] === $candidatePath) {
                    $pageId = (int) $candidateId;
                    break 2;
                }
            }
        }
        if ($pageId === null || !isset($pages[$pageId])) {
            return $this->notFound($locale);
        }

        // The matching query only needs path segments. Fetch all translations
        // for the resolved ancestor chain once so localized_slugs stays
        // complete without reopening the full public graph.
        $ancestorIds = $this->ancestorIds($pageId, $pages);
        $localizedQuery = $this->db->table('cms_page_translations')
            ->select('page_id, language_id, slug, title, excerpt, meta_title, meta_description, og_image_file_id, og_image_url, og_type, canonical_url, robots, schema_data, updated_at')
            ->whereIn('page_id', $ancestorIds)
            ->whereIn('language_id', $languageIds)
            ->get();
        $localizedRows = $localizedQuery !== false ? $localizedQuery->getResultArray() : [];
        foreach ($localizedRows as $row) {
            $id = (int) $row['page_id'];
            $language = $codeById[(int) $row['language_id']] ?? '';
            if ($language !== '') {
                $translations[$id][$language] = $row;
            }
        }
        $pathMap = $this->buildPathMap(array_values($pages), $translations, $languages, $locale, $defaultLocale);
        $page = $pages[$pageId];
        if (! $preview && in_array((string) $page['page_type'], self::PAGE_TEMPLATE_TYPES, true)) {
            return $this->notFound($locale);
        }
        $translation = $this->normalizePageTranslation(
            $this->resolveTranslation($translations[$pageId] ?? [], $locale, $defaultLocale),
        );
        $payload = [
            'id' => $pageId,
            'parent_id' => $page['parent_id'],
            'collection_id' => $page['collection_id'],
            'page_type' => $page['page_type'],
            'published_at' => $page['published_at'],
            'sort_order' => (int) $page['sort_order'],
            'sitemap_priority' => $page['sitemap_priority'],
            'sitemap_changefreq' => $page['sitemap_changefreq'],
            'is_in_sitemap' => (bool) $page['is_in_sitemap'],
            'title' => $translation['title'] ?? '',
            'excerpt' => $translation['excerpt'] ?? null,
            'meta_title' => $translation['meta_title'] ?? null,
            'meta_description' => $translation['meta_description'] ?? null,
            'og_image' => $translation['og_image'] ?? null,
            'og_type' => $translation['og_type'] ?? null,
            'canonical_url' => $translation['canonical_url'] ?? null,
            'robots' => $translation['robots'] ?? null,
            'schema_data' => $translation['schema_data'] ?? null,
            'localized_slugs' => $pathMap[$pageId]['localized'] ?? [],
            'updated_at' => $page['updated_at'],
        ];
        if ($fields === [] || in_array('blocks', $fields, true)) {
            $payload['blocks'] = $this->blockSerializer->forContent('page', (int) $pageId, $locale);
        }

        $query = count($normalizedPaths) === 1
            ? ['path' => $normalizedPaths[0], 'preview' => $preview]
            : ['paths' => $normalizedPaths, 'preview' => $preview];

        return PublicReadEnvelope::success(
            locale: $locale,
            data: $this->filterFields($payload, $fields),
            sourceRevision: $this->revision([$page]),
            domain: 'cms',
            meta: [
                'fields' => $fields,
                'query' => $query,
            ],
        );
    }

    /**
     * Resolve a singleton template page by its stable page_type contract.
     *
     * The public site uses these pages as shells for catalog and event detail
     * rendering. They intentionally do not participate in slug resolution.
     */
    public function byType(string $locale, string $type): ApiResult
    {
        if (! in_array($type, self::PUBLIC_BY_TYPE, true)) {
            return $this->notFound($locale);
        }

        $pageBuilder = $this->db->table('cms_pages')
            ->select('id, parent_id, collection_id, page_type, status, published_at, scheduled_at, sort_order, sitemap_priority, sitemap_changefreq, is_in_sitemap, created_at, updated_at')
            ->where('page_type', $type)
            ->where('status', 'published')
            ->where('deleted_at', null)
            ->orderBy('id', 'ASC');
        $this->applyEffectivePublication($pageBuilder);
        $pageQuery = $pageBuilder->limit(1)->get();
        $page = $pageQuery !== false ? $pageQuery->getRowArray() : null;
        if ($page === null) {
            return $this->notFound($locale);
        }

        [, $codeById, $defaultLocale] = $this->loadPublicLanguages();
        $languageIds = array_keys($codeById);
        $translationBuilder = $this->db->table('cms_page_translations')
            ->select('language_id, slug, title, excerpt, meta_title, meta_description, og_image_file_id, og_image_url, og_type, canonical_url, robots, schema_data, updated_at')
            ->where('page_id', (int) $page['id']);
        if ($languageIds !== []) {
            $translationBuilder->whereIn('language_id', $languageIds);
        }
        $translationQuery = $translationBuilder->get();
        $translations = [];
        $localizedSlugs = [];
        $translationRows = $translationQuery !== false ? $translationQuery->getResultArray() : [];
        foreach ($translationRows as $translation) {
            $language = $codeById[(int) $translation['language_id']] ?? '';
            if ($language === '') {
                continue;
            }
            $translations[$language] = $translation;
            $localizedSlugs[$language] = (string) ($translation['slug'] ?? '');
        }

        $translation = $this->normalizePageTranslation(
            $this->resolveTranslation($translations, $locale, $defaultLocale),
        );
        $payload = array_merge($page, $translation);
        $payload['id'] = (int) $page['id'];
        $payload['blocks'] = $this->blockSerializer->forContent('page', (int) $page['id'], $locale);
        $payload['localized_slugs'] = $localizedSlugs;

        return PublicReadEnvelope::success(
            locale: $locale,
            data: $payload,
            sourceRevision: $this->revision([$page]),
            domain: 'cms',
            meta: ['fields' => [], 'query' => ['type' => $type]],
        );
    }

    /** @return array{0: list<array<string, mixed>>, 1: array<int, array<string, array<string, mixed>>>, 2: list<array<string, mixed>>, 3: string} */
    private function loadPublicGraph(): array
    {
        $languageQuery = $this->db->table('cms_languages')
            ->select('id, code, is_default')->where('is_active', 1)->get();
        $languageRows = $languageQuery !== false ? array_values($languageQuery->getResultArray()) : [];
        $codeById = [];
        $defaultLocale = strtolower($this->fallbackLocale);
        foreach ($languageRows as $language) {
            $code = strtolower((string) $language['code']);
            $codeById[(int) $language['id']] = $code;
            if ((int) $language['is_default'] === 1) {
                $defaultLocale = $code;
            }
        }
        $languageIds = array_values(array_map(static fn (array $language): int => (int) $language['id'], $languageRows));
        $pageBuilder = $this->db->table('cms_pages')
            ->select('id, parent_id, collection_id, page_type, status, published_at, sort_order, sitemap_priority, sitemap_changefreq, is_in_sitemap, created_at, updated_at')
            ->where('status', 'published')->where('deleted_at', null)
            ->orderBy('sort_order', 'ASC')->orderBy('id', 'ASC');
        $this->applyEffectivePublication($pageBuilder);
        $pageQuery = $pageBuilder->get();
        $pages = $pageQuery !== false ? array_values($pageQuery->getResultArray()) : [];
        if ($pages === []) {
            return [[], [], $languageRows, $defaultLocale];
        }

        $pageIds = array_map(static fn (array $page): int => (int) $page['id'], $pages);
        $translationBuilder = $this->db->table('cms_page_translations')
            ->select('page_id, language_id, slug, title, excerpt, meta_title, meta_description, canonical_url, robots, updated_at')
            ->whereIn('page_id', $pageIds);
        if ($languageIds !== []) {
            $translationBuilder->whereIn('language_id', $languageIds);
        }
        // Load all active-language slugs in this single set query. The requested
        // and default locales still control the content fallback, while the
        // complete map lets the response expose stable localized_slugs without
        // querying a translation inside the hierarchy loop.
        $translations = [];
        $translationQuery = $translationBuilder->get();
        $translationRows = $translationQuery !== false ? $translationQuery->getResultArray() : [];
        foreach ($translationRows as $translation) {
            $translation['locale'] = $codeById[(int) $translation['language_id']] ?? '';
            $translations[(int) $translation['page_id']][(string) $translation['locale']] = $translation;
        }

        return [$pages, $translations, $languageRows, $defaultLocale];
    }

    /** @return array{0: list<array<string, mixed>>, 1: array<int, string>, 2: string} */
    private function loadPublicLanguages(): array
    {
        $query = $this->db->table('cms_languages')
            ->select('id, code, is_default')
            ->where('is_active', 1)
            ->get();
        $rows = $query !== false ? array_values($query->getResultArray()) : [];
        $codeById = [];
        $defaultLocale = strtolower($this->fallbackLocale);
        foreach ($rows as $row) {
            $code = strtolower((string) $row['code']);
            $codeById[(int) $row['id']] = $code;
            if ((int) $row['is_default'] === 1) {
                $defaultLocale = $code;
            }
        }

        return [$rows, $codeById, $defaultLocale];
    }

    private function applyEffectivePublication(BaseBuilder $builder, string $prefix = ''): void
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

    /**
     * @param array<int, array<string, mixed>> $pages
     * @return list<int>
     */
    private function ancestorIds(int $pageId, array $pages): array
    {
        $ids = [];
        $current = $pageId;
        while ($current > 0 && !in_array($current, $ids, true)) {
            $ids[] = $current;
            $parentId = $pages[$current]['parent_id'] ?? null;
            $current = $parentId === null ? 0 : (int) $parentId;
        }

        return $ids;
    }

    /**
     * @param list<array<string, mixed>> $pages
     * @param array<int, array<string, array<string, mixed>>> $translations
     * @param list<array<string, mixed>> $languages
     * @return array<int, array{path: string, localized: array<string, string>}>
     */
    private function buildPathMap(array $pages, array $translations, array $languages, string $locale, string $defaultLocale): array
    {
        $pageMap = [];
        foreach ($pages as $page) {
            $pageMap[(int) $page['id']] = $page;
        }
        $codes = [$locale, $defaultLocale];
        foreach ($languages as $language) {
            $codes[] = strtolower((string) $language['code']);
        }
        $result = [];
        foreach ($pageMap as $id => $page) {
            $localized = [];
            foreach (array_values(array_unique($codes)) as $code) {
                $segments = [];
                $current = $id;
                $visited = [];
                while ($current !== null && !isset($visited[$current])) {
                    $visited[$current] = true;
                    $pageRow = $pageMap[$current] ?? null;
                    if ($pageRow === null) {
                        $segments = [];
                        break;
                    }
                    $translation = $this->resolveTranslation($translations[$current] ?? [], $code, $defaultLocale);
                    $slug = trim((string) ($translation['slug'] ?? ''));
                    if ($slug === '') {
                        $segments = [];
                        break;
                    }
                    array_unshift($segments, $slug);
                    $current = $pageRow['parent_id'] !== null ? (int) $pageRow['parent_id'] : null;
                }
                if ($segments !== []) {
                    $localized[$code] = implode('/', $segments);
                }
            }
            $path = $localized[$locale] ?? $localized[$defaultLocale] ?? '';
            if ($path !== '') {
                $result[$id] = ['path' => $path, 'localized' => $localized];
            }
        }

        return $result;
    }

    /**
     * @param array<string, array<string, mixed>> $translations
     * @return array<string, mixed>
     */
    private function resolveTranslation(array $translations, string $locale, string $defaultLocale): array
    {
        return $translations[$locale] ?? $translations[$defaultLocale] ?? [];
    }

    /**
     * @param array<string, mixed> $translation
     * @return array<string, mixed>
     */
    private function normalizePageTranslation(array $translation): array
    {
        if ($this->fileUrlResolver !== null) {
            return $this->fileUrlResolver->normalizePageTranslation($translation);
        }

        $url = trim((string) ($translation['og_image_url'] ?? ''));
        if ($url !== '') {
            $translation['og_image'] = ['url' => $url];
        }

        return $translation;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $fields
     * @return array<string, mixed>
     */
    private function filterFields(array $payload, array $fields): array
    {
        return $fields === [] ? $payload : array_intersect_key($payload, array_flip($fields));
    }

    /** @param list<array<string, mixed>> $rows */
    private function revision(array $rows): string
    {
        $updated = '';
        $maxId = 0;
        foreach ($rows as $row) {
            $updated = max($updated, (string) ($row['updated_at'] ?? ''));
            $maxId = max($maxId, (int) ($row['id'] ?? 0));
        }

        return 'cms:' . ($updated !== '' ? $updated : 'empty') . ':' . $maxId;
    }

    private function notFound(string $locale): ApiResult
    {
        return new ApiResult([
            'version' => 1,
            'ok' => false,
            'data' => null,
            'meta' => ['locale' => $locale, 'source_revision' => 'cms:empty'],
            'source' => ['domain' => 'cms', 'state' => 'unavailable', 'stale' => false],
            'messages' => ['Page not found.'],
        ], 404);
    }
}
