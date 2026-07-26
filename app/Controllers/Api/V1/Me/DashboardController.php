<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Me;

use App\Controllers\BaseProxyController;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use dcardenasl\Ci4ApiCore\Exceptions\AuthenticationException;
use dcardenasl\Ci4ApiCore\Http\ContextHolder;

/**
 * Canonical aggregator example: `GET /api/v1/me/dashboard`.
 *
 * Demonstrates the introspect-auth + aggregate pattern:
 *
 *  1. `introspectauth` filter (route-level) validates the bearer token via the
 *     hub and populates {@see ApiRequest::setAuthContext()}.
 *  2. This controller pulls `auth_user_id` + `auth_permissions` from the
 *     request and uses {@see BaseProxyController::aggregate()} to merge data
 *     from multiple sources (here: the hub's user profile + the permission
 *     list carried by the token) into a single response.
 *
 * Real-world aggregators add domain calls, preference fetches, notification
 * counts, etc. Add closures to the array; merge under semantic keys.
 */
class DashboardController extends BaseProxyController
{
    public function index(): ResponseInterface
    {
        // The introspectauth filter populates ContextHolder with the user id
        // and permission scope derived from the introspect response. We read
        // from there (not from `$this->request`) so the controller stays
        // agnostic of whether the framework wraps the request in ApiRequest
        // — CI4's test infrastructure replaces it with a vanilla IncomingRequest.
        $context     = ContextHolder::get();
        $userId      = $context?->user_id;
        $permissions = $context !== null ? $context->permissions : [];
        $bearer      = $this->extractBearerToken();

        if ($userId === null || $bearer === null) {
            throw new AuthenticationException('Missing authenticated user context.');
        }

        $hubClient = Services::hubClient();

        return $this->aggregate([
            'profile'     => static fn () => $hubClient->getUser($userId, $bearer),
            'permissions' => static fn () => ['scope' => $permissions],
        ]);
    }

    private function extractBearerToken(): ?string
    {
        $header = $this->request->getHeaderLine('Authorization');
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return trim($m[1]);
        }

        return null;
    }
}
