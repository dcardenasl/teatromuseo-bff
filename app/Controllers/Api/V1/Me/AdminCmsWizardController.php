<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Me;

use App\Controllers\BaseProxyController;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use dcardenasl\Ci4ApiCore\Exceptions\AuthenticationException;
use dcardenasl\Ci4ApiCore\Http\ApiResponse;
use dcardenasl\Ci4ApiCore\Http\ContextHolder;

/** Composite CMS read for the Admin structure wizard. */
final class AdminCmsWizardController extends BaseProxyController
{
    public function bootstrap(): ResponseInterface
    {
        $authContext = ContextHolder::get();
        $bearer = $this->extractBearerToken();
        if ($authContext?->user_id === null || $bearer === null) {
            throw new AuthenticationException('Missing authenticated user context.');
        }

        return $this->handleOperation(function () use ($authContext, $bearer): ResponseInterface {
            return $this->response->setJSON(ApiResponse::success([
                'version' => 1,
                'generated_at' => date(DATE_ATOM),
                'context' => 'wizard-bootstrap',
                'source' => ['cms' => 'ok', 'state' => 'ok'],
                'sections' => Services::adminReadCmsWizard()->bootstrap($authContext->permissions, $bearer),
            ]));
        }, 'CMS wizard bootstrap source');
    }
}
