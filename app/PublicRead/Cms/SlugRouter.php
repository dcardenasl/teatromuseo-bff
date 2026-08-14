<?php

declare(strict_types=1);

namespace App\PublicRead\Cms;

use CodeIgniter\Database\BaseConnection;

class SlugRouter
{
    /** @var BaseConnection<mixed, mixed> */
    private BaseConnection $db;

    /** @param BaseConnection<mixed, mixed> $db */
    public function __construct(BaseConnection $db)
    {
        $this->db = $db;
    }

    /**
     * Resolve a slug path to a content ID.
     *
     * @param string $langCode Language code (e.g., 'es')
     * @param string $type Resource type (e.g., 'page')
     * @param string $slugPath Full slug path (e.g., 'nosotros/vision')
     * @param bool $includeUnpublished When true, resolve draft/unpublished pages too.
     *        Only PublicPageController sets this, and only after independently
     *        verifying a signed preview token for this exact lang+slug — slug
     *        resolution has no other access control of its own.
     * @return int|null Resolved ID or null if not found
     */
    public function resolve(string $langCode, string $type, string $slugPath, bool $includeUnpublished = false): ?int
    {
        if ($type !== 'page') {
            return null;
        }

        // Get language ID
        $lang = $this->getLanguageByCode($langCode);
        if (!$lang) {
            return null;
        }
        $langId = (int) $lang['id'];

        // Get default language ID as fallback
        $defaultLang = $this->getDefaultLanguage();
        $defaultLangId = $defaultLang ? (int) $defaultLang['id'] : null;

        // Clean slug path
        $slugPath = trim($slugPath, '/');
        if ($slugPath === '' || $slugPath === 'home') {
            $builder = $this->db->table('cms_pages')
                ->select('id')
                ->where('page_type', 'home')
                ->where('deleted_at IS NULL');
            if (!$includeUnpublished) {
                $this->applyPublicPageVisibility($builder);
            }
            $result = $builder->get();
            if ($result !== false) {
                $homePage = $result->getRow();
                if ($homePage) {
                    return (int) $homePage->id;
                }
            }
        }

        if ($slugPath === '') {
            return null;
        }

        $segments = explode('/', $slugPath);
        $currentParentId = null;

        foreach ($segments as $segment) {
            $pageId = $this->findPageBySlugAndParent($segment, $currentParentId, $langId, $includeUnpublished);

            // If not found in target language, check in default language as fallback
            if ($pageId === null && $defaultLangId !== null && $langId !== $defaultLangId) {
                $pageId = $this->findPageBySlugAndParent($segment, $currentParentId, $defaultLangId, $includeUnpublished);
            }

            if ($pageId === null) {
                return null;
            }

            $currentParentId = $pageId;
        }

        return $currentParentId;
    }

    /**
     * Resolve a content ID to its full slug path.
     *
     * @param string $langCode Language code (e.g., 'es')
     * @param string $type Resource type (only 'page' supported)
     * @param int $id Content ID
     * @return string|null Full slug path (e.g., 'nosotros/vision') or null if not found
     */
    public function resolveSlug(string $langCode, string $type, int $id): ?string
    {
        if ($type !== 'page') {
            return null;
        }

        $lang = $this->getLanguageByCode($langCode);
        if (!$lang) {
            return null;
        }
        $langId = (int) $lang['id'];

        $defaultLang    = $this->getDefaultLanguage();
        $defaultLangId  = $defaultLang ? (int) $defaultLang['id'] : null;

        $segments  = [];
        $currentId = $id;

        while ($currentId !== null) {
            $row = $this->fetchPageSlugRow($currentId, $langId);

            // Fall back to default language when the requested translation is missing
            if (!is_array($row) && $defaultLangId !== null && $langId !== $defaultLangId) {
                $row = $this->fetchPageSlugRow($currentId, $defaultLangId);
            }

            if (!is_array($row) || !isset($row['slug'])) {
                return null;
            }

            array_unshift($segments, (string) $row['slug']);
            $currentId = array_key_exists('parent_id', $row) && $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
        }

        return implode('/', $segments);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchPageSlugRow(int $pageId, int $langId): ?array
    {
        $query = $this->db->table('cms_pages p')
            ->select('p.parent_id, pt.slug')
            ->join('cms_page_translations pt', 'p.id = pt.page_id AND pt.language_id = ' . $langId)
            ->where('p.id', $pageId)
            ->where('p.deleted_at IS NULL')
            ->where('p.status', 'published')
            ->groupStart()
                ->where('p.published_at IS NULL')
                ->orWhere('p.published_at <=', date('Y-m-d H:i:s'))
            ->groupEnd()
            ->groupStart()
                ->where('p.scheduled_at IS NULL')
                ->orWhere('p.scheduled_at <=', date('Y-m-d H:i:s'))
            ->groupEnd()
            ->get();

        if ($query === false) {
            return null;
        }

        $row = $query->getRowArray();

        return is_array($row) ? $row : null;
    }

    /**
     * Find a page ID by its slug and parent_id for a given language.
     */
    private function findPageBySlugAndParent(string $slug, ?int $parentId, int $langId, bool $includeUnpublished = false): ?int
    {
        $builder = $this->db->table('cms_pages p')
            ->select('p.id')
            ->join('cms_page_translations pt', 'p.id = pt.page_id')
            ->where('pt.slug', $slug)
            ->where('pt.language_id', $langId)
            ->where('p.deleted_at IS NULL');
        if (!$includeUnpublished) {
            $this->applyPublicPageVisibility($builder, 'p.');
        }

        if ($parentId === null) {
            $builder->where('p.parent_id IS NULL');
        } else {
            $builder->where('p.parent_id', $parentId);
        }

        $query = $builder->get();
        if ($query === false) {
            return null;
        }
        $result = $query->getRow();

        return $result ? (int) $result->id : null;
    }

    private function applyPublicPageVisibility(object $builder, string $prefix = ''): void
    {
        $builder->where($prefix . 'status', 'published')
            ->groupStart()
                ->where($prefix . 'published_at IS NULL')
                ->orWhere($prefix . 'published_at <=', date('Y-m-d H:i:s'))
            ->groupEnd()
            ->groupStart()
                ->where($prefix . 'scheduled_at IS NULL')
                ->orWhere($prefix . 'scheduled_at <=', date('Y-m-d H:i:s'))
            ->groupEnd();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getLanguageByCode(string $code): ?array
    {
        $result = $this->db->table('cms_languages')
            ->where('code', $code)
            ->get();

        return $result instanceof \CodeIgniter\Database\ResultInterface ? $result->getRowArray() : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getDefaultLanguage(): ?array
    {
        $result = $this->db->table('cms_languages')
            ->where('is_default', 1)
            ->where('is_active', 1)
            ->get();

        return $result instanceof \CodeIgniter\Database\ResultInterface ? $result->getRowArray() : null;
    }
}
