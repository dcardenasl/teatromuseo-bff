<?php

declare(strict_types=1);

namespace App\AdminRead\Contracts;

/** Permission-aware reader for the Admin file-usage snapshot. */
interface AdminFileUsageSourceInterface
{
    /**
     * @param list<string> $permissions
     * @return array{complete: bool, source: array<string, string>, usages: list<array<string, mixed>>}
     */
    public function readSnapshot(int $fileId, string $bearerToken, array $permissions): array;
}
