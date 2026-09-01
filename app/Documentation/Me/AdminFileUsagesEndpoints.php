<?php

declare(strict_types=1);

namespace App\Documentation\Me;

use OpenApi\Attributes as OA;

/** Cross-domain, complete-or-incomplete file usage projection. */
#[OA\Get(
    path: '/api/v1/me/admin-files/{fileId}/usages',
    tags: ['Me'],
    summary: 'Admin file usages',
    description: 'Combines the authenticated Hub usage list with the CMS registry. Rows are deduplicated by source, resource, resource id and role; the CMS block context is preferred. `complete` is false whenever either source is unavailable, so the result must not be used as proof that a file is safe to delete.',
    security: [['bearerAuth' => []]],
    parameters: [
        new OA\Parameter(
            name: 'fileId',
            in: 'path',
            required: true,
            schema: new OA\Schema(type: 'integer', minimum: 1),
        ),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Usage rows and per-source completeness state',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'status', type: 'string', example: 'success'),
                    new OA\Property(
                        property: 'data',
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'version', type: 'integer', example: 1),
                            new OA\Property(property: 'generated_at', type: 'string', format: 'date-time'),
                            new OA\Property(property: 'complete', type: 'boolean', example: true),
                            new OA\Property(property: 'source', type: 'object', example: ['hub' => 'ok', 'cms' => 'ok', 'state' => 'ok']),
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
final class AdminFileUsagesEndpoints
{
}
