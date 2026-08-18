<?php

declare(strict_types=1);

namespace App\PublicRead\Cms;

use App\PublicRead\Support\FileMetaResolverInterface;

/**
 * Canonical file URL resolver.
 *
 * Resolves Hub file IDs to public URLs through a {@see FileMetaResolverInterface}.
 * The only implementation wired into this repo is
 * {@see \App\PublicRead\Support\DirectDbFileMetaResolver}, which reads
 * `hub_readonly` directly — it is not cached. Batching (`resolveMany()`)
 * is what keeps this cheap: callers collect every file ID a projection
 * needs first and resolve them in one query, rather than one call per file.
 *
 * The Domain's `cms` database has NO `files` table — files are owned by
 * the Hub. This class is the single point of contact for file URL resolution
 * in the Domain, keeping the boundary explicit.
 */
class FileUrlResolver
{
    private FileMetaResolverInterface $fileMetaResolver;

    private string $publicBaseUrl = '';

    public function __construct(FileMetaResolverInterface $fileMetaResolver, ?string $publicBaseUrl = null)
    {
        $this->fileMetaResolver = $fileMetaResolver;
        $this->publicBaseUrl = rtrim(
            trim($publicBaseUrl ?? (string) (config('Hub')->publicUrl ?? config('Hub')->url)),
            '/'
        );
    }

    // ─── Public API (interface unchanged) ────────────────────────────────────

    public function resolve(int $fileId, string $context = 'public'): ?string
    {
        if ($fileId <= 0) {
            return null;
        }

        $map = $this->fileMetaResolver->resolveMany([$fileId], $context);
        $row = $map[$fileId] ?? null;

        return $row !== null ? $this->resolveFromRow($row, $context) : null;
    }

    /**
     * Resolve a stored local path for a public response without persisting the
     * deployment host back into CMS data.
     */
    public function publicUrl(?string $url): ?string
    {
        if ($this->publicBaseUrl === '') {
            return $url !== null ? trim($url) : null;
        }

        return $this->normalizePublicUrl($url);
    }

    /**
     * Convert a local file URL into its portable storage representation.
     */
    public function storageUrl(?string $url): ?string
    {
        return $this->normalizeStoredUrl($url);
    }

    /**
     * @param  list<int>         $fileIds
     * @return array<int, string>
     */
    public function resolveMany(array $fileIds, string $context = 'public'): array
    {
        $fileIds = array_values(array_unique(array_filter(
            $fileIds,
            static fn ($id): bool => is_int($id) && $id > 0
        )));

        if (empty($fileIds)) {
            return [];
        }

        $metaMap = $this->fileMetaResolver->resolveMany($fileIds, $context);
        $urls    = [];

        foreach ($metaMap as $fileId => $row) {
            $resolved = $this->resolveFromRow($row, $context);
            if ($resolved !== null && $resolved !== '') {
                $urls[(int) $fileId] = $resolved;
            }
        }

        return $urls;
    }

    /**
     * Resolve public metadata (url and variants) for multiple file IDs.
     *
     * @param  list<int>  $fileIds
     * @return array<int, array{url: string|null, variants: array<string, mixed>|null}>
     */
    public function resolveManyMeta(array $fileIds, string $context = 'public'): array
    {
        $fileIds = array_values(array_unique(array_filter(
            $fileIds,
            static fn ($id): bool => is_int($id) && $id > 0
        )));

        if (empty($fileIds)) {
            return [];
        }

        $metaMap = $this->fileMetaResolver->resolveMany($fileIds, $context);
        $result  = [];

        foreach ($metaMap as $fileId => $row) {
            $url = $this->resolveFromRow($row, $context);

            $variants = $row['variants'] ?? null;
            if (is_string($variants) && $variants !== '') {
                $decoded  = json_decode($variants, true);
                $variants = is_array($decoded) ? $decoded : null;
            }
            if (is_array($variants)) {
                $variants = $this->normalizeVariants($variants, $context);
            }

            $result[(int) $fileId] = [
                'url'      => $url,
                'variants' => $variants,
            ];
        }

        return $result;
    }

