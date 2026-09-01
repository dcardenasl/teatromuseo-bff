<?php

declare(strict_types=1);

namespace App\Documentation\Me;

use OpenApi\Attributes as OA;

/** Event lookup projections consumed by Admin forms. */
#[OA\Get(
    path: '/api/v1/me/admin-event-lookups/{context}',
    tags: ['Me'],
    summary: 'Admin Event lookup projection',
    description: 'Returns one bounded, permission-aware lookup bundle for an Admin Event form context. Context is closed to occurrence, ticket_type, ticket, booking, and event_reference.',
    security: [['bearerAuth' => []]],
    parameters: [
        new OA\Parameter(
            name: 'context',
            in: 'path',
            required: true,
            schema: new OA\Schema(type: 'string', enum: ['occurrence', 'ticket_type', 'ticket', 'booking', 'event_reference']),
        ),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Event lookup sections',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'status', type: 'string', example: 'success'),
                    new OA\Property(
                        property: 'data',
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'version', type: 'integer', example: 1),
                            new OA\Property(property: 'context', type: 'string', example: 'occurrence'),
                            new OA\Property(property: 'source', type: 'object', example: ['event' => 'ok', 'state' => 'ok']),
                            new OA\Property(property: 'sections', type: 'object'),
                        ],
                    ),
                ],
                type: 'object',
            ),
        ),
        new OA\Response(response: 401, description: 'Missing or invalid bearer token'),
        new OA\Response(response: 403, description: 'Missing Event permission'),
        new OA\Response(response: 422, description: 'Unsupported lookup context'),
        new OA\Response(response: 503, description: 'Event lookup source unavailable'),
    ],
)]
final class AdminEventLookupsEndpoints
{
}
