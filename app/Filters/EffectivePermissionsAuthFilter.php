<?php

declare(strict_types=1);

namespace App\Filters;

use Config\Services;
use dcardenasl\Ci4ApiCore\Exceptions\ApiException;
use dcardenasl\Ci4ApiCore\Http\Filters\AbstractJwtAuthFilter;
use stdClass;

/**
 * Authenticates a cross-application BFF projection through the Hub's
 * canonical authenticated-user endpoint.
 *
 * The regular introspection filter is intentionally application-scoped: the
 * Hub resolves permissions for the application represented by its X-App-Key.
 * That is correct for /me/dashboard and domain-specific proxy routes, but not
 * for an Admin projection that reads CMS, Catalog, and Event in one request.
 * This filter keeps the JWT boundary in the Hub and only adapts the Hub's
 * resolveAll() response to the shared request security context.
 */
final class EffectivePermissionsAuthFilter extends AbstractJwtAuthFilter
{
    protected function decodeToken(string $token): ?object
    {
        try {
            $user = Services::hubDashboardClient()->getAuthenticatedUser($token);
        } catch (ApiException) {
            return null;
        } catch (\Throwable $exception) {
            log_message('warning', sprintf(
                'Cross-application auth context unavailable: %s',
                $exception->getMessage(),
            ));

            return null;
        }

        $userId         = $user['id'] ?? null;
        $rawPermissions = $user['permissions'] ?? null;

        if (! is_numeric($userId) || (int) $userId <= 0 || ! is_array($rawPermissions)) {
            return null;
        }

        $permissions = [];
        foreach ($rawPermissions as $permission) {
            if (! is_string($permission)) {
                return null;
            }

            $permission = trim($permission);
            if ($permission === '') {
                return null;
            }

            $permissions[$permission] = true;
        }

        $decoded          = new stdClass();
        $decoded->uid     = (int) $userId;
        $decoded->scope   = array_keys($permissions);
        $decoded->app_id  = null;
        $decoded->jti     = null;

        return $decoded;
    }
}
