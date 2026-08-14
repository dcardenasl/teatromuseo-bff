<?php

declare(strict_types=1);

namespace App\PublicRead\Support;

interface FileMetaResolverInterface
{
    /**
     * @param list<int> $fileIds
     * @return array<int, array{url: string|null, variants: array<string, mixed>|null}>
     */
    public function resolveMany(array $fileIds, string $context = 'public'): array;
}
