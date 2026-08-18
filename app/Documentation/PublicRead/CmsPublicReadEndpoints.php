<?php

declare(strict_types=1);

namespace App\Documentation\PublicRead;

use OpenApi\Attributes as OA;

/**
 * Direct, read-only CMS public-read endpoints (`CmsPublicReadController`).
 *
 * Gated by `X-App-Key` (`webappkey` filter), not a bearer token — this is
 * the unauthenticated public surface `teatromuseo-web` calls directly. Every
 * successful response is the canonical `PublicReadEnvelope` shape
 * (`version`, `ok`, `data`, `meta`, `source`, `messages`); a source failure
 * or malformed request never returns HTML or a bare 5xx — it returns the
 * same envelope with `ok: false` and a `messages` entry.
 */
#[OA\Get(
    path: '/api/v1/public-read/{locale}/navigation',
    tags: ['PublicRead'],
    summary: 'Public navigation menus',
    description: 'Main, footer and legal menus for the given locale, with resolved destination URLs and clickability.',
    security: [['appKeyAuth' => []]],
    parameters: [
        new OA\Parameter(name: 'locale', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'es')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Navigation envelope',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'version', type: 'integer', example: 1),
                    new OA\Property(property: 'ok', type: 'boolean'),
                    new OA\Property(property: 'data', type: 'object', properties: [
                        new OA\Property(property: 'main', type: 'object', nullable: true),
                        new OA\Property(property: 'footer', type: 'object', nullable: true),
                        new OA\Property(property: 'legal', type: 'object', nullable: true),
                    ]),
                    new OA\Property(property: 'meta', type: 'object'),
                    new OA\Property(property: 'source', type: 'object', example: ['domain' => 'cms', 'state' => 'fresh', 'stale' => false]),
                ],
                type: 'object',
            ),
        ),
        new OA\Response(response: 401, description: 'Missing or invalid X-App-Key'),
        new OA\Response(response: 503, description: 'CMS read source unavailable'),
    ],
)]
#[OA\Get(
    path: '/api/v1/public-read/{locale}/settings',
    tags: ['PublicRead'],
    summary: 'Public site settings',
    description: 'Identity/social settings marked public, with resolved media URLs.',
    security: [['appKeyAuth' => []]],
    parameters: [
        new OA\Parameter(name: 'locale', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'es')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Settings envelope',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'version', type: 'integer', example: 1),
                new OA\Property(property: 'ok', type: 'boolean'),
                new OA\Property(property: 'data', type: 'object'),
                new OA\Property(property: 'meta', type: 'object'),
                new OA\Property(property: 'source', type: 'object'),
            ], type: 'object'),
        ),
        new OA\Response(response: 401, description: 'Missing or invalid X-App-Key'),
        new OA\Response(response: 503, description: 'CMS read source unavailable'),
    ],
)]
#[OA\Get(
    path: '/api/v1/public/cms/public/languages',
    tags: ['PublicRead'],
    summary: 'Active CMS languages',
    description: 'Locale-agnostic list of active languages with default/fallback flags.',
    security: [['appKeyAuth' => []]],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Language list',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')),
                new OA\Property(property: 'meta', type: 'object', properties: [
                    new OA\Property(property: 'generated_at', type: 'string', format: 'date-time'),
                ]),
            ], type: 'object'),
        ),
        new OA\Response(response: 401, description: 'Missing or invalid X-App-Key'),
        new OA\Response(response: 503, description: 'CMS read source unavailable'),
    ],
)]
#[OA\Get(
    path: '/api/v1/public-read/{locale}/pages',
    tags: ['PublicRead'],
    summary: 'CMS page listing',
    description: 'Bounded, paginated list of published CMS pages for the locale.',
    security: [['appKeyAuth' => []]],
    parameters: [
        new OA\Parameter(name: 'locale', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'es')),
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
        new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 100)),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Page listing envelope',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'version', type: 'integer', example: 1),
                new OA\Property(property: 'ok', type: 'boolean'),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')),
                new OA\Property(property: 'meta', type: 'object'),
                new OA\Property(property: 'source', type: 'object'),
            ], type: 'object'),
        ),
        new OA\Response(response: 401, description: 'Missing or invalid X-App-Key'),
        new OA\Response(response: 422, description: 'Invalid locale, page or per_page'),
        new OA\Response(response: 503, description: 'CMS read source unavailable'),
    ],
)]
#[OA\Get(
    path: '/api/v1/public-read/{locale}/pages/{path}',
    tags: ['PublicRead'],
    summary: 'CMS page detail by path',
    description: 'Resolves one published CMS page by its full slug path (may contain slashes for nested pages). Supports a signed `preview` query for unpublished content.',
    security: [['appKeyAuth' => []]],
    parameters: [
        new OA\Parameter(name: 'locale', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'es')),
        new OA\Parameter(name: 'path', in: 'path', required: true, description: 'Page slug path; may contain additional slash-separated segments', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'fields', in: 'query', description: 'Comma-separated sparse fieldset', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'preview', in: 'query', schema: new OA\Schema(type: 'string', enum: ['1', 'true', 'yes'])),
        new OA\Parameter(name: 'preview_expires', in: 'query', schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'preview_sig', in: 'query', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Page detail envelope',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'version', type: 'integer', example: 1),
                new OA\Property(property: 'ok', type: 'boolean'),
                new OA\Property(property: 'data', type: 'object'),
                new OA\Property(property: 'meta', type: 'object'),
                new OA\Property(property: 'source', type: 'object'),
            ], type: 'object'),
        ),
        new OA\Response(response: 401, description: 'Missing or invalid X-App-Key'),
        new OA\Response(response: 404, description: 'Page not found or unpublished without a valid preview signature'),
        new OA\Response(response: 503, description: 'CMS read source unavailable'),
    ],
)]
#[OA\Get(
    path: '/api/v1/public/{locale}/pages/by-type/{type}',
    tags: ['PublicRead'],
    summary: 'CMS page by page_type',
    description: 'Resolves the singleton CMS page for a given `page_type` (e.g. `template_catalog_item`, `template_event_item`) instead of by slug — used to render Catalog/Event detail pages through CMS-owned templates.',
    security: [['appKeyAuth' => []]],
    parameters: [
        new OA\Parameter(name: 'locale', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'es')),
        new OA\Parameter(name: 'type', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'template_event_item')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Page detail envelope',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'object'),
                new OA\Property(property: 'meta', type: 'object'),
            ], type: 'object'),
        ),
        new OA\Response(response: 401, description: 'Missing or invalid X-App-Key'),
        new OA\Response(response: 404, description: 'No page of that type is published'),
        new OA\Response(response: 503, description: 'CMS read source unavailable'),
    ],
)]
#[OA\Get(
    path: '/api/v1/public-read/{locale}/sitemap',
    tags: ['PublicRead'],
    summary: 'CMS sitemap projection',
    description: 'Bounded, locale-scoped projection of pages, collections and entries for building a public sitemap.',
    security: [['appKeyAuth' => []]],
    parameters: [
        new OA\Parameter(name: 'locale', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'es')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Sitemap envelope',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'pages', type: 'array', items: new OA\Items(type: 'object')),
                    new OA\Property(property: 'collections', type: 'array', items: new OA\Items(type: 'object')),
                    new OA\Property(property: 'entries', type: 'array', items: new OA\Items(type: 'object')),
                ]),
                new OA\Property(property: 'meta', type: 'object'),
            ], type: 'object'),
        ),
        new OA\Response(response: 401, description: 'Missing or invalid X-App-Key'),
        new OA\Response(response: 503, description: 'CMS read source unavailable'),
    ],
)]
#[OA\Get(
    path: '/api/v1/public/{locale}/collections',
    tags: ['PublicRead'],
    summary: 'CMS collection list',
    description: 'Active, locale-scoped list of CMS collections (e.g. `noticias`, `teatroescuela`).',
    security: [['appKeyAuth' => []]],
    parameters: [
        new OA\Parameter(name: 'locale', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'es')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Collection list',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')),
                new OA\Property(property: 'meta', type: 'object'),
            ], type: 'object'),
        ),
        new OA\Response(response: 401, description: 'Missing or invalid X-App-Key'),
        new OA\Response(response: 503, description: 'CMS read source unavailable'),
    ],
)]
#[OA\Get(
    path: '/api/v1/public-read/{locale}/entries/{collection}',
    tags: ['PublicRead'],
    summary: 'CMS entry listing',
    description: 'Bounded, paginated, filterable/orderable listing of published entries within one collection. `order_by`/`filter_by` accept a fixed set of entry columns or a facet field key (`entry.<column>`, `block.<key>.<field>`, or a bare facet key).',
    security: [['appKeyAuth' => []]],
    parameters: [
        new OA\Parameter(name: 'locale', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'es')),
        new OA\Parameter(name: 'collection', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'noticias')),
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
        new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20)),
        new OA\Parameter(name: 'category', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'category_id', in: 'query', schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'tag', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'q', in: 'query', description: 'Free-text search over title/excerpt', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'order_by', in: 'query', schema: new OA\Schema(type: 'string', example: 'published_at')),
        new OA\Parameter(name: 'order_direction', in: 'query', schema: new OA\Schema(type: 'string', enum: ['asc', 'desc', 'upcoming'])),
        new OA\Parameter(name: 'filter_by', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'filter_value', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'filter_operator', in: 'query', schema: new OA\Schema(type: 'string', enum: ['equals', 'contains'])),
        new OA\Parameter(name: 'include', in: 'query', description: 'e.g. `listing_content,listing_content.image`', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'fields', in: 'query', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Entry listing envelope',
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
        new OA\Response(response: 422, description: 'Invalid locale, pagination, order_by or filter_by shape'),
        new OA\Response(response: 503, description: 'CMS read source unavailable'),
    ],
)]
#[OA\Get(
    path: '/api/v1/public-read/{locale}/entries/{collection}/{slug}',
    tags: ['PublicRead'],
    summary: 'CMS entry detail',
    description: 'One published entry by slug, including the complete localized slugs map, taxonomy, blocks and related entries. Supports a signed `preview` query for unpublished content.',
    security: [['appKeyAuth' => []]],
    parameters: [
        new OA\Parameter(name: 'locale', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'es')),
        new OA\Parameter(name: 'collection', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'slug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'fields', in: 'query', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Entry detail envelope',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'object'),
                new OA\Property(property: 'meta', type: 'object'),
            ], type: 'object'),
        ),
        new OA\Response(response: 401, description: 'Missing or invalid X-App-Key'),
        new OA\Response(response: 404, description: 'Entry not found or unpublished without a valid preview signature'),
        new OA\Response(response: 503, description: 'CMS read source unavailable'),
    ],
)]
#[OA\Get(
    path: '/api/v1/public/{locale}/categories/{collectionKey}',
    tags: ['PublicRead'],
    summary: 'CMS categories for a collection',
    security: [['appKeyAuth' => []]],
    parameters: [
        new OA\Parameter(name: 'locale', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'es')),
        new OA\Parameter(name: 'collectionKey', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Category list',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')),
                new OA\Property(property: 'meta', type: 'object'),
            ], type: 'object'),
        ),
        new OA\Response(response: 401, description: 'Missing or invalid X-App-Key'),
        new OA\Response(response: 503, description: 'CMS read source unavailable'),
    ],
)]
#[OA\Get(
    path: '/api/v1/public/{locale}/tags/{collectionKey}',
    tags: ['PublicRead'],
    summary: 'CMS tags for a collection',
    security: [['appKeyAuth' => []]],
    parameters: [
        new OA\Parameter(name: 'locale', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'es')),
        new OA\Parameter(name: 'collectionKey', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Tag list',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')),
                new OA\Property(property: 'meta', type: 'object'),
            ], type: 'object'),
        ),
        new OA\Response(response: 401, description: 'Missing or invalid X-App-Key'),
        new OA\Response(response: 503, description: 'CMS read source unavailable'),
    ],
)]
#[OA\Get(
    path: '/api/v1/public/{locale}/forms/{formKey}',
    tags: ['PublicRead'],
    summary: 'CMS public form definition',
    security: [['appKeyAuth' => []]],
    parameters: [
        new OA\Parameter(name: 'locale', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'es')),
        new OA\Parameter(name: 'formKey', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Form definition',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'object'),
                new OA\Property(property: 'meta', type: 'object'),
            ], type: 'object'),
        ),
        new OA\Response(response: 401, description: 'Missing or invalid X-App-Key'),
        new OA\Response(response: 404, description: 'Form not found or inactive'),
        new OA\Response(response: 503, description: 'CMS read source unavailable'),
    ],
)]
#[OA\Get(
    path: '/api/v1/public/redirects/{path}',
    tags: ['PublicRead'],
    summary: 'Resolve a legacy redirect',
    description: '`path` may contain additional slash-separated segments.',
    security: [['appKeyAuth' => []]],
    parameters: [
        new OA\Parameter(name: 'path', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Redirect target',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'to', type: 'string'),
                    new OA\Property(property: 'status', type: 'integer', example: 301),
                ]),
            ], type: 'object'),
        ),
        new OA\Response(response: 401, description: 'Missing or invalid X-App-Key'),
        new OA\Response(response: 404, description: 'No redirect configured for this path'),
        new OA\Response(response: 503, description: 'CMS read source unavailable'),
    ],
)]
final class CmsPublicReadEndpoints
{
}
