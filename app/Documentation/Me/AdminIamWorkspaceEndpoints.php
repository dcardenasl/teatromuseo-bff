<?php

declare(strict_types=1);

namespace App\Documentation\Me;

use OpenApi\Attributes as OA;

/** Hub-owned IAM role editor projection for authenticated Admin clients. */
#[OA\Get(
    path: '/api/v1/me/admin-iam/roles/{roleId}/workspace',
    tags: ['Me'],
    summary: 'IAM role editor workspace',
    description: 'Role, the complete permission catalog and the ids currently assigned to the role, from the Hub\'s own IAM role workspace endpoint. The BFF only authenticates and normalizes the response; the Hub is the sole owner of this read model.',
    security: [['bearerAuth' => []]],
    parameters: [
        new OA\Parameter(name: 'roleId', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Role workspace',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'status', type: 'string', example: 'success'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'version', type: 'integer', example: 1),
                    new OA\Property(property: 'role', type: 'object'),
                    new OA\Property(property: 'allPermissions', type: 'array', items: new OA\Items(type: 'object')),
                    new OA\Property(property: 'assignedPermissionIds', type: 'array', items: new OA\Items(type: 'integer')),
                ]),
            ], type: 'object'),
        ),
        new OA\Response(response: 401, description: 'Missing or invalid bearer token'),
        new OA\Response(response: 403, description: 'Missing IAM permission on the Hub'),
        new OA\Response(response: 404, description: 'Role not found'),
        new OA\Response(response: 422, description: 'roleId is not a positive integer'),
        new OA\Response(response: 503, description: 'Hub IAM role workspace source unavailable'),
    ],
)]
final class AdminIamWorkspaceEndpoints
{
}
