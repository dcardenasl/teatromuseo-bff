<?php

declare(strict_types=1);

namespace App\AdminRead\Contracts;

/** Single-read CMS workspace projection for authenticated Admin screens. */
interface AdminCmsWorkspaceSourceInterface
{
    /**
     * @param list<string> $permissions
     * @return array<string, mixed>
     */
    public function pageWorkspace(int $pageId, ?int $instanceId, array $permissions): array;

    /**
     * @param list<string> $permissions
     * @return array<string, mixed>
     */
    public function entryWorkspace(int $entryId, ?int $instanceId, array $permissions): array;
}
