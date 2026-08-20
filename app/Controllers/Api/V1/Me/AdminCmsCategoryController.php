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

/** One-read CMS category projection for authenticated Admin screens. */
final class AdminCmsCategoryController extends BaseProxyController
{
    public function index(): ResponseInterface
    {
        return $this->bootstrap(null);
    }

    public function category(string $categoryId): ResponseInterface
    {
        if (! ctype_digit($categoryId) || (int) $categoryId < 1) {
            throw new ValidationException('Invalid CMS category identifier.', ['categoryId' => 'Must be a positive integer.']);
        }

        return $this->bootstrap((int) $categoryId);
    }

    private function bootstrap(?int $categoryId): ResponseInterface
    {
        $authContext = ContextHolder::get();
        if ($authContext?->user_id === null) {
            throw new AuthenticationException('Missing authenticated user context.');
        }

        return $this->handleOperation(function () use ($authContext, $categoryId): ResponseInterface {
            return $this->response->setJSON(ApiResponse::success([
                'version' => 1,
                'generated_at' => date(DATE_ATOM),
                'context' => 'cms-category-bootstrap',
                'source' => ['cms' => 'ok', 'state' => 'ok'],
                'sections' => Services::adminReadCmsCategory()->bootstrap($categoryId, $authContext->permissions),
            ]));
        }, 'CMS category bootstrap source');
    }
}
