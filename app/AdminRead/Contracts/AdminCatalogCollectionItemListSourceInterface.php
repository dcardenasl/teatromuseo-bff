<?php

declare(strict_types=1);

namespace App\AdminRead\Contracts;

/** Bounded Catalog lookup projection for the collection-item list screen. */
interface AdminCatalogCollectionItemListSourceInterface
{
    /**
     * @param list<string> $permissions
     * @return array<string, mixed>
     */
    public function bootstrap(array $permissions): array;
}
