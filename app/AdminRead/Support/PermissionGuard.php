<?php

declare(strict_types=1);

namespace App\AdminRead\Support;

use dcardenasl\Ci4ApiCore\Exceptions\AuthorizationException;

/**
 * Shared permission check for `AdminRead` sources: every projection must
 * verify the caller's effective permissions before running a query, never
 * after. Extracted from three sources that reimplemented this identically
 * (`AdminCatalogCollectionItemSource`, `AdminEventWorkspaceSource`,
 * `AdminFileUsageSource`).
 */
final class PermissionGuard
{
    /** @param list<string> $permissions */
    public static function require(array $permissions, string $permission): void
    {
        if (! in_array($permission, $permissions, true)) {
            throw new AuthorizationException('The ' . $permission . ' permission is required.');
        }
    }
}
