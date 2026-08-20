<?php

declare(strict_types=1);

namespace App\AdminRead\Catalog;

use App\AdminRead\Contracts\AdminCatalogTechniqueSourceInterface;
use App\AdminRead\Support\CmsLanguageOptions;
use App\AdminRead\Support\JsonArrayAggregateSql;
use App\AdminRead\Support\JsonProjectionDecoder;
use App\AdminRead\Support\PermissionGuard;
use App\AdminRead\Support\ReadOnlyQuery;
use App\Support\RequestTelemetry;
use CodeIgniter\Cache\CacheInterface;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/** One Catalog/CMS read boundary for the Admin technique screens. */
final class AdminCatalogTechniqueSource implements AdminCatalogTechniqueSourceInterface
{
    private const CACHE_TTL = 30;

    /**
     * @param BaseConnection<mixed,mixed> $catalogDb
     * @param BaseConnection<mixed,mixed> $cmsDb
     */
    public function __construct(
        private readonly BaseConnection $catalogDb,
        private readonly BaseConnection $cmsDb,
        private readonly CacheInterface $cache,
    ) {
    }

    /** @param list<string> $permissions */
    public function workspace(?int $techniqueId, array $permissions): array
    {
        PermissionGuard::require($permissions, 'catalog.technique.read');
        if ($techniqueId !== null && $techniqueId < 1) {
            throw new RuntimeException('A positive Catalog technique identifier is required.');
        }

        $scope = array_values(array_unique($permissions));
        sort($scope);
        $cacheKey = 'admin_catalog_technique_' . ($techniqueId ?? 'base') . '_' . hash('sha256', implode("\0", $scope));
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            RequestTelemetry::recordCache('admin.catalog.technique', 'hit');

            return $cached;
        }
        RequestTelemetry::recordCache('admin.catalog.technique', 'miss');

        $technique = $techniqueId === null ? null : $this->technique($techniqueId);
        if ($techniqueId !== null && $technique === null) {
            throw new RuntimeException('Catalog technique not found.');
        }

        $languages = in_array('cms.languages.read', $permissions, true)
            ? CmsLanguageOptions::list($this->cmsDb)
            : [];
        $sections = [
            'technique' => $technique,
            'languages' => $languages,
            'quality' => [],
        ];
        $this->cache->save($cacheKey, $sections, self::CACHE_TTL);

        return $sections;
    }

    /** @return array<string,mixed>|null */
    private function technique(int $techniqueId): ?array
    {
        $json = JsonArrayAggregateSql::forDatabase($this->catalogDb);
        $aggregate = $json['aggregate'];
        $object = $json['object'];
        $suffix = $json['suffix'];
        $sql = <<<SQL
            SELECT technique.id, technique.name, technique.slug, technique.summary,
                   technique.video_url, technique.pdf_file_id, technique.sort_order,
                   technique.created_at, technique.updated_at, technique.deleted_at,
                   COALESCE((SELECT {$aggregate}({$object}(
                        'locale', t.locale,
                        'field', t.field,
                        'value', t.value,
                        'updated_at', t.updated_at
                   ){$suffix})
                   FROM catalog_translations t
                   WHERE t.translatable_type = 'technique'
                     AND t.translatable_id = technique.id), '[]') AS translations_json
            FROM techniques technique
            WHERE technique.id = ?
              AND technique.deleted_at IS NULL
            LIMIT 1
        SQL;

        $rows = ReadOnlyQuery::sql($this->catalogDb, $sql, [$techniqueId], 'Catalog admin technique workspace');
        $row = $rows[0] ?? null;
        if (! is_array($row)) {
            return null;
        }

        $row['id'] = (int) ($row['id'] ?? 0);
        $row['pdf_file_id'] = $row['pdf_file_id'] === null ? null : (int) $row['pdf_file_id'];
        $row['translations'] = $this->translations($row['translations_json'] ?? null);
        unset($row['translations_json']);

        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function translations(mixed $value): array
    {
        $grouped = [];
        foreach (JsonProjectionDecoder::decodeList($value) as $row) {
            $locale = strtolower(trim((string) ($row['locale'] ?? '')));
            $field = trim((string) ($row['field'] ?? ''));
            if ($locale === '' || $field === '') {
                continue;
            }
            $grouped[$locale] ??= ['locale' => $locale];
            $grouped[$locale][$field] = $row['value'] ?? '';
            if (array_key_exists('updated_at', $row)) {
                $grouped[$locale]['updated_at'] = $row['updated_at'];
            }
        }

        return array_values($grouped);
    }
}