    /**
     * Canonicalize a file-bearing URL field.
     *
     * If a file ID exists, it always wins over a stored URL.
     * Falls back to the stored URL when the Hub cannot resolve the ID.
     */
    public function resolveUrlValue(int|string|null $fileId, ?string $currentUrl = null, string $context = 'public'): ?string
    {
        $normalizedFileId = is_numeric($fileId) ? (int) $fileId : null;

        if ($context === 'storage') {
            return $this->normalizeStoredUrl($currentUrl);
        }

        if ($normalizedFileId !== null && $normalizedFileId > 0) {
            $resolved = $this->resolve($normalizedFileId, $context);
            if ($resolved !== null && $resolved !== '') {
                return $resolved;
            }

            return $this->normalizePublicUrl($currentUrl);
        }

        return $this->normalizePublicUrl($currentUrl);
    }

    /**
     * Normalize entry translation media into canonical nested objects.
     *
     * Relational persistence columns are projected into one public contract.
     *
     * @param array<string, mixed> $translation
     * @param array<int, array<string, mixed>>|null $metaMap
     * @return array<string, mixed>
     */
    public function normalizeEntryTranslation(array $translation, string $context = 'public', ?array $metaMap = null): array
    {
        $featuredImage = $translation['featured_image'] ?? null;
        if (is_array($featuredImage) && $featuredImage !== []) {
            $translation['featured_image'] = $this->normalizeMediaReference($featuredImage, $context, $metaMap);
        } else {
            $translation['featured_image'] = $this->normalizeMediaReference([
                'file_id' => $translation['featured_file_id'] ?? null,
                'url'     => isset($translation['featured_image_url']) ? (string) $translation['featured_image_url'] : null,
            ], $context, $metaMap);
        }

        $ogImage = $translation['og_image'] ?? null;
        if (is_array($ogImage) && $ogImage !== []) {
            $translation['og_image'] = $this->normalizeMediaReference($ogImage, $context, $metaMap);
        } else {
            $translation['og_image'] = $this->normalizeMediaReference([
                'file_id' => $translation['og_image_file_id'] ?? null,
                'url'     => isset($translation['og_image_url']) ? (string) $translation['og_image_url'] : null,
            ], $context, $metaMap);
        }

        unset(
            $translation['featured_file_id'],
            $translation['featured_image_url'],
            $translation['og_image_file_id'],
            $translation['og_image_url']
        );

        return $translation;
    }

    /**
     * @param  array<string, mixed> $translation
     * @return array<string, mixed>
     */
    public function normalizePageTranslation(array $translation, string $context = 'public'): array
    {
        $ogImage = $translation['og_image'] ?? null;
        if (is_array($ogImage) && $ogImage !== []) {
            $translation['og_image'] = $this->normalizeMediaReference($ogImage, $context);
        } else {
            $translation['og_image'] = $this->normalizeMediaReference([
                'file_id' => $translation['og_image_file_id'] ?? null,
                'url'     => isset($translation['og_image_url']) ? (string) $translation['og_image_url'] : null,
            ], $context);
        }

        unset($translation['og_image_file_id'], $translation['og_image_url']);

        return $translation;
    }

    /**
     * Normalize all file-bearing fields in a block payload according to its schema.
     *
     * @param  array<string, mixed>               $blockData
     * @param  array<string, array<string, mixed>> $schemaFields
     * @return array<string, mixed>
     */
    public function normalizeBlockData(array $blockData, array $schemaFields, string $context = 'public'): array
    {
        return $this->normalizeSchemaPayload($blockData, $schemaFields, $context);
    }

