<?php

declare(strict_types=1);

namespace App\PublicRead\Cms;

use App\PublicRead\Page\CollectionReaderInterface;
use CodeIgniter\Database\BaseConnection;

/** Set-based public CMS collection projection. */
final class PublicReadCollectionReader implements CollectionReaderInterface
{
    /** @param BaseConnection<mixed, mixed> $db */
    public function __construct(private readonly BaseConnection $db, private readonly string $fallbackLocale = 'es')
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(string $locale): array
    {
        $locales = new PublicLocaleResolver($this->db, $this->fallbackLocale);
        $all = $locales->all();
        $languageIds = array_values(array_map(static fn (array $row): int => (int) $row['id'], $all['by_code']));
        $query = $this->db->table('cms_collections c')
            ->select('c.*')
            ->where('c.is_active', 1)
            ->orderBy('c.sort_order', 'ASC')->orderBy('c.id', 'ASC')
            ->get();
        $collections = $query !== false ? $query->getResultArray() : [];
        if ($collections === []) {
            return [];
        }

        $ids = array_values(array_map(static fn (array $row): int => (int) $row['id'], $collections));
        $translations = [];
        if ($languageIds !== []) {
            $translationQuery = $this->db->table('cms_collection_translations')
                ->select('collection_id, language_id, slug, name, description, listing_title, listing_intro, default_meta_title, default_meta_description, entry_cta_label')
                ->whereIn('collection_id', $ids)->whereIn('language_id', $languageIds)->get();
            $rows = $translationQuery !== false ? $translationQuery->getResultArray() : [];
            foreach ($rows as $row) {
                $translations[(int) $row['collection_id']][(int) $row['language_id']] = $row;
            }
        }

        $indexPages = $this->indexPages($ids, $all['by_code']);
        $result = [];
        foreach ($collections as $collection) {
            $id = (int) $collection['id'];
            $requestedId = isset($all['by_code'][$locale]) ? (int) $all['by_code'][$locale]['id'] : null;
            $defaultId = isset($all['by_code'][$all['default']]) ? (int) $all['by_code'][$all['default']]['id'] : null;
            $translation = $requestedId !== null ? ($translations[$id][$requestedId] ?? null) : null;
            $isFallback = false;
            if (! is_array($translation) && $defaultId !== null) {
                $translation = $translations[$id][$defaultId] ?? null;
                $isFallback = $translation !== null;
            }
            $translation ??= [];
            $localizedSlugs = [];
            foreach ($all['by_code'] as $code => $language) {
                $slug = trim((string) ($translations[$id][(int) $language['id']]['slug'] ?? ''), '/');
                if ($slug !== '') {
                    $localizedSlugs[$code] = $slug;
                }
            }
            $payload = array_merge($collection, [
                'id' => $id,
                'slug' => $translation['slug'] ?? null,
                'name' => $translation['name'] ?? '',
                'description' => $translation['description'] ?? null,
                'listing_title' => $translation['listing_title'] ?? null,
                'listing_intro' => $translation['listing_intro'] ?? null,
                'default_meta_title' => $translation['default_meta_title'] ?? null,
                'default_meta_description' => $translation['default_meta_description'] ?? null,
                'entry_cta_label' => $translation['entry_cta_label'] ?? null,
                'localized_slugs' => $localizedSlugs,
                'is_fallback' => $isFallback,
                'index_page' => $indexPages[$id] ?? null,
            ]);
            $result[] = $payload;
        }

        return $result;
    }

    /**
     * @param list<int> $collectionIds
     * @param array<string, array<string, mixed>> $languages
     * @return array<int, array<string, mixed>>
     */
    private function indexPages(array $collectionIds, array $languages): array
    {
        if ($collectionIds === []) {
            return [];
        }
        $query = $this->db->table('cms_pages p')
            ->select('p.id, p.collection_id')
            ->whereIn('p.collection_id', $collectionIds)->where('p.page_type', 'collection_index')
            ->where('p.status', 'published')->where('p.deleted_at', null)
            ->orderBy('p.id', 'ASC')->get();
        $pages = $query !== false ? $query->getResultArray() : [];
        if ($pages === []) {
            return [];
        }
        $pageIds = array_map(static fn (array $row): int => (int) $row['id'], $pages);
        $languageIds = array_map(static fn (array $row): int => (int) $row['id'], $languages);
        $translations = [];
        if ($languageIds !== []) {
            $translationQuery = $this->db->table('cms_page_translations')
                ->select('page_id, language_id, slug')
                ->whereIn('page_id', $pageIds)->whereIn('language_id', $languageIds)->get();
            $rows = $translationQuery !== false ? $translationQuery->getResultArray() : [];
            foreach ($rows as $row) {
                $translations[(int) $row['page_id']][(int) $row['language_id']] = trim((string) $row['slug'], '/');
            }
        }
        $result = [];
        foreach ($pages as $page) {
            $pageId = (int) $page['id'];
            $slugs = [];
            foreach ($languages as $code => $language) {
                $slug = $translations[$pageId][(int) $language['id']] ?? '';
                if ($slug !== '') {
                    $slugs[$code] = $slug;
                }
            }
            $result[(int) $page['collection_id']] = [
                'id' => $pageId,
                'localized_slugs' => $slugs,
                'localized_urls' => array_map(static fn (string $slug, string $code): string => '/' . $code . '/' . $slug, $slugs, array_keys($slugs)),
            ];
        }

        return $result;
    }
}
