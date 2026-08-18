<?php

declare(strict_types=1);

namespace App\AdminRead\Hub;

use App\AdminRead\Contracts\AdminIamRoleWorkspaceSourceInterface;
use App\Libraries\Hub\HubClient;

/**
 * Thin transport + normalization layer over the Hub's own IAM role
 * workspace endpoint. IAM is Hub-owned: this source does not decide
 * authorization (the Hub's endpoint does, via the forwarded bearer token)
 * and does not read any database — it only shapes the response envelope.
 */
final class AdminIamRoleWorkspaceSource implements AdminIamRoleWorkspaceSourceInterface
{
    public function __construct(private readonly HubClient $client)
    {
    }

    /** @return array<string, mixed> */
    public function workspace(int $roleId, string $bearerToken): array
    {
        $data = $this->unwrap($this->client->get('/api/v1/iam/roles/' . $roleId . '/workspace', $bearerToken), 'role');

        return [
            'role' => is_array($data['role'] ?? null) ? $data['role'] : [],
            'allPermissions' => is_array($data['allPermissions'] ?? null) ? $data['allPermissions'] : [],
            'assignedPermissionIds' => is_array($data['assignedPermissionIds'] ?? null) ? $data['assignedPermissionIds'] : [],
        ];
    }

    /**
     * Hub responses may arrive double-wrapped (`{data: {data: {...}}}`)
     * depending on the calling client's envelope handling; unwrap once more
     * only when the outer `data` doesn't already carry the expected key.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function unwrap(array $payload, string $expectedKey): array
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        if (is_array($data['data'] ?? null) && ! array_key_exists($expectedKey, $data)) {
            $data = $data['data'];
        }

        return $data;
    }
}
