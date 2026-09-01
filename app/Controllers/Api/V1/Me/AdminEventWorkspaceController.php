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

/** One-read Event workspace for authenticated Admin screens. */
final class AdminEventWorkspaceController extends BaseProxyController
{
    public function index(): ResponseInterface
    {
        return $this->workspace(null);
    }

    public function event(string $eventId): ResponseInterface
    {
        if (! ctype_digit($eventId) || (int) $eventId < 1) {
            throw new ValidationException('Invalid Event identifier.', ['eventId' => 'Must be a positive integer.']);
        }

        return $this->workspace((int) $eventId);
    }

    private function workspace(?int $eventId): ResponseInterface
    {
        $authContext = ContextHolder::get();
        if ($authContext?->user_id === null) {
            throw new AuthenticationException('Missing authenticated user context.');
        }

        return $this->handleOperation(function () use ($authContext, $eventId): ResponseInterface {
            return $this->response->setJSON(ApiResponse::success([
                'version' => 1,
                'generated_at' => date(DATE_ATOM),
                'context' => 'event-workspace',
                'source' => ['event' => 'ok', 'cms' => 'ok', 'state' => 'ok'],
                'sections' => Services::adminReadEventWorkspace()->workspace(
                    $eventId,
                    $authContext->permissions,
                ),
            ]));
        }, 'Event workspace source');
    }
}
