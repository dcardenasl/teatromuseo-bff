<?php

declare(strict_types=1);

namespace App\AdminRead\Contracts;

interface AdminDashboardTranslationsSourceInterface
{
    /**
     * Read the CMS translation summary from the permission-filtered read seam.
     *
     * The bearer argument remains part of the adapter contract for source
     * compatibility; the direct SQL dashboard projection does not need it.
     *
     * @param list<string> $permissions
     * @return array{sections: array<string, mixed>}
     */
    public function read(array $permissions, string $bearerToken): array;
}
