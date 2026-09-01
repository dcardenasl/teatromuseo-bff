<?php

declare(strict_types=1);

namespace App\AdminRead\Support;

use CodeIgniter\Database\BaseConnection;

/**
 * Active CMS language options for Admin workspace projections. Extracted
 * from `AdminCatalogCollectionItemSource` and `AdminEventWorkspaceSource`,
 * which reimplemented this identically — including omitting a `LIMIT`,
 * unlike every other `cms_languages` read in this codebase (see
 * `AdminCmsBootstrapSource`/`AdminCmsWorkspaceSource`, which all cap it at
 * 100). The active-language table is small and curated, so this bound is
 * defensive rather than a response to real growth.
 */
final class CmsLanguageOptions
{
    private const MAX_LANGUAGES = 100;

    /**
     * @param BaseConnection<mixed,mixed> $cmsDb
     * @return list<array<string,mixed>>
     */
    public static function list(BaseConnection $cmsDb): array
    {
        $query = $cmsDb->table('cms_languages')
            ->select('id, code, name, native_name, is_default, sort_order')
            ->where('is_active', 1)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->limit(self::MAX_LANGUAGES)
            ->get();
        $rows = $query !== false ? array_values($query->getResultArray()) : [];
        foreach ($rows as &$row) {
            $row['id'] = (int) ($row['id'] ?? 0);
            $row['is_default'] = (bool) ($row['is_default'] ?? false);
        }
        unset($row);

        return $rows;
    }
}
