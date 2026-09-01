<?php

declare(strict_types=1);

namespace App\Documentation\Health;

use OpenApi\Attributes as OA;

#[OA\Get(
    path: '/health',
    tags: ['System'],
    summary: 'Overall health check',
    description: 'Detailed diagnostic only: aggregates the hub probe, four SELECT-only read-database probes, and local disk/writable checks. Do not poll this endpoint as a liveness or readiness monitor on low-capacity hosting.',
    responses: [
        new OA\Response(
            response: 200,
            description: 'System healthy or degraded',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'status', type: 'string', example: 'healthy'),
                    new OA\Property(property: 'timestamp', type: 'string'),
                        new OA\Property(property: 'checks', type: 'object', properties: [
                            new OA\Property(property: 'hub', type: 'object'),
                            new OA\Property(property: 'databases', type: 'object'),
                            new OA\Property(property: 'disk', type: 'object'),
                        new OA\Property(property: 'writable', type: 'object'),
                    ]),
                ],
                type: 'object'
            )
        ),
        new OA\Response(response: 503, description: 'System unhealthy or monitoring disabled'),
    ]
)]
#[OA\Get(
    path: '/ping',
    tags: ['System'],
    summary: 'Ping',
    description: 'Lightweight availability check. Does not probe upstreams; safe to call as often as needed (excluded from rate-limit).',
    responses: [
        new OA\Response(
            response: 200,
            description: 'Service reachable',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'status', type: 'string', example: 'ok'),
                    new OA\Property(property: 'timestamp', type: 'string'),
                ],
                type: 'object'
            )
        ),
    ]
)]
#[OA\Get(
    path: '/ready',
    tags: ['System'],
    summary: 'Readiness check',
    description: 'Returns 200 when the BFF can reach the upstream hub (`GET {hubUrl}/ping`). The BFF has no database, so readiness is a function of hub reachability only.',
    responses: [
        new OA\Response(
            response: 200,
            description: 'Ready to serve traffic',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'status', type: 'string', example: 'ready'),
                    new OA\Property(property: 'timestamp', type: 'string'),
                    new OA\Property(property: 'hub', type: 'object', properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'healthy'),
                        new OA\Property(property: 'response_time_ms', type: 'number'),
                        new OA\Property(property: 'hub_url', type: 'string'),
                        new OA\Property(property: 'hub_status_code', type: 'integer'),
                    ]),
                ],
                type: 'object'
            )
        ),
        new OA\Response(response: 503, description: 'Hub unreachable or returned non-2xx'),
    ]
)]
#[OA\Get(
    path: '/live',
    tags: ['System'],
    summary: 'Liveness check',
    description: 'Returns alive + uptime regardless of upstream state. Use to detect a stuck process; not a substitute for readiness.',
    responses: [
        new OA\Response(
            response: 200,
            description: 'Service alive',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'status', type: 'string', example: 'alive'),
                    new OA\Property(property: 'timestamp', type: 'string'),
                    new OA\Property(property: 'uptime_seconds', type: 'integer', example: 12345),
                ],
                type: 'object'
            )
        ),
    ]
)]
/*
 * The same four probes are also mounted under /api/v1 (see app/Config/Routes.php:
 * "clients using the versioned API client can use the health contract without
 * a special transport path") — one route definition, two URL surfaces. Both
 * are documented so the OpenAPI spec matches every registered path.
 */
#[OA\Get(
    path: '/api/v1/health',
    tags: ['System'],
    summary: 'Overall health check (versioned)',
    description: 'Same contract as `GET /health`, mounted under `/api/v1` for clients that only use the versioned transport.',
    responses: [
        new OA\Response(
            response: 200,
            description: 'System healthy or degraded',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'status', type: 'string', example: 'healthy'),
                    new OA\Property(property: 'timestamp', type: 'string'),
                    new OA\Property(property: 'checks', type: 'object', properties: [
                        new OA\Property(property: 'hub', type: 'object'),
                        new OA\Property(property: 'disk', type: 'object'),
                        new OA\Property(property: 'writable', type: 'object'),
                    ]),
                ],
                type: 'object'
            )
        ),
        new OA\Response(response: 503, description: 'System unhealthy or monitoring disabled'),
    ]
)]
#[OA\Get(
    path: '/api/v1/ping',
    tags: ['System'],
    summary: 'Ping (versioned)',
    description: 'Same contract as `GET /ping`, mounted under `/api/v1` for clients that only use the versioned transport.',
    responses: [
        new OA\Response(
            response: 200,
            description: 'Service reachable',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'status', type: 'string', example: 'ok'),
                    new OA\Property(property: 'timestamp', type: 'string'),
                ],
                type: 'object'
            )
        ),
    ]
)]
#[OA\Get(
    path: '/api/v1/ready',
    tags: ['System'],
    summary: 'Readiness check (versioned)',
    description: 'Same contract as `GET /ready`, mounted under `/api/v1` for clients that only use the versioned transport.',
    responses: [
        new OA\Response(
            response: 200,
            description: 'Ready to serve traffic',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'status', type: 'string', example: 'ready'),
                    new OA\Property(property: 'timestamp', type: 'string'),
                    new OA\Property(property: 'hub', type: 'object', properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'healthy'),
                        new OA\Property(property: 'response_time_ms', type: 'number'),
                        new OA\Property(property: 'hub_url', type: 'string'),
                        new OA\Property(property: 'hub_status_code', type: 'integer'),
                    ]),
                ],
                type: 'object'
            )
        ),
        new OA\Response(response: 503, description: 'Hub unreachable or returned non-2xx'),
    ]
)]
#[OA\Get(
    path: '/api/v1/live',
    tags: ['System'],
    summary: 'Liveness check (versioned)',
    description: 'Same contract as `GET /live`, mounted under `/api/v1` for clients that only use the versioned transport.',
    responses: [
        new OA\Response(
            response: 200,
            description: 'Service alive',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'status', type: 'string', example: 'alive'),
                    new OA\Property(property: 'timestamp', type: 'string'),
                    new OA\Property(property: 'uptime_seconds', type: 'integer', example: 12345),
                ],
                type: 'object'
            )
        ),
    ]
)]
class HealthEndpoints
{
}
