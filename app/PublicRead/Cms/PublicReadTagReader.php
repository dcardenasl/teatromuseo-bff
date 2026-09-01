<?php

declare(strict_types=1);

namespace App\PublicRead\Cms;

use CodeIgniter\Database\BaseConnection;

/** Set-based public CMS tag projection. */
final class PublicReadTagReader
{
    /** @param BaseConnection<mixed, mixed> $db */
    public function __construct(private readonly BaseConnection $db, private readonly string $fallbackLocale = 'es')
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(string $locale, string $collectionKey): array
    {
        $collectionKey = strtolower(trim($collectionKey)) === 'cursos' ? 'teatroescuela' : trim($collectionKey);
        $existsQuery = $this->db->table('cms_collections')->select('id')->where('collection_key', $collectionKey)->where('is_active', 1)->get();
        $exists = $existsQuery !== false ? $existsQuery->getRowArray() : null;
        if (! is_array($exists)) {
            return [];
        }
        $query = $this->db->table('cms_tags')->select('id')->where('is_active', 1)->orderBy('created_at', 'ASC')->orderBy('id', 'ASC')->get();
        $rows = $query !== false ? $query->getResultArray() : [];
        if ($rows === []) {
            return [];
        }
        $resolver = new PublicLocaleResolver($this->db, $this->fallbackLocale);
        $all = $resolver->all();
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $languageIds = array_values(array_map(static fn (array $row): int => (int) $row['id'], $all['by_code']));
        $query = $this->db->table('cms_tag_translations')->select('tag_id, language_id, slug, name')->whereIn('tag_id', $ids)->whereIn('language_id', $languageIds)->get();
        $translations = [];
        foreach (($query !== false ? $query->getResultArray() : []) as $row) {
            $translations[(int) $row['tag_id']][(int) $row['language_id']] = $row;
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
            return ['id' => $id, 'slug' => (string) ($translation['slug'] ?? ''), 'name' => (string) ($translation['name'] ?? ''), 'is_fallback' => $fallback];
        }, $rows));
    }
}
