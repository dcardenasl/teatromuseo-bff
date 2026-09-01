<?php

declare(strict_types=1);

namespace App\Documentation\Me;

use OpenApi\Attributes as OA;

/** OpenAPI contracts for the Admin module read projections. */
#[OA\Get(
    path: '/api/v1/me/admin-cms/categories/bootstrap',
    tags: ['Me'],
    summary: 'CMS category list bootstrap',
    security: [['bearerAuth' => []]],
    responses: [new OA\Response(response: 200, description: 'Bounded category, collection, language and parent projections'), new OA\Response(response: 401, description: 'Missing or invalid bearer token')],
)]
#[OA\Get(
    path: '/api/v1/me/admin-cms/categories/{categoryId}/bootstrap',
    tags: ['Me'],
    summary: 'CMS category workspace bootstrap',
    security: [['bearerAuth' => []]],
    parameters: [new OA\Parameter(name: 'categoryId', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1))],
    responses: [new OA\Response(response: 200, description: 'Bounded category editor projection'), new OA\Response(response: 401, description: 'Missing or invalid bearer token')],
)]
#[OA\Get(
    path: '/api/v1/me/admin-catalog/collection-items/list-bootstrap',
    tags: ['Me'],
    summary: 'Catalog collection-item list bootstrap',
    security: [['bearerAuth' => []]],
    responses: [new OA\Response(response: 200, description: 'Bounded collection-item category filters'), new OA\Response(response: 401, description: 'Missing or invalid bearer token')],
)]
#[OA\Get(
    path: '/api/v1/me/admin-event/events/list-bootstrap',
    tags: ['Me'],
    summary: 'Event list bootstrap',
    security: [['bearerAuth' => []]],
    responses: [new OA\Response(response: 200, description: 'Bounded event type filters'), new OA\Response(response: 401, description: 'Missing or invalid bearer token')],
)]
#[OA\Get(
    path: '/api/v1/me/admin-catalog/techniques/workspace',
    tags: ['Me'],
    summary: 'Catalog technique create workspace',
    security: [['bearerAuth' => []]],
    responses: [new OA\Response(response: 200, description: 'Technique create options and languages'), new OA\Response(response: 401, description: 'Missing or invalid bearer token')],
)]
#[OA\Get(
    path: '/api/v1/me/admin-catalog/techniques/{techniqueId}/workspace',
    tags: ['Me'],
    summary: 'Catalog technique edit workspace',
    security: [['bearerAuth' => []]],
    parameters: [new OA\Parameter(name: 'techniqueId', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1))],
    responses: [new OA\Response(response: 200, description: 'Technique editor options and languages'), new OA\Response(response: 401, description: 'Missing or invalid bearer token')],
)]
final class AdminModuleBootstrapEndpoints
{
}
