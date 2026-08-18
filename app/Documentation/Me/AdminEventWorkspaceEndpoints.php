<?php

declare(strict_types=1);

namespace App\Documentation\Me;

use OpenApi\Attributes as OA;

/** One-read Event workspace for authenticated Admin screens (create/show/edit). */
#[OA\Get(
    path: '/api/v1/me/admin-event/events/workspace',
    tags: ['Me'],
    summary: 'Event workspace (create)',
    description: 'Options bundle for the create form: event types and languages. No event section.',
    security: [['bearerAuth' => []]],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Workspace sections',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'status', type: 'string', example: 'success'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'version', type: 'integer', example: 1),
                    new OA\Property(property: 'context', type: 'string', example: 'event-workspace'),
                    new OA\Property(property: 'source', type: 'object', example: ['event' => 'ok', 'cms' => 'ok', 'state' => 'ok']),
                    new OA\Property(property: 'sections', type: 'object', properties: [
                        new OA\Property(property: 'event', type: 'object', nullable: true),
                        new OA\Property(property: 'eventTypes', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'languages', type: 'array', items: new OA\Items(type: 'object')),
                    ]),
                ]),
            ], type: 'object'),
        ),
        new OA\Response(response: 401, description: 'Missing or invalid bearer token'),
        new OA\Response(response: 403, description: 'Missing event.events.read permission'),
        new OA\Response(response: 503, description: 'Event workspace source unavailable'),
    ],
)]
#[OA\Get(
    path: '/api/v1/me/admin-event/events/{eventId}/workspace',
    tags: ['Me'],
    summary: 'Event workspace (show/edit)',
    description: 'Same options bundle as the create workspace, plus the event itself.',
    security: [['bearerAuth' => []]],
    parameters: [
        new OA\Parameter(name: 'eventId', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Workspace sections including the event',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'status', type: 'string', example: 'success'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'version', type: 'integer', example: 1),
                    new OA\Property(property: 'context', type: 'string', example: 'event-workspace'),
                    new OA\Property(property: 'source', type: 'object'),
                    new OA\Property(property: 'sections', type: 'object', properties: [
                        new OA\Property(property: 'event', type: 'object'),
                        new OA\Property(property: 'eventTypes', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'languages', type: 'array', items: new OA\Items(type: 'object')),
                    ]),
                ]),
            ], type: 'object'),
        ),
        new OA\Response(response: 401, description: 'Missing or invalid bearer token'),
        new OA\Response(response: 403, description: 'Missing event.events.read permission'),
        new OA\Response(response: 404, description: 'Event not found'),
        new OA\Response(response: 422, description: 'eventId is not a positive integer'),
        new OA\Response(response: 503, description: 'Event workspace source unavailable'),
    ],
)]
final class AdminEventWorkspaceEndpoints
{
}
