<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Me;

use App\AdminRead\Event\AdminEventLookupSource;
use App\Controllers\BaseProxyController;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use dcardenasl\Ci4ApiCore\Exceptions\AuthenticationException;
use dcardenasl\Ci4ApiCore\Exceptions\ValidationException;
use dcardenasl\Ci4ApiCore\Http\ApiResponse;
use dcardenasl\Ci4ApiCore\Http\ContextHolder;

/** Bounded Event lookup projection consumed by Admin form screens. */
final class AdminEventLookupsController extends BaseProxyController
{
    public function index(string $context): ResponseInterface
    {
        $authContext = ContextHolder::get();
        if ($authContext?->user_id === null) {
            throw new AuthenticationException('Missing authenticated user context.');
        }

        return $this->handleOperation(function () use ($authContext, $context): ResponseInterface {
            if (! in_array($context, AdminEventLookupSource::ALLOWED_CONTEXTS, true)) {
                throw new ValidationException('Unsupported Event lookup context.', [
                    'context' => 'Allowed values: ' . implode(', ', AdminEventLookupSource::ALLOWED_CONTEXTS) . '.',
                ]);
            }

            $sections = Services::adminReadEventLookups()->read($context, $authContext->permissions);

            return $this->response->setJSON(ApiResponse::success([
                'version'      => 1,
                'generated_at' => date(DATE_ATOM),
                'context'      => $context,
                'source'       => [
                    'event' => 'ok',
                    'state' => 'ok',
                ],
                'sections' => $sections,
            ]));
        }, 'Event lookup source');
    }
}
