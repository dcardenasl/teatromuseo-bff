<?php

declare(strict_types=1);

namespace App\Documentation\PublicRead;

use OpenApi\Attributes as OA;

/**
 * Direct, read-only Catalog public-read endpoints (`CatalogPublicReadController`).
 *
 * Gated by `X-App-Key` (`webappkey` filter). Every successful response is the
 * canonical `PublicReadEnvelope` shape; a source failure returns the same
 * envelope with `ok: false`.
 */
#[OA\Get(
    path: '/api/v1/public-read/{locale}/collection-items',
    tags: ['PublicRead'],
    summary: 'Catalog collection-item listing',
    description: 'Bounded, paginated, filterable listing of museum collection items.',
    security: [['appKeyAuth' => []]],
    parameters: [
        new OA\Parameter(name: 'locale', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'es')),
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
        new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20)),
        new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'category', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'category_id', in: 'query', schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'technique', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'technique_id', in: 'query', schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', enum: ['name', 'created_at', 'id'])),
        new OA\Parameter(name: 'fields', in: 'query', description: 'Comma-separated sparse fieldset', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Collection-item listing envelope',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')),
                new OA\Property(property: 'meta', type: 'object', properties: [
                    new OA\Property(property: 'page', type: 'integer'),
                    new OA\Property(property: 'per_page', type: 'integer'),
                    new OA\Property(property: 'total', type: 'integer'),
                ]),
            ], type: 'object'),
        ),
        new OA\Response(response: 401, description: 'Missing or invalid X-App-Key'),
        new OA\Response(response: 422, description: 'Invalid locale, pagination, sort or fields'),
        new OA\Response(response: 503, description: 'Catalog read source unavailable'),
    ],
)]
#[OA\Get(
    path: '/api/v1/public-read/{locale}/collection-items/{idOrSlug}',
    tags: ['PublicRead'],
    summary: 'Catalog collection-item detail',
    description: 'One item by numeric id or slug, including the complete localized slugs map, translations, category and techniques.',
    security: [['appKeyAuth' => []]],
    parameters: [
        new OA\Parameter(name: 'locale', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'es')),
        new OA\Parameter(name: 'idOrSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'fields', in: 'query', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Collection-item detail envelope',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'object'),
                new OA\Property(property: 'meta', type: 'object'),
            ], type: 'object'),
        ),
        new OA\Response(response: 401, description: 'Missing or invalid X-App-Key'),
        new OA\Response(response: 404, description: 'Item not found'),
        new OA\Response(response: 503, description: 'Catalog read source unavailable'),
    ],
)]
#[OA\Get(
    path: '/api/v1/public/catalog/categories',
    tags: ['PublicRead'],
    summary: 'Catalog category facets',
    security: [['appKeyAuth' => []]],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Category facet list',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')),
                new OA\Property(property: 'meta', type: 'object'),
            ], type: 'object'),
        ),
        new OA\Response(response: 401, description: 'Missing or invalid X-App-Key'),
        new OA\Response(response: 503, description: 'Catalog read source unavailable'),
    ],
)]
#[OA\Get(
    path: '/api/v1/public/catalog/techniques',
    tags: ['PublicRead'],
    summary: 'Catalog technique facets',
    security: [['appKeyAuth' => []]],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Technique facet list',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')),
                new OA\Property(property: 'meta', type: 'object'),
            ], type: 'object'),
        ),
        new OA\Response(response: 401, description: 'Missing or invalid X-App-Key'),
        new OA\Response(response: 503, description: 'Catalog read source unavailable'),
    ],
)]
#[OA\Get(
    path: '/api/v1/public/catalog/techniques/{idOrSlug}',
    tags: ['PublicRead'],
    summary: 'Catalog technique detail',
    security: [['appKeyAuth' => []]],
    parameters: [
        new OA\Parameter(name: 'idOrSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Technique detail',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'object'),
                new OA\Property(property: 'meta', type: 'object'),
            ], type: 'object'),
        ),
        new OA\Response(response: 401, description: 'Missing or invalid X-App-Key'),
        new OA\Response(response: 404, description: 'Technique not found'),
        new OA\Response(response: 503, description: 'Catalog read source unavailable'),
    ],
)]
final class CatalogPublicReadEndpoints
{
}
