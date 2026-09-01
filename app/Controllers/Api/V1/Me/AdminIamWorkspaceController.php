<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Me;

use App\Controllers\BaseProxyController;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use dcardenasl\Ci4ApiCore\Exceptions\AuthenticationException;
use dcardenasl\Ci4ApiCore\Exceptions\ValidationException;
use dcardenasl\Ci4ApiCore\Http\ApiResponse;
use dcardenasl\Ci4ApiCore\Http\ContextHolder;

/** Hub-owned IAM role editor projection for authenticated Admin clients. */
final class AdminIamWorkspaceController extends BaseProxyController
{
    public function role(string $roleId): ResponseInterface
    {
        if (! ctype_digit($roleId) || (int) $roleId < 1) {
            throw new ValidationException('Invalid IAM identifier.', ['roleId' => 'Must be a positive integer.']);
        }

        $context = ContextHolder::get();
        $bearer = $this->extractBearerToken();
        if ($context?->user_id === null || $bearer === null) {
            throw new AuthenticationException('Missing authenticated user context.');
        }

        return $this->handleOperation(function () use ($bearer, $roleId): ResponseInterface {
            $sections = Services::adminReadIamRoleWorkspace()->workspace((int) $roleId, $bearer);

            return $this->response->setJSON(ApiResponse::success([
                'version' => 1,
                ...$sections,
            ]));
        }, 'Hub IAM role workspace source');
    }
}
