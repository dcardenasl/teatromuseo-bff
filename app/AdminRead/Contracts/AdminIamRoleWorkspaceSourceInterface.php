<?php

declare(strict_types=1);

namespace App\AdminRead\Contracts;

/** Hub-owned IAM role editor projection: role, all permissions, assigned permission ids. */
interface AdminIamRoleWorkspaceSourceInterface
{
    /** @return array<string, mixed> */
    public function workspace(int $roleId, string $bearerToken): array;
}
