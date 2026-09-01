<?php

declare(strict_types=1);

namespace App\Documentation\Me;

use OpenApi\Attributes as OA;

/** One-read Catalog collection-item workspace for authenticated Admin screens (create/show/edit). */
#[OA\Get(
    path: '/api/v1/me/admin-catalog/collection-items/workspace',
    tags: ['Me'],
    summary: 'Catalog collection-item workspace (create)',
    description: 'Options bundle for the create form: categories, techniques and languages. No item section.',
    security: [['bearerAuth' => []]],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Workspace sections',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'status', type: 'string', example: 'success'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'version', type: 'integer', example: 1),
                    new OA\Property(property: 'context', type: 'string', example: 'catalog-collection-item-workspace'),
                    new OA\Property(property: 'source', type: 'object', example: ['catalog' => 'ok', 'cms' => 'ok', 'state' => 'ok']),
                    new OA\Property(property: 'sections', type: 'object', properties: [
                        new OA\Property(property: 'collectionItem', type: 'object', nullable: true),
                        new OA\Property(property: 'categories', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'techniques', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'languages', type: 'array', items: new OA\Items(type: 'object')),
                    ]),
                ]),
            ], type: 'object'),
        ),
        new OA\Response(response: 401, description: 'Missing or invalid bearer token'),
        new OA\Response(response: 403, description: 'Missing catalog.collectionItem.read permission'),
        new OA\Response(response: 503, description: 'Catalog collection item workspace source unavailable'),
    ],
)]
#[OA\Get(
    path: '/api/v1/me/admin-catalog/collection-items/{itemId}/workspace',
    tags: ['Me'],
    summary: 'Catalog collection-item workspace (show/edit)',
    description: 'Same options bundle as the create workspace, plus the collection item itself.',
    security: [['bearerAuth' => []]],
    parameters: [
        new OA\Parameter(name: 'itemId', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Workspace sections including the item',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'status', type: 'string', example: 'success'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'version', type: 'integer', example: 1),
                    new OA\Property(property: 'context', type: 'string', example: 'catalog-collection-item-workspace'),
                    new OA\Property(property: 'source', type: 'object'),
                    new OA\Property(property: 'sections', type: 'object', properties: [
                        new OA\Property(property: 'collectionItem', type: 'object'),
                        new OA\Property(property: 'categories', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'techniques', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'languages', type: 'array', items: new OA\Items(type: 'object')),
                    ]),
                ]),
            ], type: 'object'),
        ),
        new OA\Response(response: 401, description: 'Missing or invalid bearer token'),
        new OA\Response(response: 403, description: 'Missing catalog.collectionItem.read permission'),
        new OA\Response(response: 404, description: 'Collection item not found'),
        new OA\Response(response: 422, description: 'itemId is not a positive integer'),
        new OA\Response(response: 503, description: 'Catalog collection item workspace source unavailable'),
    ],
)]
final class AdminCatalogWorkspaceEndpoints
{
}
