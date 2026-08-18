<?php

declare(strict_types=1);

namespace App\Documentation\Me;

use OpenApi\Attributes as OA;

/** Hub-owned Metrics summary + timeseries workspace for the Admin. */
#[OA\Get(
    path: '/api/v1/me/admin-metrics/workspace',
    tags: ['Me'],
    summary: 'Metrics workspace',
    description: 'Summary and timeseries for one period, from the Hub\'s own Metrics workspace endpoint. The BFF only authenticates and normalizes the response; the Hub is the sole owner of this read model.',
    security: [['bearerAuth' => []]],
    parameters: [
        new OA\Parameter(name: 'period', in: 'query', schema: new OA\Schema(type: 'string', enum: ['1h', '24h', '7d', '30d'], default: '24h')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Metrics workspace',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'status', type: 'string', example: 'success'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'version', type: 'integer', example: 1),
                    new OA\Property(property: 'period', type: 'string', example: '24h'),
                    new OA\Property(property: 'summary', type: 'object'),
                    new OA\Property(property: 'timeseries', type: 'array', items: new OA\Items(type: 'object')),
                ]),
            ], type: 'object'),
        ),
        new OA\Response(response: 401, description: 'Missing or invalid bearer token'),
        new OA\Response(response: 403, description: 'Missing Metrics permission on the Hub'),
        new OA\Response(response: 422, description: 'Invalid period'),
        new OA\Response(response: 503, description: 'Hub Metrics workspace source unavailable'),
    ],
)]
final class AdminMetricsWorkspaceEndpoints
{
}
