<?php

declare(strict_types=1);

namespace App\PublicRead\Cms;

use App\PublicRead\Page\RedirectReaderInterface;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\ResultInterface;
use dcardenasl\Ci4ApiCore\Exceptions\NotFoundException;

/**
 * Resolves a public redirect path, in priority order: manual redirects
 * (`cms_redirects`) then slug history
 * (`cms_slug_redirects`, for pages/entries whose slug changed since the
 * link was published).
 *
 * Extracted from PublicRedirectController, which used to run all of this
 * inline via `\Config\Database::connect()`. Uses a raw `BaseConnection`
 * directly rather than a Model/Repository — same established pattern as
 * TranslationResolver, OwnerUsageResolver, and SlugRedirectRecorder, all of
 * which do custom cross-table history/pivot queries this way rather than
 * inventing single-purpose Models for tables with no CRUD surface of their
 * own (`cms_slug_redirects` has never had a Model in this codebase).
 */
class PublicRedirectResolver implements RedirectReaderInterface
{
    /**
     * @param BaseConnection<mixed, mixed> $db
     */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly TranslationResolver $translationResolver,
        private readonly SlugRouter $slugRouter,
    ) {
    }

    /**
     * @param list<string> $segments
     * @return array{new_url: string, redirect_type: int}
     */
    public function resolve(array $segments): array
    {
        $cleanPath = trim(rawurldecode(implode('/', $segments)), '/');

        $manual = $this->findManualRedirect($cleanPath);
        if ($manual !== null) {
            return [
                'new_url'       => (string) $manual['new_url'],
                'redirect_type' => (int) $manual['redirect_type'],
            ];
        }

        $history = $this->findSlugHistory($cleanPath);
        if ($history !== null) {
            $resolved = $this->resolveFromHistory($history);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        throw new NotFoundException('Public redirect not found.');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findManualRedirect(string $cleanPath): ?array
    {
        $result = $this->db->table('cms_redirects')
            ->where('old_path', $cleanPath)
            ->where('is_active', 1)
            ->get();

        return $result instanceof ResultInterface ? $result->getRowArray() : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findSlugHistory(string $cleanPath): ?array
    {
        $result = $this->db->table('cms_slug_redirects')
            ->where('old_full_path', $cleanPath)
            ->get();

        return $result instanceof ResultInterface ? $result->getRowArray() : null;
    }

    /**
     * @param array<string, mixed> $history
     * @return array{new_url: string, redirect_type: int}|null
     */
    private function resolveFromHistory(array $history): ?array
    {
        $entityType = (string) $history['entity_type'];
        $entityId   = (int) $history['entity_id'];
        $langId     = (int) $history['language_id'];
        $langCode   = $this->languageCodeById($langId);

        return match ($entityType) {
            'page'  => $this->resolvePageRedirect($entityId, $langId, $langCode),
            'entry' => $this->resolveEntryRedirect($entityId, $langId, $langCode),
            default => null,
        };
    }

    private function languageCodeById(int $langId): string
    {
        $result = $this->db->table('cms_languages')->where('id', $langId)->get();
        $row = $result instanceof ResultInterface ? $result->getRowArray() : null;

        return $row !== null ? (string) $row['code'] : 'en';
    }

    /**
     * @return array{new_url: string, redirect_type: int}|null
     */
    private function resolvePageRedirect(int $pageId, int $langId, string $langCode): ?array
    {
        $result = $this->db->table('cms_page_translations')
            ->where('page_id', $pageId)
            ->where('language_id', $langId)
            ->get();
        $pageTrans = $result instanceof ResultInterface ? $result->getRowArray() : null;

        if ($pageTrans === null) {
            return null;
        }

        // Reuses SlugRouter's parent-chain walk (already has default-language
        // fallback per segment) instead of a bespoke recursive SQL loop.
        $currentSlugPath = (string) ($this->slugRouter->resolveSlug($langCode, 'page', $pageId) ?? '');

        return [
            'new_url'       => '/' . $langCode . '/pages/' . $currentSlugPath,
            'redirect_type' => 301,
        ];
    }

    /**
     * @return array{new_url: string, redirect_type: int}|null
     */
    private function resolveEntryRedirect(int $entryId, int $langId, string $langCode): ?array
    {
        $result = $this->db->table('cms_entry_translations')
            ->where('entry_id', $entryId)
            ->where('language_id', $langId)
            ->get();
        $entryTrans = $result instanceof ResultInterface ? $result->getRowArray() : null;

        if ($entryTrans === null) {
            return null;
        }

        $entryResult = $this->db->table('cms_entries')->where('id', $entryId)->get();
        $entryRow = $entryResult instanceof ResultInterface ? $entryResult->getRowArray() : null;

        $prefix = '';
        if ($entryRow !== null) {
            $resolvedCollection = $this->translationResolver->resolve('collection', (int) $entryRow['collection_id'], $langCode);
            $prefix = trim((string) ($resolvedCollection['slug'] ?? ''), '/');
        }

        return [
            'new_url'       => '/' . $langCode . '/entries/' . ($prefix !== '' ? $prefix . '/' : '') . (string) $entryTrans['slug'],
            'redirect_type' => 301,
        ];
    }
}
