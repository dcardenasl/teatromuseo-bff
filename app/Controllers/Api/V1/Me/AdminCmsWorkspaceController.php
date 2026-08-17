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

/** One-read CMS workspace projection for authenticated Admin pages. */
final class AdminCmsWorkspaceController extends BaseProxyController
{
    public function page(string $pageId): ResponseInterface
    {
        if (! ctype_digit($pageId) || (int) $pageId < 1) {
            throw new ValidationException('Invalid CMS identifier.', ['pageId' => 'Must be a positive integer.']);
        }

        $id = (int) $pageId;
        $instanceId = $this->request->getGet('instance_id');
        $instance = null;
        if ($instanceId !== null && $instanceId !== '') {
            if (! is_string($instanceId) || ! ctype_digit($instanceId) || (int) $instanceId < 1) {
                throw new ValidationException('Invalid CMS identifier.', ['instance_id' => 'Must be a positive integer.']);
            }
            $instance = (int) $instanceId;
        }

        $authContext = ContextHolder::get();
        if ($authContext?->user_id === null) {
            throw new AuthenticationException('Missing authenticated user context.');
        }

        return $this->handleOperation(function () use ($authContext, $id, $instance): ResponseInterface {
            return $this->response->setJSON(ApiResponse::success([
                'version' => 1,
                'generated_at' => date(DATE_ATOM),
                'context' => 'page-workspace',
                'source' => ['cms' => 'ok', 'hub' => 'ok', 'state' => 'ok'],
                'sections' => Services::adminReadCmsWorkspace()->pageWorkspace(
                    $id,
                    $instance,
                    $authContext->permissions,
                ),
            ]));
        }, 'CMS page workspace source');
    }

    public function entry(string $entryId): ResponseInterface
    {
        if (! ctype_digit($entryId) || (int) $entryId < 1) {
            throw new ValidationException('Invalid CMS identifier.', ['entryId' => 'Must be a positive integer.']);
        }

        $id = (int) $entryId;
        $instanceId = $this->request->getGet('instance_id');
        $instance = null;
        if ($instanceId !== null && $instanceId !== '') {
            if (! is_string($instanceId) || ! ctype_digit($instanceId) || (int) $instanceId < 1) {
                throw new ValidationException('Invalid CMS identifier.', ['instance_id' => 'Must be a positive integer.']);
            }
            $instance = (int) $instanceId;
        }

        $authContext = ContextHolder::get();
        if ($authContext?->user_id === null) {
            throw new AuthenticationException('Missing authenticated user context.');
        }

        return $this->handleOperation(function () use ($authContext, $id, $instance): ResponseInterface {
            return $this->response->setJSON(ApiResponse::success([
                'version' => 1,
                'generated_at' => date(DATE_ATOM),
                'context' => 'entry-workspace',
                'source' => ['cms' => 'ok', 'hub' => 'ok', 'state' => 'ok'],
                'sections' => Services::adminReadCmsWorkspace()->entryWorkspace(
                    $id,
                    $instance,
                    $authContext->permissions,
                ),
            ]));
        }, 'CMS entry workspace source');
    }
}
