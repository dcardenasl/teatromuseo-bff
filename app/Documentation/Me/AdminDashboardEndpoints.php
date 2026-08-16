<?php

declare(strict_types=1);

namespace App\Documentation\Me;

use OpenApi\Attributes as OA;

/**
 * Real Admin dashboard aggregator. Unlike `/me/dashboard`, this endpoint
 * isolates failures from the four independent summary sources.
 */
#[OA\Get(
    path: '/api/v1/me/admin-dashboard',
    tags: ['Me'],
    summary: 'Admin dashboard with partial source degradation',
    description: 'Introspects the visitor token and combines Hub, CMS, Catalog and Event dashboard summaries. Each source is reported as `ok` or `unavailable`; one failed source does not fail the complete response.',
    security: [['bearerAuth' => []]],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Dashboard sections and per-source availability',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'status', type: 'string', example: 'success'),
                    new OA\Property(
                        property: 'data',
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'version', type: 'integer', example: 1),
                            new OA\Property(property: 'generated_at', type: 'string', format: 'date-time'),
                            new OA\Property(
                                property: 'source',
                                type: 'object',
                                additionalProperties: new OA\AdditionalProperties(type: 'string'),
                                example: [
                                    'hub' => 'ok',
                                    'cms' => 'ok',
                                    'catalog' => 'unavailable',
                                    'event' => 'ok',
                                    'state' => 'partial',
                                ],
                            ),
                            new OA\Property(property: 'sections', type: 'object'),
                        ],
                    ),
                ],
                type: 'object',
            ),
        ),
        new OA\Response(response: 401, description: 'Missing or invalid bearer token'),
    ],
)]
final class AdminDashboardEndpoints
{
}
