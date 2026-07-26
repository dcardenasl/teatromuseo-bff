<?php

declare(strict_types=1);

namespace App\Filters;

use Config\Services;
use dcardenasl\Ci4ApiCore\Http\Client\IntrospectResult;
use dcardenasl\Ci4ApiCore\Http\Filters\AbstractIntrospectionFilter;

/**
 * Opt-in JWT auth filter for aggregator endpoints that need the user context.
 *
 * The BFF is forward-only by default — most routes never crack the token.
 * Routes that *need* `auth_user_id` / `auth_permissions` (e.g. dashboards that
 * fan out to several services and tag each result with the user) opt in by
 * attaching this filter:
 *
 *     $routes->get('me/dashboard', 'Me\DashboardController::index', [
 *         'filter' => 'introspectauth',
 *     ]);
 *
 * Token validation is delegated to the hub via {@see \App\Libraries\Hub\HubClient::introspect()},
 * which caches positive results. The BFF therefore never holds the JWT secret
 * and remains stateless.
 */
class IntrospectAuthFilter extends AbstractIntrospectionFilter
{
    protected function introspect(string $token): IntrospectResult
    {
        return Services::hubClient()->introspect($token);
    }
}
