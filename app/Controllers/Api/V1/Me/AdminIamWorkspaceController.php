<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Me;

use App\Controllers\BaseProxyController;
use App\Libraries\Hub\HubClient;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use dcardenasl\Ci4ApiCore\Exceptions\AuthenticationException;
use dcardenasl\Ci4ApiCore\Http\ApiResponse;
use dcardenasl\Ci4ApiCore\Http\ContextHolder;

/** Hub-owned IAM role editor projection for authenticated Admin clients. */
final class AdminIamWorkspaceController extends BaseProxyController
{
    public function role(string $roleId): ResponseInterface
    {
        if (! ctype_digit($roleId) || (int) $roleId < 1) {
            throw new \InvalidArgumentException('Role identifier must be a positive integer.');
        }

        $context = ContextHolder::get();
        $bearer = $this->extractBearerToken();
        if ($context?->user_id === null || $bearer === null) {
            throw new AuthenticationException('Missing authenticated user context.');
        }

        return $this->handleOperation(function () use ($bearer, $roleId): ResponseInterface {
            /** @var HubClient $hubClient */
            $hubClient = Services::hubDashboardClient();
            $payload = $hubClient->get('/api/v1/iam/roles/' . $roleId . '/workspace', $bearer);
            $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
            if (is_array($data['data'] ?? null) && ! array_key_exists('role', $data)) {
                $data = $data['data'];
            }

            return $this->response->setJSON(ApiResponse::success([
                'version' => 1,
                'role' => is_array($data['role'] ?? null) ? $data['role'] : [],
                'allPermissions' => is_array($data['allPermissions'] ?? null) ? $data['allPermissions'] : [],
                'assignedPermissionIds' => is_array($data['assignedPermissionIds'] ?? null) ? $data['assignedPermissionIds'] : [],
            ]));
        }, 'Hub IAM role workspace source');
    }

    private function extractBearerToken(): ?string
    {
        $header = $this->request->getHeaderLine('Authorization');
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }
}
