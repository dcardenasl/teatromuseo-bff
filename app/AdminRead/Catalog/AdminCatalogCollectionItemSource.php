<?php

declare(strict_types=1);

namespace App\AdminRead\Catalog;

use App\AdminRead\Contracts\AdminCatalogCollectionItemSourceInterface;
use App\AdminRead\Support\JsonArrayAggregateSql;
use App\AdminRead\Support\ReadOnlyQuery;
use CodeIgniter\Database\BaseConnection;
use dcardenasl\Ci4ApiCore\Exceptions\AuthorizationException;
use RuntimeException;

/**
 * Bounded Catalog workspace projection for the Admin item screens.
 *
 * The item, category and techniques are owned by Catalog and are joined or
 * aggregated there. CMS languages are merged explicitly from the CMS read
 * connection; no cross-database SQL is attempted.
 */
final class AdminCatalogCollectionItemSource implements AdminCatalogCollectionItemSourceInterface
{
    private const MAX_OPTIONS = 500;

    /** @param BaseConnection<mixed,mixed> $catalogDb @param BaseConnection<mixed,mixed> $cmsDb */
    public function __construct(
        private readonly BaseConnection $catalogDb,
        private readonly BaseConnection $cmsDb,
    ) {
    }

    public function workspace(?int $itemId, array $permissions): array
    {
        $this->requirePermission($permissions, 'catalog.collectionItem.read');
        if ($itemId !== null && $itemId < 1) {
            throw new RuntimeException('A positive Catalog item identifier is required.');
        }

        $item = $itemId === null ? null : $this->item($itemId);
        if ($itemId !== null && $item === null) {
            throw new RuntimeException('Catalog collection item not found.');
        }

        return [
            'collectionItem' => $item,
            'categories' => in_array('catalog.category.read', $permissions, true) ? $this->categories() : [],
            'techniques' => in_array('catalog.technique.read', $permissions, true) ? $this->techniques() : [],
            'languages' => in_array('cms.languages.read', $permissions, true) ? $this->languages() : [],
            'quality' => [],
        ];
    }

    /** @return array<string,mixed>|null */
    private function item(int $itemId): ?array
    {
        $json = JsonArrayAggregateSql::forDatabase($this->catalogDb);
        $aggregate = $json['aggregate'];
        $object = $json['object'];
        $suffix = $json['suffix'];
        $empty = "'[]'";

        $translations = <<<SQL
            SELECT {$aggregate}({$object}(
                'id', t.id,
                'locale', t.locale,
                'field', t.field,
                'value', t.value,
                'updated_at', t.updated_at
            ){$suffix}) AS translations_json
            FROM catalog_translations t
            WHERE t.translatable_type = 'collection_item'
              AND t.translatable_id = ?
        SQL;
        $techniques = <<<SQL
            SELECT {$aggregate}({$object}(
                'id', technique.id,
                'name', technique.name,
                'slug', technique.slug,
                'summary', technique.summary,
                'video_url', technique.video_url,
                'pdf_file_id', technique.pdf_file_id,
                'sort_order', technique.sort_order
            ){$suffix}) AS techniques_json
            FROM collection_item_technique relation
            INNER JOIN techniques technique ON technique.id = relation.technique_id
            WHERE relation.collection_item_id = ?
              AND technique.deleted_at IS NULL
        SQL;
        $sql = <<<SQL
            SELECT item.id, item.name, item.category_id, item.inventory_code, item.status,
                   item.summary, item.curiosidad, item.contenido, item.origin, item.period,
                   item.creator, item.ubicacion, item.materials, item.cover_file_id,
                   item.gallery_file_ids, item.show_in_totem, item.internal_notes,
                   item.collection_number, item.collection_group, item.physical_description,
                   item.dimensions, item.ingress_type, item.donated_by, item.tags, item.links,
                   item.company_history, item.is_active, item.created_at, item.updated_at,
                   item.deleted_at,
                   category.name AS category_name,
                   COALESCE(translation_projection.translations_json, {$empty}) AS translations_json,
                   COALESCE(technique_projection.techniques_json, {$empty}) AS techniques_json
            FROM collection_items item
            LEFT JOIN categories category ON category.id = item.category_id
            LEFT JOIN ({$translations}) translation_projection ON 1 = 1
            LEFT JOIN ({$techniques}) technique_projection ON 1 = 1
            WHERE item.id = ?
              AND item.deleted_at IS NULL
            LIMIT 1
        SQL;

        $rows = ReadOnlyQuery::sql(
            $this->catalogDb,
            $sql,
            [$itemId, $itemId, $itemId],
            'Catalog admin collection item workspace',
        );
        $row = $rows[0] ?? null;
        if (! is_array($row)) {
            return null;
        }

        $row['id'] = (int) ($row['id'] ?? 0);
        $row['category_id'] = (int) ($row['category_id'] ?? 0);
        $row['is_active'] = (bool) ($row['is_active'] ?? false);
        $row['show_in_totem'] = (bool) ($row['show_in_totem'] ?? false);
        $row['translations'] = $this->translations($row['translations_json'] ?? null);
        $row['techniques'] = $this->decodeList($row['techniques_json'] ?? null);
        foreach ($row['techniques'] as &$technique) {
            $technique['id'] = (int) ($technique['id'] ?? 0);
            $technique['sort_order'] = (int) ($technique['sort_order'] ?? 0);
        }
        unset($technique);
        unset($row['translations_json'], $row['techniques_json'], $row['category_name']);

        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function categories(): array
    {
        $query = $this->catalogDb->table('categories')
            ->select('id, name, slug, icon, short_description, sort_order')
            ->where('deleted_at', null)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('name', 'ASC')
            ->limit(self::MAX_OPTIONS)
            ->get();

        return $query !== false ? $query->getResultArray() : [];
    }

    /** @return list<array<string,mixed>> */
    private function techniques(): array
    {
        $query = $this->catalogDb->table('techniques')
            ->select('id, name, slug, summary, video_url, pdf_file_id, sort_order')
            ->where('deleted_at', null)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('name', 'ASC')
            ->limit(self::MAX_OPTIONS)
            ->get();

        return $query !== false ? $query->getResultArray() : [];
    }

    /** @return list<array<string,mixed>> */
    private function languages(): array
    {
        $query = $this->cmsDb->table('cms_languages')
            ->select('id, code, name, native_name, is_default, sort_order')
            ->where('is_active', 1)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->get();
        $rows = $query !== false ? $query->getResultArray() : [];
        foreach ($rows as &$row) {
            $row['id'] = (int) ($row['id'] ?? 0);
            $row['is_default'] = (bool) ($row['is_default'] ?? false);
        }
        unset($row);

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function translations(mixed $value): array
    {
        $rows = $this->decodeList($value);
        $grouped = [];
        foreach ($rows as $row) {
            $locale = strtolower(trim((string) ($row['locale'] ?? '')));
            $field = trim((string) ($row['field'] ?? ''));
            if ($locale === '' || $field === '') {
                continue;
            }
            $grouped[$locale] ??= ['locale' => $locale];
            $grouped[$locale][$field] = $row['value'] ?? '';
            if (isset($row['updated_at'])) {
                $grouped[$locale]['updated_at'] = $row['updated_at'];
            }
        }

        return array_values($grouped);
    }

    /** @return list<array<string,mixed>> */
    private function decodeList(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
        }
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /** @param list<string> $permissions */
    private function requirePermission(array $permissions, string $permission): void
    {
        if (! in_array($permission, $permissions, true)) {
            throw new AuthorizationException('The ' . $permission . ' permission is required.');
        }
    }
}
