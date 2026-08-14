<?php

declare(strict_types=1);

namespace App\PublicRead\Cms;

use CodeIgniter\Database\BaseConnection;

class BlockInstanceSerializer
{
    private FileUrlResolver $fileUrlResolver;

    private ?EntryReferenceResolver $entryReferenceResolver;

    /** @param BaseConnection<mixed, mixed> $db */
    public function __construct(
        private readonly BaseConnection $db,
        FileUrlResolver $fileUrlResolver,
        ?EntryReferenceResolver $entryReferenceResolver = null
    ) {
        $this->fileUrlResolver = $fileUrlResolver;
        $this->entryReferenceResolver = $entryReferenceResolver;
    }

    /**
     * Resolve and serialize all block instances for a given owner.
     *
     * Uses a single batch query per translation table to avoid N+1 queries.
     *
     * @param string $ownerType Type of owner ('page', 'entry')
     * @param int    $ownerId   ID of the owner
     * @param string $langCode  Target language code
     * @return array<int, array<string, mixed>>
     */
    public function forContent(string $ownerType, int $ownerId, string $langCode): array
    {
        $blocksByOwner = $this->forOwnersBatch($ownerType, [$ownerId], $langCode);

        return $blocksByOwner[$ownerId] ?? [];
    }

    /**
     * Resolve and serialize block instances for multiple owners without a query
     * per owner. The result deliberately remains grouped by owner ID so callers
     * can enrich a listing without exposing this loading detail to consumers.
     *
     * @param string    $ownerType Type of owner ('page', 'entry')
     * @param list<int> $ownerIds  IDs of the owners
     * @param string    $langCode  Target language code
     * @return array<int, list<array<string, mixed>>> Top-level blocks by owner ID
     */
    public function forOwnersBatch(string $ownerType, array $ownerIds, string $langCode): array
    {
        $ownerIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $ownerId): int => (int) $ownerId, $ownerIds),
            static fn (int $ownerId): bool => $ownerId > 0
        )));

        if ($ownerIds === []) {
            return [];
        }

        $db = $this->db;

        $query = $db->table('cms_block_instances i')
            ->select('i.*, b.block_key, b.name as block_type_name, b.schema_definition')
            ->join('cms_content_blocks b', 'b.id = i.block_id')
            ->where('i.owner_type', $ownerType)
            ->whereIn('i.owner_id', $ownerIds)
            ->where('i.is_active', 1)
            ->orderBy('i.sort_order', 'ASC')
            ->get();

        $instances = $query ? $query->getResultArray() : [];

        if (empty($instances)) {
            return [];
        }

        $instanceIds = array_column($instances, 'id');

        $translationsMap = $this->batchResolveBlockTranslations($instanceIds, $langCode, $db);

        $referenceMap = [];
        if ($this->entryReferenceResolver !== null) {
            $references = [];
            foreach ($instances as $instance) {
                $translationData = $translationsMap[(int) $instance['id']] ?? [];
                $rawData = $translationData['block_data'] ?? null;
                $blockData = is_string($rawData) ? (json_decode($rawData, true) ?? []) : (array) $rawData;
                $schemaDefinition = $this->parseSchemaDefinition((string) ($instance['schema_definition'] ?? ''));
                $references = array_merge(
                    $references,
                    $this->entryReferenceResolver->collectReferences($blockData, (array) ($schemaDefinition['fields'] ?? []))
                );
            }
            $referenceMap = $this->entryReferenceResolver->resolve($references, $langCode);
        }

        // Collect all file IDs in a single pre-pass via schema field declarations
        $allFileIds = [];
        foreach ($instances as $instance) {
            $translationData = $translationsMap[(int) $instance['id']] ?? [];
            $rawData         = $translationData['block_data'] ?? null;
            $blockData       = is_string($rawData) ? (json_decode($rawData, true) ?? []) : (array) $rawData;

            $schemaDefinition = $this->parseSchemaDefinition((string) ($instance['schema_definition'] ?? ''));
            $schemaFields = (array) ($schemaDefinition['fields'] ?? []);
            $schemaConfigFields = (array) ($schemaDefinition['config_fields'] ?? []);

            $allFileIds = array_merge($allFileIds, $this->fileUrlResolver->collectBlockFileIds($blockData, $schemaFields));

            $blockConfig = [];
            if (!empty($instance['block_config'])) {
                $blockConfig = is_string($instance['block_config'])
                    ? (json_decode($instance['block_config'], true) ?? [])
                    : (array) $instance['block_config'];
            }
            $allFileIds = array_merge($allFileIds, $this->fileUrlResolver->collectSchemaFileIds($blockConfig, $schemaConfigFields));
        }

        $allFileIds        = array_values(array_unique($allFileIds));
        $fileMetaMap       = !empty($allFileIds)
            ? $this->fileUrlResolver->resolveManyMeta($allFileIds, 'public')
            : [];

        // Serialize ALL instances (top-level and children alike) into a map keyed by id
        $serializedMap = [];
        $ownerByInstanceId = [];

        foreach ($instances as $instance) {
            $instanceId  = (int) $instance['id'];
            $translation = $translationsMap[$instanceId] ?? [];

            $rawBlockData = $translation['block_data'] ?? null;
            $blockData    = is_string($rawBlockData)
                ? (json_decode($rawBlockData, true) ?? [])
                : (array) $rawBlockData;
            $blockConfig = [];
            if (!empty($instance['block_config'])) {
                $blockConfig = is_string($instance['block_config'])
                    ? (json_decode($instance['block_config'], true) ?? [])
                    : (array) $instance['block_config'];
            }

            $schemaDefinition = $this->parseSchemaDefinition((string) ($instance['schema_definition'] ?? ''));
            $schemaFields = (array) ($schemaDefinition['fields'] ?? []);
            $schemaConfigFields = (array) ($schemaDefinition['config_fields'] ?? []);

            $blockConfig = SchemaDefaults::applyConfigDefaults($schemaDefinition, $blockConfig);
            $blockData = SchemaDefaults::apply($blockData, $schemaFields);

            // URLs were resolved once for the complete owner batch above. Apply
            // that map to both config and translated data without calling Hub
            // again for each field.
            if ($schemaConfigFields !== []) {
                $blockConfig = $this->mergeFileMetadata($blockConfig, $schemaConfigFields, $fileMetaMap);
            }

            $listingFields = is_array($schemaDefinition['listing_fields'] ?? null)
                ? $schemaDefinition['listing_fields']
                : [];
            foreach ($schemaFields as $fieldKey => $fieldDefinition) {
                if (! is_array($fieldDefinition) || ! in_array((string) ($fieldDefinition['type'] ?? ''), ['string', 'text', 'textarea', 'richtext', 'date', 'datetime', 'number', 'integer', 'select', 'boolean', 'media_reference'], true)) {
                    continue;
                }
                $listingFields[(string) $fieldKey] = array_merge([
                    'label' => (string) ($fieldDefinition['label'] ?? $fieldKey),
                    'type' => (string) ($fieldDefinition['type'] ?? 'string'),
                ], is_array($listingFields[(string) $fieldKey] ?? null) ? $listingFields[(string) $fieldKey] : []);
            }

            $blockPayload = [
                'id'                 => $instanceId,
                'block_key'          => $instance['block_key'],
                'sort_order'         => (int) $instance['sort_order'],
                'column_index'       => isset($instance['column_index']) ? (int) $instance['column_index'] : null,
                'parent_instance_id' => isset($instance['parent_instance_id']) ? (int) $instance['parent_instance_id'] : null,
                'block_config'       => $blockConfig,
                'block_data'         => $blockData,
                'listing_fields'     => $listingFields,
                'is_fallback'        => $translation['is_fallback'] ?? true,
                'children'           => [],
            ];

            // Resolve media fields and expand file IDs inside nested structures.
            $blockPayload['block_data'] = $this->mergeFileMetadata(
                $blockPayload['block_data'],
                $schemaFields,
                $fileMetaMap
            );
            if ($this->entryReferenceResolver !== null) {
                $blockPayload['block_data'] = $this->entryReferenceResolver->hydrateBlockData(
                    $blockPayload['block_data'],
                    $schemaFields,
                    $referenceMap
                );
            }

            $serializedMap[$instanceId] = $blockPayload;
            $ownerByInstanceId[$instanceId] = (int) $instance['owner_id'];
        }

        // Build tree: attach children to their parent's 'children' array, return only top-level
        $childrenByParent = [];
        foreach ($serializedMap as $instanceId => $block) {
            $parentId = $block['parent_instance_id'];
            if ($parentId !== null) {
                $childrenByParent[$parentId][] = $instanceId;
            }
        }

        $topLevelByOwner = array_fill_keys($ownerIds, []);
        foreach ($serializedMap as $instanceId => $block) {
            if ($block['parent_instance_id'] === null) {
                $block['children'] = $this->buildChildren($instanceId, $serializedMap, $childrenByParent);
                $ownerId = $ownerByInstanceId[$instanceId] ?? 0;
                if (isset($topLevelByOwner[$ownerId])) {
                    $topLevelByOwner[$ownerId][] = $block;
                }
            }
        }

        foreach ($topLevelByOwner as &$blocks) {
            usort($blocks, static fn (array $a, array $b): int => $a['sort_order'] <=> $b['sort_order']);
        }
        unset($blocks);

        return $topLevelByOwner;
    }

    // ─── Private helpers ────────────────────────────────────────────────────────

    /**
     * Recursively build the children array for a given parent instance.
     *
     * @param  int                                   $parentId
     * @param  array<int, array<string, mixed>>      $serializedMap
     * @param  array<int, list<int>>                 $childrenByParent
     * @return list<array<string, mixed>>
     */
    private function buildChildren(int $parentId, array $serializedMap, array $childrenByParent): array
    {
        $childIds = $childrenByParent[$parentId] ?? [];
        if (empty($childIds)) {
            return [];
        }

        $children = [];
        foreach ($childIds as $childId) {
            if (!isset($serializedMap[$childId])) {
                continue;
            }
            $child             = $serializedMap[$childId];
            $child['children'] = $this->buildChildren($childId, $serializedMap, $childrenByParent);
            $children[]        = $child;
        }

        usort($children, static fn (array $a, array $b): int => $a['sort_order'] <=> $b['sort_order']);

        return $children;
    }

    /**
     * Parse the schema_definition JSON string and return only the 'fields' map.
     *
     * @return array<string, array<string, mixed>>
     */
    private function parseSchemaDefinition(string $schemaDef): array
    {
        if ($schemaDef === '') {
            return [];
        }
        $schema = json_decode($schemaDef, true);
        if (!is_array($schema)) {
            return [];
        }

        return $schema;
    }

    /**
     * Merge resolved media_reference and repeater fields into block_data.
     *
     * @param  array<string, mixed>        $blockData
     * @param  array<string, array<string, mixed>> $schemaFields
     * @param  array<int, array{url: string|null, variants: array<string, mixed>|null}> $fileMetaMap keyed by file_id
     * @return array<string, mixed>
     */
    private function mergeFileMetadata(array $blockData, array $schemaFields, array $fileMetaMap): array
    {
        foreach ($schemaFields as $fieldKey => $fieldDef) {
            $type = $fieldDef['type'] ?? 'string';

            if ($type === 'media_reference') {
                $this->mergeMediaReferenceField($blockData, $fieldKey, $fileMetaMap);
            } elseif ($type === 'repeater') {
                $items      = $blockData[$fieldKey] ?? [];
                $itemFields = $fieldDef['item_fields'] ?? [];
                if (!is_array($items) || !is_array($itemFields)) {
                    continue;
                }

                $enriched = [];
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        $enriched[] = $item;
                        continue;
                    }
                    $enriched[] = $this->mergeFileMetadata($item, $itemFields, $fileMetaMap);
                }
                $blockData[$fieldKey] = $enriched;
            } elseif (in_array($type, ['group', 'fieldset'], true)) {
                $nestedFields = $fieldDef['fields'] ?? [];
                $nestedData   = $blockData[$fieldKey] ?? [];
                if (is_array($nestedData) && is_array($nestedFields)) {
                    $blockData[$fieldKey] = $this->mergeFileMetadata($nestedData, $nestedFields, $fileMetaMap);
                }
            }
        }

        return $blockData;
    }

    /**
     * Normalize a media_reference field into the canonical nested payload.
     *
     * @param array<string, mixed> $blockData
     * @param array<int, array{url: string|null, variants: array<string, mixed>|null}> $fileMetaMap
     */
    private function mergeMediaReferenceField(array &$blockData, string $fieldKey, array $fileMetaMap): void
    {
        $reference = is_array($blockData[$fieldKey] ?? null) ? $blockData[$fieldKey] : [];
        $sourceKind = strtolower(trim((string) ($reference['source_kind'] ?? '')));
        $url = isset($reference['url']) && is_scalar($reference['url'])
            ? trim((string) $reference['url'])
            : '';
        $url = $url !== '' ? $url : null;
        $variants = is_array($reference['variants'] ?? null) ? $reference['variants'] : null;

        if ($sourceKind === 'external_url') {
            $blockData[$fieldKey] = [
                'source_kind' => 'external_url',
                'file_id'     => null,
                'url'         => $this->fileUrlResolver->publicUrl($url),
                'variants'    => null,
            ];
            return;
        }

        $fileId = $this->fileUrlResolver->resolveMediaReferenceFileId($reference);
        if ($fileId !== null) {
            $meta = $fileMetaMap[$fileId] ?? null;
            $blockData[$fieldKey] = [
                'source_kind' => 'hub_file',
                'file_id'     => $fileId,
                'url'         => $this->fileUrlResolver->publicUrl($meta['url'] ?? $url),
                'variants'    => $meta['variants'] ?? $variants,
            ];
            return;
        }

        $blockData[$fieldKey] = [
            'source_kind' => $sourceKind === 'hub_file' ? 'hub_file' : 'external_url',
            'file_id'     => null,
            'url'         => $this->fileUrlResolver->publicUrl($url),
            'variants'    => null,
        ];
    }

    /**
     * Batch-resolve block_instance translations for a list of instance IDs.
     * Falls back to the default language when no translation exists for the target.
     *
     * @param  list<int> $instanceIds
     * @param  string    $langCode
     * @param  object    $db
     * @return array<int, array<string, mixed>>     keyed by instance_id
     */
    private function batchResolveBlockTranslations(
        array $instanceIds,
        string $langCode,
        object $db
    ): array {
        [$langId, $defaultLangId] = $this->resolveLanguageIds($langCode, $db);

        $langIds = array_unique(array_filter([$langId, $defaultLangId]));

        if (empty($langIds) || empty($instanceIds)) {
            return [];
        }

        $result = $db->table('cms_block_instance_translations')
            ->whereIn('instance_id', $instanceIds)
            ->whereIn('language_id', $langIds)
            ->where('is_published', 1)
            ->get();
        $rows = $result ? $result->getResultArray() : [];

        $map = [];
        foreach ($rows as $row) {
            $iid = (int) $row['instance_id'];
            $lid = (int) $row['language_id'];
            if (!isset($map[$iid]) || $lid === $langId) {
                $map[$iid] = [
                    'block_data'  => $row['block_data'],
                    'is_fallback' => $lid !== $langId,
                ];
            }
        }

        return $map;
    }

    /**
     * Returns [targetLangId, defaultLangId].
     * Queries the DB once; results should be cached at the connection layer.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function resolveLanguageIds(string $langCode, object $db): array
    {
        $result = $db->table('cms_languages')
            ->whereIn('code', [$langCode])
            ->orWhere('is_default', 1)
            ->where('is_active', 1)
            ->get();
        $rows = $result ? $result->getResultArray() : [];

        $targetId  = null;
        $defaultId = null;

        foreach ($rows as $row) {
            if ($row['code'] === $langCode) {
                $targetId = (int) $row['id'];
            }
            if ((int) $row['is_default'] === 1) {
                $defaultId = (int) $row['id'];
            }
        }

        return [$targetId ?? $defaultId, $defaultId];
    }
}
