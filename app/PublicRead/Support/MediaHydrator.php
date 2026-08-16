<?php

declare(strict_types=1);

namespace App\PublicRead\Support;

/**
 * Resolves Hub file metadata and projects it into the public media shape.
 *
 * Catalog and Event readers intentionally share this small projection so a
 * media contract change cannot drift between public-read domains.
 */
final readonly class MediaHydrator
{
    public function __construct(
        private FileMetaResolverInterface $fileMetaResolver,
    ) {
    }

    /**
     * @param array<int, mixed> $ids
     * @return array<int, array<string, mixed>>
     */
    public function resolve(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<int> $normalizedIds */
        $normalizedIds = array_values(array_unique(array_map(
            static fn (mixed $id): int => (int) $id,
            $ids,
        )));

        return $this->fileMetaResolver->resolveMany($normalizedIds);
    }

    /**
     * @param array<int, array<string, mixed>> $media
     * @return array<string, mixed>|null
     */
    public function item(array $media, int $id): ?array
    {
        if ($id <= 0 || ! isset($media[$id])) {
            return null;
        }

        $meta = $media[$id];

        return [
            'source_kind' => 'hub_file',
            'file_id' => $id,
            'url' => $meta['url'] ?? null,
            'variants' => is_string($meta['variants'] ?? null)
                ? json_decode($meta['variants'], true)
                : ($meta['variants'] ?? null),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $media
     * @return list<array<string, mixed>>
     */
    public function gallery(array $media, mixed $rawIds): array
    {
        $result = [];
        foreach (explode(',', (string) $rawIds) as $rawId) {
            $item = $this->item($media, (int) trim($rawId));
            if ($item !== null) {
                $result[] = $item;
            }
        }

        return $result;
    }
}
