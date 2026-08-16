<?php

declare(strict_types=1);

namespace App\AdminRead\Contracts;

interface AdminDashboardSourceInterface
{
    /**
     * Read the source projection visible to the supplied permission scope.
     *
     * @param list<string> $permissions
     * @return array{sections: array<string, mixed>}
     */
    public function read(array $permissions): array;
}
