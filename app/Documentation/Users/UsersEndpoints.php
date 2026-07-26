<?php

declare(strict_types=1);

namespace App\Documentation\Users;

use OpenApi\Attributes as OA;

/**
 * Proxy endpoints for `/api/v1/users` (BFF-103 canonical example).
 *
 * The BFF forwards each call transparently to the hub. Status, body and
 * pagination headers flow back unchanged. The hub enforces RBAC, so this
 * route is gated by whatever permissions the user's JWT carries.
 */
#[OA\Get(
    path: '/api/v1/users/{id}',
    tags: ['Users'],
    summary: 'Fetch a user (proxy)',
    description: 'Forwards `GET /api/v1/users/{id}` to the hub and returns the upstream response unchanged. Authentication is forward-only — the BFF relays the bearer token without decoding it.',
    security: [['bearerAuth' => []]],
    parameters: [
        new OA\Parameter(
            name: 'id',
            in: 'path',
            required: true,
            description: 'Hub user id',
            schema: new OA\Schema(type: 'integer'),
        ),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'User profile from the hub',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', type: 'object'),
                ],
                type: 'object'
            )
        ),
        new OA\Response(response: 401, description: 'Hub rejected the token'),
        new OA\Response(response: 404, description: 'User not found'),
        new OA\Response(response: 503, description: 'Hub unreachable'),
    ]
)]
class UsersEndpoints
{
}
