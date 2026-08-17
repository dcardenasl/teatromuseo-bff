<?php

declare(strict_types=1);

namespace App\AdminRead\Contracts;

interface AdminDashboardTranslationsSourceInterface
{
    /**
     * Read the CMS translation summary using the authenticated visitor token.
     *
     * @param list<string> $permissions
     * @return array{sections: array<string, mixed>}
     */
    public function read(array $permissions, string $bearerToken): array;
}
