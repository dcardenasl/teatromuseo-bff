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

/** One-read Catalog collection-item workspace for authenticated Admin screens. */
final class AdminCatalogWorkspaceController extends BaseProxyController
{
    public function index(): ResponseInterface
    {
        return $this->workspace(null);
    }

    public function item(string $itemId): ResponseInterface
    {
        if (! ctype_digit($itemId) || (int) $itemId < 1) {
            throw new ValidationException('Invalid Catalog identifier.', ['itemId' => 'Must be a positive integer.']);
        }

        return $this->workspace((int) $itemId);
    }

    private function workspace(?int $itemId): ResponseInterface
    {
        $authContext = ContextHolder::get();
        if ($authContext?->user_id === null) {
            throw new AuthenticationException('Missing authenticated user context.');
        }

        return $this->handleOperation(function () use ($authContext, $itemId): ResponseInterface {
            return $this->response->setJSON(ApiResponse::success([
                'version' => 1,
                'generated_at' => date(DATE_ATOM),
                'context' => 'catalog-collection-item-workspace',
                'source' => ['catalog' => 'ok', 'cms' => 'ok', 'state' => 'ok'],
                'sections' => Services::adminReadCatalogCollectionItemWorkspace()->workspace(
                    $itemId,
                    $authContext->permissions,
                ),
            ]));
        }, 'Catalog collection item workspace source');
    }
}
