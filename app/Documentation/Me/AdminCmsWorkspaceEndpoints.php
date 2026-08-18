<?php

declare(strict_types=1);

namespace App\Documentation\Me;

use OpenApi\Attributes as OA;

/** One-read CMS page/entry editor workspace for authenticated Admin screens. */
#[OA\Get(
    path: '/api/v1/me/admin-cms/pages/{pageId}/workspace',
    tags: ['Me'],
    summary: 'CMS page workspace',
    description: 'Complete editor projection for one CMS page: translations, blocks, block types, taxonomy and translation status in one read. Optional `instance_id` scopes the block-form-options section to one block instance.',
    security: [['bearerAuth' => []]],
    parameters: [
        new OA\Parameter(name: 'pageId', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'instance_id', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Page workspace sections',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'status', type: 'string', example: 'success'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'version', type: 'integer', example: 1),
                    new OA\Property(property: 'context', type: 'string', example: 'page-workspace'),
                    new OA\Property(property: 'source', type: 'object', example: ['cms' => 'ok', 'hub' => 'ok', 'state' => 'ok']),
                    new OA\Property(property: 'sections', type: 'object'),
                ]),
            ], type: 'object'),
        ),
        new OA\Response(response: 401, description: 'Missing or invalid bearer token'),
        new OA\Response(response: 404, description: 'Page not found'),
        new OA\Response(response: 422, description: 'pageId or instance_id is not a positive integer'),
        new OA\Response(response: 503, description: 'CMS page workspace source unavailable'),
    ],
)]
#[OA\Get(
    path: '/api/v1/me/admin-cms/entries/{entryId}/workspace',
    tags: ['Me'],
    summary: 'CMS entry workspace',
    description: 'Complete editor projection for one CMS entry: translations, blocks, block types, taxonomy and translation status in one read. Optional `instance_id` scopes the block-form-options section to one block instance.',
    security: [['bearerAuth' => []]],
    parameters: [
        new OA\Parameter(name: 'entryId', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'instance_id', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Entry workspace sections',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'status', type: 'string', example: 'success'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'version', type: 'integer', example: 1),
                    new OA\Property(property: 'context', type: 'string', example: 'entry-workspace'),
                    new OA\Property(property: 'source', type: 'object'),
                    new OA\Property(property: 'sections', type: 'object'),
                ]),
            ], type: 'object'),
        ),
        new OA\Response(response: 401, description: 'Missing or invalid bearer token'),
        new OA\Response(response: 404, description: 'Entry not found'),
        new OA\Response(response: 422, description: 'entryId or instance_id is not a positive integer'),
        new OA\Response(response: 503, description: 'CMS entry workspace source unavailable'),
    ],
)]
final class AdminCmsWorkspaceEndpoints
{
}
