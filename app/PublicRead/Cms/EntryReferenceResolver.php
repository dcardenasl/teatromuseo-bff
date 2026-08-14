<?php

declare(strict_types=1);

namespace App\PublicRead\Cms;

use CodeIgniter\Database\BaseConnection;

/**
 * Resolves canonical block references into the small public entry shape used
 * by frontends. It intentionally exposes only published entries and performs
 * one query per table for a complete owner batch.
 */
final class EntryReferenceResolver
{
    /** @param BaseConnection<mixed, mixed> $db */
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, array<string, mixed>> $fields
     * @return list<array{entry_id: int, collection_key: string}>
     */
    public function collectReferences(array $data, array $fields): array
    {
        $references = [];
        foreach ($fields as $fieldKey => $definition) {
            $type = (string) ($definition['type'] ?? '');
            if ($type === 'entry_reference') {
                $candidate = $data[$fieldKey] ?? null;
                if ($this->isReference($candidate)) {
                    $references[] = $this->canonicalReference($candidate);
                }
            } elseif ($type === 'entry_reference_list' && is_array($data[$fieldKey] ?? null)) {
                foreach ($data[$fieldKey] as $candidate) {
                    if ($this->isReference($candidate)) {
                        $references[] = $this->canonicalReference($candidate);
                    }
                }
            }
        }

        return $references;
    }

    /**
     * @param list<array{entry_id: int, collection_key: string}> $references
     * @return array<string, array<string, mixed>|null>
     */
    public function resolve(array $references, string $langCode): array
    {
        $unique = [];
        foreach ($references as $reference) {
            $unique[$this->key($reference)] = $reference;
        }
        if ($unique === []) {
            return [];
        }

        [$languageId, $defaultLanguageId] = $this->resolveLanguageIds($langCode);
        $languageIds = array_values(array_unique(array_filter([$languageId, $defaultLanguageId])));
        if ($languageIds === []) {
            return array_fill_keys(array_keys($unique), null);
        }

        $entryIds = array_values(array_unique(array_map(
            static fn (array $reference): int => $reference['entry_id'],
            array_values($unique)
        )));

        $result = $this->db->table('cms_entries e')
            ->select('e.id, c.collection_key, et.language_id, et.slug, et.title, et.excerpt, ct.slug as collection_slug')
            ->join('cms_collections c', 'c.id = e.collection_id')
            ->join('cms_entry_translations et', 'et.entry_id = e.id', 'left')
            ->join('cms_collection_translations ct', 'ct.collection_id = c.id AND ct.language_id = et.language_id', 'left')
            ->whereIn('e.id', $entryIds)
            ->where('e.workflow_status', 'published')
            ->where('e.deleted_at IS NULL', null, false)
            ->where('c.is_active', 1)
            ->whereIn('et.language_id', $languageIds)
            ->get();
        $rows = $result === false ? [] : $result->getResultArray();

        /** @var array<string, array{priority: int, payload: array<string, mixed>}> $resolved */
        $resolved = [];
        foreach ($rows as $row) {
            $reference = [
                'entry_id' => (int) $row['id'],
                'collection_key' => (string) $row['collection_key'],
            ];
            $key = $this->key($reference);
            if (! isset($unique[$key])) {
                continue;
            }

            $priority = (int) $row['language_id'] === $languageId ? 0 : 1;
            if (isset($resolved[$key]) && $resolved[$key]['priority'] <= $priority) {
                continue;
            }

            $entrySlug = trim((string) ($row['slug'] ?? ''));
            $collectionSlug = trim((string) ($row['collection_slug'] ?? $row['collection_key']));
            $resolved[$key] = [
                'priority' => $priority,
                'payload' => [
                    'id' => (int) $row['id'],
                    'collection_key' => (string) $row['collection_key'],
                    'collection_slug' => $collectionSlug,
                    'slug' => $entrySlug,
                    'title' => (string) ($row['title'] ?? ''),
                    'excerpt' => $row['excerpt'] !== null ? (string) $row['excerpt'] : null,
                    'url' => trim($collectionSlug . '/' . $entrySlug, '/'),
                ],
            ];
        }

        $result = [];
        foreach (array_keys($unique) as $key) {
            $result[$key] = $resolved[$key]['payload'] ?? null;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, array<string, mixed>> $fields
     * @param array<string, array<string, mixed>|null> $resolved
     * @return array<string, mixed>
     */
    public function hydrateBlockData(array $data, array $fields, array $resolved): array
    {
        foreach ($fields as $fieldKey => $definition) {
            $type = (string) ($definition['type'] ?? '');
            if ($type === 'entry_reference') {
                $candidate = $data[$fieldKey] ?? null;
                if ($this->isReference($candidate)) {
                    $data[$fieldKey]['entry'] = $resolved[$this->key($this->canonicalReference($candidate))] ?? null;
                }
            } elseif ($type === 'entry_reference_list' && is_array($data[$fieldKey] ?? null)) {
                foreach ($data[$fieldKey] as $index => $candidate) {
                    if ($this->isReference($candidate)) {
                        $data[$fieldKey][$index]['entry'] = $resolved[$this->key($this->canonicalReference($candidate))] ?? null;
                    }
                }
            }
        }

        return $data;
    }

    private function isReference(mixed $value): bool
    {
        return is_array($value)
            && isset($value['entry_id'], $value['collection_key'])
            && (int) $value['entry_id'] > 0
            && is_string($value['collection_key'])
            && trim($value['collection_key']) !== '';
    }

    /**
     * @param array<string, mixed> $reference
     * @return array{entry_id: int, collection_key: string}
     */
    private function canonicalReference(array $reference): array
    {
        return [
            'entry_id' => (int) $reference['entry_id'],
            'collection_key' => trim($reference['collection_key']),
        ];
    }

    /** @param array{entry_id: int, collection_key: string} $reference */
    private function key(array $reference): string
    {
        return $reference['collection_key'] . ':' . $reference['entry_id'];
    }

    /** @return array{0: int|null, 1: int|null} */
    private function resolveLanguageIds(string $langCode): array
    {
        $result = $this->db->table('cms_languages')
            ->where('is_active', 1)
            ->groupStart()
            ->where('code', $langCode)
            ->orWhere('is_default', 1)
            ->groupEnd()
            ->get();
        $rows = $result === false ? [] : $result->getResultArray();

        $targetId = null;
        $defaultId = null;
        foreach ($rows as $row) {
            if ((string) $row['code'] === $langCode) {
                $targetId = (int) $row['id'];
            }
            if ((int) $row['is_default'] === 1) {
                $defaultId = (int) $row['id'];
            }
        }

        return [$targetId ?? $defaultId, $defaultId];
    }
}
