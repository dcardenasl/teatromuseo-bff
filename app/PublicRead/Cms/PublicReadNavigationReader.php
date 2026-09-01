<?php

declare(strict_types=1);

namespace App\PublicRead\Cms;

use App\PublicRead\Support\PublicReadEnvelope;
use CodeIgniter\Database\BaseConnection;
use dcardenasl\Ci4ApiCore\Support\ApiResult;

/** Set-based public navigation reader for all menu locations. */
final class PublicReadNavigationReader
{
    private const FALLBACK_LOCALE = 'es';

    /** @param BaseConnection<mixed, mixed> $db */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly string $fallbackLocale = self::FALLBACK_LOCALE,
    ) {
    }

    public function show(string $locale): ApiResult
    {
        $languagesQuery = $this->db->table('cms_languages')
            ->select('id, code, is_default')
            ->where('is_active', 1)
            ->get();
        $languages = $languagesQuery !== false ? $languagesQuery->getResultArray() : [];
        $codeById = [];
        $default = strtolower($this->fallbackLocale);
        foreach ($languages as $language) {
            $code = strtolower((string) $language['code']);
            $codeById[(int) $language['id']] = $code;
            if ((int) $language['is_default'] === 1) {
                $default = $code;
            }
        }

        $menuQuery = $this->db->table('cms_menus')
            ->select('id, menu_key, location, updated_at')
            ->where('is_active', 1)
            ->where('deleted_at', null)
            ->orderBy('id', 'ASC')
            ->get();
        $menus = $menuQuery !== false ? $menuQuery->getResultArray() : [];
        $menuIds = array_map(static fn (array $row): int => (int) $row['id'], $menus);
        if ($menuIds === []) {
            return PublicReadEnvelope::success($locale, ['main' => null, 'footer' => null, 'legal' => null], 'cms-navigation:empty', domain: 'cms', meta: ['fields' => []]);
        }

        $languageIds = $this->languageIds($languages, $locale, $default);
        $menuTranslations = $this->rowsByLanguage(
            $this->db->table('cms_menu_translations')->select('menu_id, language_id, name')->whereIn('menu_id', $menuIds)->whereIn('language_id', $languageIds)->get(),
            $codeById,
            'menu_id',
        );

        $itemQuery = $this->db->table('cms_menu_items mi')
            ->select('mi.id, mi.menu_id, mi.parent_id, mi.link_type, mi.page_id, mi.entry_id, mi.collection_id, mi.link_target, mi.icon, mi.css_class, mi.sort_order, mi.updated_at, p.page_type, p.status AS page_status, p.deleted_at AS page_deleted_at, p.published_at AS page_published_at, p.scheduled_at AS page_scheduled_at, e.collection_id AS entry_collection_id, e.workflow_status AS entry_status, e.deleted_at AS entry_deleted_at, e.published_at AS entry_published_at, e.scheduled_at AS entry_scheduled_at, c.is_active AS collection_active, ec.is_active AS entry_collection_active')
            ->join('cms_pages p', 'p.id = mi.page_id', 'left')
            ->join('cms_entries e', 'e.id = mi.entry_id', 'left')
            ->join('cms_collections c', 'c.id = mi.collection_id', 'left')
            ->join('cms_collections ec', 'ec.id = e.collection_id', 'left')
            ->whereIn('mi.menu_id', $menuIds)
            ->where('mi.is_active', 1)
            ->orderBy('mi.menu_id', 'ASC')->orderBy('mi.sort_order', 'ASC')->orderBy('mi.id', 'ASC')
            ->get();
        $items = $itemQuery !== false ? $itemQuery->getResultArray() : [];
        $items = array_values(array_filter($items, fn (array $item): bool => $this->isPublicNavigationTarget($item)));
        $itemIds = array_map(static fn (array $row): int => (int) $row['id'], $items);
        $itemTranslations = $itemIds === [] ? [] : $this->rowsByLanguage(
            $this->db->table('cms_menu_item_translations')->select('menu_item_id, language_id, label, custom_url')->whereIn('menu_item_id', $itemIds)->whereIn('language_id', $languageIds)->get(),
            $codeById,
            'menu_item_id',
        );

        $pageIds = array_values(array_unique(array_filter(array_map(static fn (array $row): int => (int) ($row['page_id'] ?? 0), $items))));
        $pageSlugs = $pageIds === [] ? [] : $this->slugMap('cms_page_translations', 'page_id', $pageIds, $languageIds, $codeById);
        $entryIds = array_values(array_unique(array_filter(array_map(static fn (array $row): int => (int) ($row['entry_id'] ?? 0), $items))));
        $entrySlugs = $entryIds === [] ? [] : $this->slugMap('cms_entry_translations', 'entry_id', $entryIds, $languageIds, $codeById);
        $collectionIds = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['collection_id'] ?? $row['entry_collection_id'] ?? 0),
            $items,
        ))));
        $collectionSlugs = $collectionIds === [] ? [] : $this->slugMap(
            'cms_collection_translations',
            'collection_id',
            $collectionIds,
            $languageIds,
            $codeById,
        );
        $collectionIndexSlugs = $collectionIds === [] ? [] : $this->collectionIndexSlugMap(
            $collectionIds,
            $languageIds,
            $codeById,
        );

        $result = ['main' => null, 'footer' => null, 'legal' => null];
        foreach ($menus as $menu) {
            $menuId = (int) $menu['id'];
            $menuKey = trim((string) $menu['menu_key']);
            if ($menuKey === '' || ($result[$menuKey] ?? null) !== null) {
                continue;
            }

            $flat = [];
            foreach ($items as $item) {
                if ((int) $item['menu_id'] !== $menuId) {
                    continue;
                }
                $itemId = (int) $item['id'];
                $translation = $this->pick($itemTranslations[$itemId] ?? [], $locale, $default);
                $pageId = (int) ($item['page_id'] ?? 0);
                $entryId = (int) ($item['entry_id'] ?? 0);
                $linkType = (string) $item['link_type'];
                $collectionId = (int) ($item['collection_id'] ?? $item['entry_collection_id'] ?? 0);
                $collectionSlug = $collectionIndexSlugs[$collectionId][$locale]
                    ?? $collectionIndexSlugs[$collectionId][$default]
                    ?? $collectionSlugs[$collectionId][$locale]
                    ?? $collectionSlugs[$collectionId][$default]
                    ?? null;
                if ($linkType === 'page') {
                    $slug = $pageSlugs[$pageId][$locale] ?? $pageSlugs[$pageId][$default] ?? null;
                } elseif ($linkType === 'entry') {
                    $slug = $entrySlugs[$entryId][$locale] ?? $entrySlugs[$entryId][$default] ?? null;
                } else {
                    $slug = $collectionSlug;
                }
                $flat[] = [
                    'id' => $itemId,
                    'label' => (string) ($translation['label'] ?? ''),
                    'link_type' => $linkType,
                    'url' => $linkType === 'custom_url' ? ($translation['custom_url'] ?? null) : null,
                    'link_target' => (string) $item['link_target'],
                    'icon' => $item['icon'],
                    'css_class' => $item['css_class'],
                    'sort_order' => (int) $item['sort_order'],
                    'parent_id' => $item['parent_id'] !== null ? (int) $item['parent_id'] : null,
                    'navigation' => $this->navigation($item, $slug, $collectionSlug),
                    'is_fallback' => ! isset($itemTranslations[$itemId][$locale]),
                    'children' => [],
                ];
            }
            $result[$menuKey] = [
                'menu_key' => $menuKey,
                'location' => (string) $menu['location'],
                'name' => (string) ($this->pick($menuTranslations[$menuId] ?? [], $locale, $default)['name'] ?? $menu['menu_key']),
                'items' => $this->tree($flat),
            ];
        }

        $revision = 'cms-navigation:' . $this->revision($menus, $items);
        return PublicReadEnvelope::success($locale, $result, $revision, domain: 'cms', meta: ['fields' => [], 'query' => ['resource' => 'navigation']]);
    }

    /**
     * @param array<int, array<string, mixed>> $languages
     * @return list<int>
     */
    private function languageIds(array $languages, string $locale, string $default): array
    {
        $ids = [];
        foreach ($languages as $language) {
            if (in_array(strtolower((string) $language['code']), [$locale, $default], true)) {
                $ids[] = (int) $language['id'];
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * @param array<int, string> $codeById
     * @return array<int, array<string, array<string, mixed>>>
     */
    private function rowsByLanguage(mixed $query, array $codeById, string $key): array
    {
        $rows = $query !== false && $query !== null ? $query->getResultArray() : [];
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row[$key]][$codeById[(int) $row['language_id']] ?? ''] = $row;
        }
        return $result;
    }

    /**
     * @param list<int> $ids
     * @param list<int> $languageIds
     * @param array<int, string> $codeById
     * @return array<int, array<string, string>>
     */
    private function slugMap(string $table, string $idColumn, array $ids, array $languageIds, array $codeById): array
    {
        $query = $this->db->table($table)->select($idColumn . ', language_id, slug')->whereIn($idColumn, $ids)->whereIn('language_id', $languageIds)->get();
        $rows = $query !== false ? $query->getResultArray() : [];
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row[$idColumn]][$codeById[(int) $row['language_id']] ?? ''] = (string) $row['slug'];
        }
        return $result;
    }

    /**
     * Resolve published collection index page slugs in one set-based query.
     *
     * Legacy menu links prefer the public collection index page over the
     * collection translation slug, so the consolidated navigation contract
     * must preserve that precedence for both collection and entry items.
     *
     * @param list<int> $collectionIds
     * @param list<int> $languageIds
     * @param array<int, string> $codeById
     * @return array<int, array<string, string>>
     */
    private function collectionIndexSlugMap(array $collectionIds, array $languageIds, array $codeById): array
    {
        $query = $this->db->table('cms_pages p')
            ->select('p.collection_id, pt.language_id, pt.slug')
            ->join('cms_page_translations pt', 'pt.page_id = p.id')
            ->whereIn('p.collection_id', $collectionIds)
            ->where('p.page_type', 'collection_index')
            ->where('p.status', 'published')
            ->where('p.deleted_at', null)
            ->groupStart()
                ->where('p.published_at IS NULL', null, false)
                ->orWhere('p.published_at <=', date('Y-m-d H:i:s'))
            ->groupEnd()
            ->groupStart()
                ->where('p.scheduled_at IS NULL', null, false)
                ->orWhere('p.scheduled_at <=', date('Y-m-d H:i:s'))
            ->groupEnd()
            ->whereIn('pt.language_id', $languageIds)
            ->get();
        $rows = $query !== false ? $query->getResultArray() : [];
        $result = [];
        foreach ($rows as $row) {
            $collectionId = (int) ($row['collection_id'] ?? 0);
            $language = $codeById[(int) ($row['language_id'] ?? 0)] ?? '';
            $slug = trim((string) ($row['slug'] ?? ''), '/');
            if ($collectionId > 0 && $language !== '' && $slug !== '') {
                $result[$collectionId][$language] = $slug;
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $item */
    private function isPublicNavigationTarget(array $item): bool
    {
        return match ((string) ($item['link_type'] ?? '')) {
            'page' => $this->isPublicPage($item),
            'entry' => $this->isPublicEntry($item),
            'collection_listing' => (int) ($item['collection_active'] ?? 0) === 1,
            default => true,
        };
    }

    /** @param array<string, mixed> $row */
    private function isPublicPage(array $row): bool
    {
        return ($row['page_deleted_at'] ?? null) === null
            && (
                (int) ($row['page_status'] ?? 0) === 1
                || (string) ($row['page_status'] ?? '') === 'published'
            )
            && $this->isDue($row['page_published_at'] ?? null, $row['page_scheduled_at'] ?? null);
    }

    /** @param array<string, mixed> $row */
    private function isPublicEntry(array $row): bool
    {
        return ((int) ($row['entry_status'] ?? 0) === 1 || (string) ($row['entry_status'] ?? '') === 'published')
            && ($row['entry_deleted_at'] ?? null) === null
            && (int) ($row['entry_collection_active'] ?? 0) === 1
            && $this->isDue($row['entry_published_at'] ?? null, $row['entry_scheduled_at'] ?? null);
    }

    private function isDue(mixed $publishedAt, mixed $scheduledAt): bool
    {
        $now = time();
        foreach ([$publishedAt, $scheduledAt] as $value) {
            if ($value !== null && $value !== '' && strtotime((string) $value) > $now) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, array<string, mixed>> $translations
     * @return array<string, mixed>
     */
    private function pick(array $translations, string $locale, string $default): array
    {
        return $translations[$locale] ?? $translations[$default] ?? [];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function navigation(array $item, ?string $slug, ?string $collectionSlug): array
    {
        $type = (string) $item['link_type'];
        $pageType = (string) ($item['page_type'] ?? '');
        $route = match (true) {
            $type === 'event_listing' || $pageType === 'events' => 'events',
            $type === 'collection_listing' || $pageType === 'catalog_listing' => 'catalog',
            $type === 'entry' => 'entries',
            $type === 'page' => 'pages',
            default => null,
        };
        return [
            'route_key' => $route,
            'target_type' => $type,
            'target_id' => $item['page_id'] ?? $item['entry_id'] ?? $item['collection_id'] ?? null,
            'slug' => $slug,
            'collection_slug' => $collectionSlug,
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function tree(array $items): array
    {
        $byParent = [];
        foreach ($items as $item) {
            $key = $item['parent_id'] === null ? 'root' : (string) $item['parent_id'];
            $byParent[$key][] = $item;
        }
        $build = function (string $parent) use (&$build, $byParent): array {
            $branch = [];
            foreach ($byParent[$parent] ?? [] as $item) {
                $item['children'] = $build((string) $item['id']);
                $branch[] = $item;
            }
            return $branch;
        };
        return $build('root');
    }

    /**
     * @param array<int, array<string, mixed>> $menus
     * @param array<int, array<string, mixed>> $items
     */
    private function revision(array $menus, array $items): string
    {
        $updated = '';
        $maxId = 0;
        foreach (array_merge($menus, $items) as $row) {
            $updated = max($updated, (string) ($row['updated_at'] ?? ''));
            $maxId = max($maxId, (int) ($row['id'] ?? 0));
        }
        return ($updated !== '' ? $updated : 'empty') . ':' . $maxId;
    }
}
