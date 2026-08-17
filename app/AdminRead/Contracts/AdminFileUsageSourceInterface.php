<?php

declare(strict_types=1);

namespace App\AdminRead\Contracts;

/** Permission-aware readers for the Admin file-usage projection. */
interface AdminFileUsageSourceInterface
{
    /**
     * @param list<string> $permissions
     * @return list<array<string, mixed>>
     */
    public function readHub(int $fileId, string $bearerToken, array $permissions): array;

    /**
     * @param list<string> $permissions
     * @return list<array<string, mixed>>
     */
    public function readCms(int $fileId, array $permissions): array;

    /**
     * @param list<array<string, mixed>> $hubUsages
     * @param list<array<string, mixed>> $cmsUsages
     * @return list<array<string, mixed>>
     */
    public function merge(array $hubUsages, array $cmsUsages): array;
}
