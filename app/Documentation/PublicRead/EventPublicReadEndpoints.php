<?php

declare(strict_types=1);

namespace App\Documentation\PublicRead;

use OpenApi\Attributes as OA;

/**
 * Direct, read-only Event public-read endpoints (`EventPublicReadController`).
 *
 * Gated by `X-App-Key` (`webappkey` filter). Every successful response is the
 * canonical `PublicReadEnvelope` shape; a source failure returns the same
 * envelope with `ok: false`.
 */
#[OA\Get(
    path: '/api/v1/public-read/{locale}/events',
    tags: ['PublicRead'],
    summary: 'Event listing',
    description: 'Bounded, paginated listing of public events/occurrences. `sort=agenda` (the default) orders upcoming occurrences first, then past ones descending.',
    security: [['appKeyAuth' => []]],
    parameters: [
        new OA\Parameter(name: 'locale', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'es')),
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
        new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20)),
        new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'event_type', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date-time')),
        new OA\Parameter(name: 'to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date-time')),
        new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', enum: ['agenda', 'latest', 'id', 'title'])),
        new OA\Parameter(name: 'fields', in: 'query', description: 'Comma-separated sparse fieldset', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Event listing envelope',
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
        new OA\Response(response: 422, description: 'Invalid locale, pagination, date range or sort'),
        new OA\Response(response: 503, description: 'Event read source unavailable'),
    ],
)]
#[OA\Get(
    path: '/api/v1/public-read/{locale}/events/{idOrSlug}',
    tags: ['PublicRead'],
    summary: 'Event detail',
    description: 'One event by numeric id or slug, including the complete localized slugs map, translations, occurrences and venues.',
    security: [['appKeyAuth' => []]],
    parameters: [
        new OA\Parameter(name: 'locale', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'es')),
        new OA\Parameter(name: 'idOrSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'fields', in: 'query', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Event detail envelope',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'object'),
                new OA\Property(property: 'meta', type: 'object'),
            ], type: 'object'),
        ),
        new OA\Response(response: 401, description: 'Missing or invalid X-App-Key'),
        new OA\Response(response: 404, description: 'Event not found'),
        new OA\Response(response: 503, description: 'Event read source unavailable'),
    ],
)]
#[OA\Get(
    path: '/api/v1/public/events/types',
    tags: ['PublicRead'],
    summary: 'Active event types',
    security: [['appKeyAuth' => []]],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Event type list',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')),
                new OA\Property(property: 'meta', type: 'object'),
            ], type: 'object'),
        ),
        new OA\Response(response: 401, description: 'Missing or invalid X-App-Key'),
        new OA\Response(response: 503, description: 'Event read source unavailable'),
    ],
)]
final class EventPublicReadEndpoints
{
}
