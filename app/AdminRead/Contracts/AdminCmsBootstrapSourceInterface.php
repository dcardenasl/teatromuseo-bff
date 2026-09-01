<?php

declare(strict_types=1);

namespace App\AdminRead\Contracts;

/** Permission-aware composite CMS reads consumed by the Admin. */
interface AdminCmsBootstrapSourceInterface
{
    /**
     * @param list<string> $permissions
     * @return array<string, mixed>
     */
    public function entryFormOptions(?int $entryId, array $permissions): array;

    /**
     * @param list<string> $permissions
     * @return array<string, mixed>
     */
    public function pageFormOptions(?int $pageId, array $permissions): array;

    /**
     * @param list<string> $permissions
     * @return array<string, mixed>
     */
    public function menuEditorBootstrap(int $menuId, ?int $itemId, array $permissions): array;

    /**
     * @param list<string> $permissions
     * @return array<string, mixed>
     */
    public function siteIdentityBootstrap(array $permissions): array;
}
