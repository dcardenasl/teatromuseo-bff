<?php

declare(strict_types=1);

namespace App\AdminRead\Contracts;

/** Permission-aware readers for the Admin Event lookup projections. */
interface AdminEventLookupSourceInterface
{
    /**
     * @param list<string> $permissions
     * @return array<string, list<array<string, mixed>>>
     */
    public function read(string $context, array $permissions): array;
}