    /**
     * Normalize a block_config payload with the same schema-driven rules used
     * for block_data. Media reference config fields share the same canonical
     * nested shape as translated media fields.
     *
     * @param  array<string, mixed>                $blockConfig
     * @param  array<string, array<string, mixed>> $schemaFields
     * @return array<string, mixed>
     */
    public function normalizeBlockConfig(array $blockConfig, array $schemaFields, string $context = 'public'): array
    {
        return $this->normalizeSchemaPayload($blockConfig, $schemaFields, $context);
    }

    /**
     * Collect every file ID referenced by a block payload.
     *
     * @param  array<string, mixed>               $blockData
     * @param  array<string, array<string, mixed>> $schemaFields
     * @return list<int>
     */
    public function collectBlockFileIds(array $blockData, array $schemaFields): array
    {
        return $this->collectSchemaFileIds($blockData, $schemaFields);
    }

    /**
     * Collect file IDs from any schema-driven payload, including nested media
     * reference config fields.
     *
     * @param  array<string, mixed>               $payload
     * @param  array<string, array<string, mixed>> $schemaFields
     * @return list<int>
     */
    public function collectSchemaFileIds(array $payload, array $schemaFields): array
    {
        $fileIds = [];

        foreach ($schemaFields as $fieldKey => $fieldDef) {
            $type = strtolower((string) ($fieldDef['type'] ?? 'string'));

            if ($type === 'file') {
                $fileIds[] = $this->resolveFileIdFromValue(
                    $payload[$fieldKey . '_file_id'] ?? null,
                    isset($payload[$fieldKey . '_url']) ? (string) $payload[$fieldKey . '_url'] : null
                );
                continue;
            }

            if ($type === 'media_reference') {
                $reference = $payload[$fieldKey] ?? [];
                $fileIds[] = $this->resolveMediaReferenceFileId($reference);
                continue;
            }

            if ($type === 'repeater') {
                $items = $payload[$fieldKey] ?? [];
                if (! is_array($items)) {
                    continue;
                }

                $itemFields = is_array($fieldDef['item_fields'] ?? null) ? (array) $fieldDef['item_fields'] : [];
                foreach ($items as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $fileIds = array_merge($fileIds, $this->collectSchemaFileIds($item, $itemFields));
                }
                continue;
            }

            if (in_array($type, ['group', 'fieldset'], true)) {
                $nestedFields = is_array($fieldDef['fields'] ?? null) ? (array) $fieldDef['fields'] : [];
                $nestedData   = $payload[$fieldKey] ?? null;
                if (is_array($nestedData) && $nestedFields !== []) {
                    $fileIds = array_merge($fileIds, $this->collectSchemaFileIds($nestedData, $nestedFields));
                }
            }
        }

        return array_values(array_unique(array_filter(
            $fileIds,
            static fn ($id): bool => is_int($id) && $id > 0
        )));
    }

    public function resolveFileIdFromValue(int|string|null $fileId, ?string $url = null): ?int
    {
        if (is_numeric($fileId) && (int) $fileId > 0) {
            return (int) $fileId;
        }

        return null;
    }

