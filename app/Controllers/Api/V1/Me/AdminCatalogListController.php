<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Me;

use App\Controllers\BaseProxyController;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use dcardenasl\Ci4ApiCore\Exceptions\AuthenticationException;
use dcardenasl\Ci4ApiCore\Http\ApiResponse;
use dcardenasl\Ci4ApiCore\Http\ContextHolder;

/** One-read Catalog list bootstrap for authenticated Admin screens. */
final class AdminCatalogListController extends BaseProxyController
{
    public function index(): ResponseInterface
    {
        $authContext = ContextHolder::get();
        if ($authContext?->user_id === null) {
            throw new AuthenticationException('Missing authenticated user context.');
        }

        return $this->handleOperation(function () use ($authContext): ResponseInterface {
            return $this->response->setJSON(ApiResponse::success([
                'version' => 1,
                'generated_at' => date(DATE_ATOM),
                'context' => 'catalog-collection-item-list-bootstrap',
                'source' => ['catalog' => 'ok', 'state' => 'ok'],
                'sections' => Services::adminReadCatalogCollectionItemList()->bootstrap($authContext->permissions),
            ]));
        }, 'Catalog collection item list source');
    }
}
