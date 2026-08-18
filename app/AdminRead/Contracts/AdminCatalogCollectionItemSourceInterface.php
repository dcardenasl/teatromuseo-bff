<?php

declare(strict_types=1);

namespace App\AdminRead\Contracts;

/** Permission-aware Catalog collection-item workspace reader. */
interface AdminCatalogCollectionItemSourceInterface
{
    /**
     * @param list<string> $permissions
     * @return array<string, mixed>
     */
    public function workspace(?int $itemId, array $permissions): array;
}