    /**
     * Normalize a media_reference payload into the canonical nested array.
     *
     * @param mixed $reference
     * @param array<int, array<string, mixed>>|null $metaMap
     * @return array{source_kind: string, file_id: int|null, url: string|null, variants: array<string, mixed>|null}
     */
    public function normalizeMediaReference(mixed $reference, string $context = 'public', ?array $metaMap = null): array
    {
        if (is_string($reference) || is_int($reference)) {
            $reference = ['url' => (string) $reference];
        }

        if (! is_array($reference)) {
            $reference = [];
        }

        $sourceKindRaw = strtolower(trim((string) ($reference['source_kind'] ?? '')));
        $url = isset($reference['url'])
            ? ($context === 'storage'
                ? $this->normalizeStoredUrl($reference['url'])
                : $this->normalizePublicUrl($reference['url']))
            : null;
        $fileId = $this->resolveFileIdFromValue($reference['file_id'] ?? null, $url);
        $variants = is_array($reference['variants'] ?? null) ? $reference['variants'] : null;

        if ($variants !== null) {
            $variants = $this->normalizeVariants($variants, $context);
        }

        if ($sourceKindRaw === 'external_url') {
            return [
                'source_kind' => 'external_url',
                'file_id' => null,
                'url' => $url,
                'variants' => null,
            ];
        }

        if ($sourceKindRaw === 'hub_file' || $fileId !== null) {
            if ($context !== 'storage' && $variants === null && $fileId !== null) {
                $map = $metaMap ?? $this->fileMetaResolver->resolveMany([$fileId], $context);
                $row = $map[$fileId] ?? null;
                if ($row !== null && isset($row['url']) && is_string($row['url'])) {
                    $url = $row['url'];
                }
                if ($row !== null && isset($row['variants'])) {
                    $variants = is_string($row['variants']) ? json_decode($row['variants'], true) : $row['variants'];
                    if (is_array($variants)) {
                        $variants = $this->normalizeVariants($variants, $context);
                    }
                }
            }

            return [
                'source_kind' => 'hub_file',
                'file_id' => $fileId,
                'url' => $metaMap === null ? $this->resolveUrlValue($fileId, $url, $context) : $url,
                'variants' => $variants,
            ];
        }

        return [
            'source_kind' => 'external_url',
            'file_id' => null,
            'url' => $url,
            'variants' => null,
        ];
    }

    /**
     * @param mixed $reference
     */
    public function resolveMediaReferenceFileId(mixed $reference): ?int
    {
        if (is_array($reference)) {
            $sourceKind = strtolower(trim((string) ($reference['source_kind'] ?? '')));
            if ($sourceKind === 'external_url') {
                return null;
            }

            $fileId = $reference['file_id'] ?? null;
            if (is_numeric($fileId) && (int) $fileId > 0) {
                return (int) $fileId;
            }

            return null;
        }

        return $this->resolveFileIdFromValue(is_int($reference) || is_string($reference) ? $reference : null, is_string($reference) ? $reference : null);
    }

