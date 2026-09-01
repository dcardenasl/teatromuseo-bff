<?php

declare(strict_types=1);

namespace App\AdminRead\Contracts;

/** Permission-aware Catalog technique workspace reader. */
interface AdminCatalogTechniqueSourceInterface
{
    /**
     * @param list<string> $permissions
     * @return array<string, mixed>
     */
    public function workspace(?int $techniqueId, array $permissions): array;
}
