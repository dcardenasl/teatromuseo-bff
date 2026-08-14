<?php

declare(strict_types=1);

namespace App\PublicRead\Cms;

use CodeIgniter\Database\BaseConnection;

/** Set-based public CMS category projection. */
final class PublicReadCategoryReader
{
    /** @param BaseConnection<mixed, mixed> $db */
    public function __construct(private readonly BaseConnection $db, private readonly string $fallbackLocale = 'es')
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(string $locale, string $collectionKey): array
    {
        $collectionKey = strtolower(trim($collectionKey)) === 'cursos' ? 'teatroescuela' : trim($collectionKey);
        $collectionQuery = $this->db->table('cms_collections')->select('id')->where('collection_key', $collectionKey)->where('is_active', 1)->get();
        $collection = $collectionQuery !== false ? $collectionQuery->getRowArray() : null;
        if (! is_array($collection)) {
            return [];
        }
        $query = $this->db->table('cms_categories c')->select('c.id, c.collection_id, c.sort_order')
            ->where('c.collection_id', (int) $collection['id'])->where('c.is_active', 1)
            ->orderBy('c.sort_order', 'ASC')->orderBy('c.id', 'ASC')->get();
        $categories = $query !== false ? array_values($query->getResultArray()) : [];
        return $this->translate($categories, 'cms_category_translations', 'category_id', $locale, ['name', 'slug', 'description']);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $fields
     * @return list<array<string, mixed>>
     */
    private function translate(array $rows, string $table, string $foreignKey, string $locale, array $fields): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $resolver = new PublicLocaleResolver($this->db, $this->fallbackLocale);
        $all = $resolver->all();
        $languageIds = array_values(array_map(static fn (array $row): int => (int) $row['id'], $all['by_code']));
        $select = implode(', ', array_merge([$foreignKey, 'language_id'], $fields));
        $query = $this->db->table($table)->select($select)->whereIn($foreignKey, $ids)->whereIn('language_id', $languageIds)->get();
        $translations = [];
        foreach (($query !== false ? $query->getResultArray() : []) as $row) {
            $translations[(int) $row[$foreignKey]][(int) $row['language_id']] = $row;
        }
        $requestedId = isset($all['by_code'][$locale]) ? (int) $all['by_code'][$locale]['id'] : null;
        $defaultId = isset($all['by_code'][$all['default']]) ? (int) $all['by_code'][$all['default']]['id'] : null;
        return array_values(array_map(static function (array $row) use ($translations, $requestedId, $defaultId): array {
            $id = (int) $row['id'];
            $translation = $requestedId !== null ? ($translations[$id][$requestedId] ?? null) : null;
            $fallback = false;
            if (! is_array($translation) && $defaultId !== null) {
                $translation = $translations[$id][$defaultId] ?? null;
                $fallback = $translation !== null;
            }
            return ['id' => $id, 'slug' => (string) ($translation['slug'] ?? ''), 'name' => (string) ($translation['name'] ?? ''), 'description' => $translation['description'] ?? null, 'is_fallback' => $fallback];
        }, $rows));
    }
}
