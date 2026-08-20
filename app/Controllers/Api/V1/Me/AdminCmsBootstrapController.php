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

/** Composite CMS reads for Admin form bootstraps. */
final class AdminCmsBootstrapController extends BaseProxyController
{
    public function entryFormOptions(?string $entryId = null): ResponseInterface
    {
        $id = $this->optionalPositiveId($entryId, 'entryId');

        return $this->respond('entry-form-options', static function (array $permissions) use ($id): array {
            return Services::adminReadCmsBootstrap()->entryFormOptions($id, $permissions);
        });
    }

    public function pageFormOptions(?string $pageId = null): ResponseInterface
    {
        $id = $this->optionalPositiveId($pageId, 'pageId');

        return $this->respond('page-form-options', static function (array $permissions) use ($id): array {
            return Services::adminReadCmsBootstrap()->pageFormOptions($id, $permissions);
        });
    }

    public function menuEditorBootstrap(string $menuId, ?string $itemId = null): ResponseInterface
    {
        $menu = $this->requiredPositiveId($menuId, 'menuId');
        $item = $this->optionalPositiveId($itemId, 'itemId');

        return $this->respond('menu-editor-bootstrap', static function (array $permissions) use ($menu, $item): array {
            return Services::adminReadCmsBootstrap()->menuEditorBootstrap($menu, $item, $permissions);
        });
    }

    public function siteIdentityBootstrap(): ResponseInterface
    {
        return $this->respond('site-identity-bootstrap', static function (array $permissions): array {
            return Services::adminReadCmsBootstrap()->siteIdentityBootstrap($permissions);
        });
    }

    /** @param callable(list<string>): array<string, mixed> $reader */
    private function respond(string $context, callable $reader): ResponseInterface
    {
        $authContext = ContextHolder::get();
        if ($authContext?->user_id === null) {
            throw new AuthenticationException('Missing authenticated user context.');
        }

        return $this->handleOperation(function () use ($authContext, $reader, $context): ResponseInterface {
            return $this->response->setJSON(ApiResponse::success([
                'version'      => 1,
                'generated_at' => date(DATE_ATOM),
                'context'      => $context,
                'source'       => ['cms' => 'ok', 'state' => 'ok'],
                'sections'     => $reader($authContext->permissions),
            ]));
        }, 'CMS ' . $context . ' source');
    }

    private function requiredPositiveId(string $value, string $field): int
    {
        $id = $this->optionalPositiveId($value, $field);
        if ($id === null) {
            throw new ValidationException('Invalid CMS identifier.', [$field => 'Must be a positive integer.']);
        }

        return $id;
    }

    private function optionalPositiveId(?string $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! ctype_digit($value) || (int) $value < 1) {
            throw new ValidationException('Invalid CMS identifier.', [$field => 'Must be a positive integer.']);
        }

        return (int) $value;
    }
}