    /**
     * @param mixed $reference
     */
    public function resolveMediaReferenceUrl(mixed $reference, string $context = 'public'): ?string
    {
        if (is_array($reference)) {
            $normalized = $this->normalizeMediaReference($reference, $context);

            return $normalized['url'];
        }

        return $this->resolveUrlValue(is_int($reference) || is_string($reference) ? $reference : null, is_string($reference) ? $reference : null, $context);
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $row
     */
    private function resolveFromRow(array $row, string $context): ?string
    {
        if ($context === 'original') {
            return $this->normalizePublicUrl($row['url'] ?? null);
        }

        $variants = $row['variants'] ?? null;
        if (is_string($variants) && $variants !== '') {
            $decoded  = json_decode($variants, true);
            $variants = is_array($decoded) ? $decoded : null;
        }

        if (is_array($variants) && $variants !== []) {
            foreach ($this->preferredVariantKeys($context) as $variantKey) {
                if (! isset($variants[$variantKey]) || ! is_array($variants[$variantKey])) {
                    continue;
                }

                $variantUrl = $this->normalizePublicUrl($variants[$variantKey]['url'] ?? null);
                if ($variantUrl !== null) {
                    return $variantUrl;
                }
            }
        }

        return $this->normalizePublicUrl($row['url'] ?? null);
    }

    /** @return list<string> */
    private function preferredVariantKeys(string $context): array
    {
        return match ($context) {
            'original'                    => [],
            'admin', 'thumbnail', 'thumb' => ['thumb', 'sm', 'md', 'lg'],
            default                       => ['lg', 'md', 'sm', 'thumb'],
        };
    }

    private function normalizeStoredUrl(int|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $url = trim((string) $value);

        if ($url === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || ! $this->isLocalUploadPath($path)) {
            return $url;
        }

        return '/uploads/'
            . ltrim(substr($path, strpos($path, '/uploads/') + 9), '/')
            . $this->urlSuffix($url);
    }

    private function normalizePublicUrl(int|string|null $value): ?string
    {
        $storedUrl = $this->normalizeStoredUrl($value);
        if ($storedUrl === null) {
            return null;
        }

        $path = parse_url($storedUrl, PHP_URL_PATH);
        if (! is_string($path)) {
            return $storedUrl;
        }

        if (str_starts_with('/' . ltrim($path, '/'), '/files/')) {
            // A file ID is not itself a public binary URL. If the Hub did not
            // resolve it, returning a fabricated /files/{id}/view link only
            // creates a broken asset in every consuming application.
            return null;
        }

        if (! $this->isLocalUploadPath($path) || $this->publicBaseUrl === '') {
            return $storedUrl;
        }

        return $this->publicBaseUrl . '/uploads/'
            . ltrim(substr($path, strpos($path, '/uploads/') + 9), '/')
            . $this->urlSuffix($storedUrl);
    }

    private function isLocalUploadPath(string $path): bool
    {
        return str_starts_with('/' . ltrim($path, '/'), '/uploads/');
    }

    private function urlSuffix(string $url): string
    {
        $suffix = '';
        $query = parse_url($url, PHP_URL_QUERY);
        $fragment = parse_url($url, PHP_URL_FRAGMENT);

        if (is_string($query) && $query !== '') {
            $suffix .= '?' . $query;
        }
        if (is_string($fragment) && $fragment !== '') {
            $suffix .= '#' . $fragment;
        }

        return $suffix;
    }

    /**
     * @param array<string, mixed> $variants
     * @return array<string, mixed>
     */
    private function normalizeVariants(array $variants, string $context): array
    {
        foreach ($variants as $key => $variant) {
            if (! is_array($variant) || ! array_key_exists('url', $variant)) {
                continue;
            }

            $variants[$key]['url'] = $context === 'storage'
                ? $this->normalizeStoredUrl($variant['url'])
                : $this->normalizePublicUrl($variant['url']);
        }

        return $variants;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, array<string, mixed>> $schemaFields
     * @return array<string, mixed>
     */
    private function normalizeSchemaPayload(array $payload, array $schemaFields, string $context): array
    {
        foreach ($schemaFields as $fieldKey => $fieldDef) {
            $type = strtolower((string) ($fieldDef['type'] ?? 'string'));

            if ($type === 'file') {
                $fileIdKey = $fieldKey . '_file_id';
                $urlKey    = $fieldKey . '_url';
                $payload[$urlKey] = $this->resolveUrlValue(
                    $payload[$fileIdKey] ?? null,
                    isset($payload[$urlKey]) ? (string) $payload[$urlKey] : null,
                    $context
                );
                continue;
            }

            if ($type === 'media_reference') {
                $payload[$fieldKey] = $this->normalizeMediaReference($payload[$fieldKey] ?? [], $context);
                continue;
            }

            if ($type === 'repeater') {
                $items = $payload[$fieldKey] ?? [];
                if (! is_array($items)) {
                    continue;
                }

                $itemFields = is_array($fieldDef['item_fields'] ?? null) ? (array) $fieldDef['item_fields'] : [];
                $normalized = [];
                foreach ($items as $item) {
                    $normalized[] = is_array($item)
                        ? $this->normalizeSchemaPayload($item, $itemFields, $context)
                        : $item;
                }

                $payload[$fieldKey] = $normalized;
                continue;
            }

            if (in_array($type, ['group', 'fieldset'], true)) {
                $nestedFields = is_array($fieldDef['fields'] ?? null) ? (array) $fieldDef['fields'] : [];
                $nestedData   = $payload[$fieldKey] ?? null;
                if (is_array($nestedData) && $nestedFields !== []) {
                    $payload[$fieldKey] = $this->normalizeSchemaPayload($nestedData, $nestedFields, $context);
                }
            }
        }

        return $payload;
    }
}
