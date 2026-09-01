<?php

declare(strict_types=1);

namespace App\Documentation\Me;

use OpenApi\Attributes as OA;

/** Complete CMS analytics projection consumed by the Admin. */
#[OA\Get(
    path: '/api/v1/me/admin-analytics',
    tags: ['Me'],
    summary: 'Admin analytics projection',
    description: 'Returns the complete bounded CMS analytics payload in one request. The period is closed to the four periods supported by the CMS analytics contract and the pages/referrers limit is fixed server-side.',
    security: [['bearerAuth' => []]],
    parameters: [
        new OA\Parameter(
            name: 'period',
            in: 'query',
            required: false,
            schema: new OA\Schema(type: 'string', enum: ['1h', '24h', '7d', '30d'], default: '7d'),
        ),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Analytics sections and source state',
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
                                example: ['cms' => 'ok', 'state' => 'ok'],
                            ),
                            new OA\Property(property: 'sections', type: 'object'),
                        ],
                    ),
                ],
                type: 'object',
            ),
        ),
        new OA\Response(response: 401, description: 'Missing or invalid bearer token'),
        new OA\Response(response: 403, description: 'Missing CMS analytics permission'),
        new OA\Response(response: 422, description: 'Unsupported period'),
        new OA\Response(response: 503, description: 'CMS analytics source unavailable'),
    ],
)]
final class AdminAnalyticsEndpoints
{
}
