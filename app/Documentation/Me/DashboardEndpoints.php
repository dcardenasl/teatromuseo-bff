<?php

declare(strict_types=1);

namespace App\Documentation\Me;

use OpenApi\Attributes as OA;

/**
 * Aggregator endpoints scoped to the authenticated user (BFF-106 example).
 *
 * Unlike a passthrough proxy, these routes opt in to the `introspectauth`
 * filter: the BFF validates the bearer token via the hub's introspect
 * endpoint, populates the request context with the user id + permissions,
 * and then fans out N upstream calls — bundling the responses into a single
 * `ApiResponse::success({...})` envelope.
 */
#[OA\Get(
    path: '/api/v1/me/dashboard',
    tags: ['Me'],
    summary: 'Authenticated user dashboard (aggregator)',
    description: 'Combines `data.profile` (hub `/api/v1/users/{uid}`) with `data.permissions.scope` (token claim) into a single response. The aggregator demonstrates the introspect-auth + fan-out pattern; replace the closures with the calls your dashboard actually needs.',
    security: [['bearerAuth' => []]],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Aggregated dashboard payload',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'status', type: 'string', example: 'success'),
                    new OA\Property(
                        property: 'data',
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'profile', type: 'object'),
                            new OA\Property(
                                property: 'permissions',
                                type: 'object',
                                properties: [
                                    new OA\Property(
                                        property: 'scope',
                                        type: 'array',
                                        items: new OA\Items(type: 'string'),
                                    ),
                                ],
                            ),
                        ],
                    ),
                ],
                type: 'object'
            )
        ),
        new OA\Response(response: 401, description: 'Missing or invalid bearer token'),
        new OA\Response(response: 503, description: 'Hub unreachable after retry'),
    ]
)]
class DashboardEndpoints
{
}
