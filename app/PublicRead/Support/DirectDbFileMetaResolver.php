<?php

declare(strict_types=1);

namespace App\PublicRead\Support;

use CodeIgniter\Database\BaseConnection;

/**
 * Read-only file metadata adapter used only by the BFF.
 *
 * It intentionally selects the four portable columns used by the Hub's
 * public metadata projection. The connection must be provisioned with
 * SELECT-only grants; this class contains no mutating query path.
 */
final class DirectDbFileMetaResolver implements FileMetaResolverInterface
{
    /** @param BaseConnection<mixed, mixed> $hubDb */
    public function __construct(
        private readonly BaseConnection $hubDb,
        private readonly string $publicBaseUrl = '',
        private readonly int $maxBatchSize = 200,
    ) {
    }

    public function resolveMany(array $fileIds, string $context = 'public'): array
    {
        $ids = array_slice(self::normalizeIds($fileIds), 0, max(1, $this->maxBatchSize));
        if ($ids === []) {
            return [];
        }

        $query = $this->hubDb->table('files')
            ->select('id, path, url, variants')
            ->whereIn('id', $ids)
            ->where('deleted_at IS NULL', null, false)
            ->get();
        $rows = $query !== false ? $query->getResultArray() : [];
        $result = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $variants = self::decodeVariants($row['variants'] ?? null);
            $result[$id] = [
                'url' => $this->resolveRowUrl($row, $variants, $context),
                'variants' => $this->normalizeVariants($variants),
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $variants
     */
    private function resolveRowUrl(array $row, array $variants, string $context): ?string
    {
        if ($context === 'original') {
            return $this->normalizePublicUrl($row['url'] ?? $row['path'] ?? null);
        }
        foreach ($this->preferredVariantKeys($context) as $key) {
            if (is_array($variants[$key] ?? null)) {
                $url = $this->normalizePublicUrl($variants[$key]['url'] ?? $variants[$key]['path'] ?? null);
                if ($url !== null) {
                    return $url;
                }
            }
        }

        return $this->normalizePublicUrl($row['url'] ?? $row['path'] ?? null);
    }

    /** @return list<string> */
    private function preferredVariantKeys(string $context): array
    {
        return match ($context) {
            'original' => [],
            'admin', 'thumbnail', 'thumb' => ['thumb', 'sm', 'md', 'lg'],
            default => ['lg', 'md', 'sm', 'thumb'],
        };
    }

    private function normalizePublicUrl(mixed $value): ?string
    {
        $url = is_scalar($value) ? trim((string) $value) : '';
        if ($url === '') {
            return null;
        }
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path)) {
            return null;
        }
        if (str_starts_with('/' . ltrim($path, '/'), '/files/')) {
            return null;
        }
        if (! str_contains('/' . ltrim($path, '/'), '/uploads/') || $this->publicBaseUrl === '') {
            return $url;
        }

        return rtrim($this->publicBaseUrl, '/') . '/uploads/'
            . ltrim(substr($path, strpos($path, '/uploads/') + 9), '/')
            . self::urlSuffix($url);
    }

    /**
     * @param array<string, mixed> $variants
     * @return array<string, mixed>|null
     */
    private function normalizeVariants(array $variants): ?array
    {
        if ($variants === []) {
            return null;
        }
        foreach ($variants as $key => $variant) {
            if (is_array($variant) && array_key_exists('url', $variant)) {
                $variants[$key]['url'] = $this->normalizePublicUrl($variant['url']);
            } elseif (is_array($variant) && array_key_exists('path', $variant)) {
                $variants[$key]['url'] = $this->normalizePublicUrl($variant['path']);
            }
        }

        return $variants;
    }

    /** @return array<string, mixed> */
    private static function decodeVariants(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param list<int> $fileIds
     * @return list<int>
     */
    private static function normalizeIds(array $fileIds): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $fileIds),
            static fn (int $id): bool => $id > 0,
        )));
    }

    private static function urlSuffix(string $url): string
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
}
