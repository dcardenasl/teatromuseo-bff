<?php

declare(strict_types=1);

namespace App\AdminRead\Contracts;

/** Permission-aware Event editor workspace reader. */
interface AdminEventWorkspaceSourceInterface
{
    /**
     * @param list<string> $permissions
     * @return array<string, mixed>
     */
    public function workspace(?int $eventId, array $permissions): array;
}
