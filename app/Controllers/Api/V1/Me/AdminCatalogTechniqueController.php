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

/** One-read Catalog technique workspace for authenticated Admin screens. */
final class AdminCatalogTechniqueController extends BaseProxyController
{
    public function index(): ResponseInterface
    {
        return $this->workspace(null);
    }

    public function technique(string $techniqueId): ResponseInterface
    {
        if (! ctype_digit($techniqueId) || (int) $techniqueId < 1) {
            throw new ValidationException('Invalid Catalog technique identifier.', ['techniqueId' => 'Must be a positive integer.']);
        }

        return $this->workspace((int) $techniqueId);
    }

    private function workspace(?int $techniqueId): ResponseInterface
    {
        $authContext = ContextHolder::get();
        if ($authContext?->user_id === null) {
            throw new AuthenticationException('Missing authenticated user context.');
        }

        return $this->handleOperation(function () use ($authContext, $techniqueId): ResponseInterface {
            return $this->response->setJSON(ApiResponse::success([
                'version' => 1,
                'generated_at' => date(DATE_ATOM),
                'context' => 'catalog-technique-workspace',
                'source' => ['catalog' => 'ok', 'cms' => 'ok', 'state' => 'ok'],
                'sections' => Services::adminReadCatalogTechnique()->workspace($techniqueId, $authContext->permissions),
            ]));
        }, 'Catalog technique workspace source');
    }
}
