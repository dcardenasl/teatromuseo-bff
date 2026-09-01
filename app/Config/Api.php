<?php

declare(strict_types=1);

namespace Config;

/**
 * API configuration for the BFF.
 *
 * Inherits every env-driven default (rate limiting, search, pagination, file
 * uploads, monitoring, outbound HTTP, API versions) from
 * `dcardenasl\Ci4ApiCore\Config\Api` — see that class for the full property
 * list and the environment variables that hydrate it. Add overrides here only
 * for tuning that is genuinely specific to this app.
 *
 * NOTE on the inherited JWT properties (`$jwtSecretKey`, `$jwtAccessTokenTtl`,
 * `$jwtRefreshTokenTtl`, `$jwtRevocationCheck`, `$jwtServiceTokenTtl`): the BFF
 * never holds the JWT secret. It forwards the client's `Authorization` header
 * upstream and, on routes that opt into `IntrospectAuthFilter`, asks the hub to
 * introspect the token. Those properties exist because the same base config also
 * serves the hub. Setting `JWT_SECRET_KEY` in this app's environment has no
 * effect; do not do it.
 */
class Api extends \dcardenasl\Ci4ApiCore\Config\Api
{
}
