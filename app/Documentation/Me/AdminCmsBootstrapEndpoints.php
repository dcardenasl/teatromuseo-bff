<?php

declare(strict_types=1);

namespace App\Documentation\Me;

use OpenApi\Attributes as OA;

/** Composite CMS reads for Admin form bootstraps (`AdminCmsBootstrapController`). */
#[OA\Get(
    path: '/api/v1/me/admin-cms/entry-form-options',
    tags: ['Me'],
    summary: 'CMS entry form options (create)',
    description: 'Languages, collections and (if the caller holds the permission) categories/tags needed to render the entry-creation form.',
    security: [['bearerAuth' => []]],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Entry form options',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'status', type: 'string', example: 'success'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'context', type: 'string', example: 'entry-form-options'),
                    new OA\Property(property: 'source', type: 'object', example: ['cms' => 'ok', 'state' => 'ok']),
                    new OA\Property(property: 'sections', type: 'object', properties: [
                        new OA\Property(property: 'languages', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'collections', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'categories', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'tags', type: 'array', items: new OA\Items(type: 'object')),
                    ]),
                ]),
            ], type: 'object'),
        ),
        new OA\Response(response: 401, description: 'Missing or invalid bearer token'),
        new OA\Response(response: 503, description: 'CMS entry-form-options source unavailable'),
    ],
)]
#[OA\Get(
    path: '/api/v1/me/admin-cms/entry-form-options/{entryId}',
    tags: ['Me'],
    summary: 'CMS entry form options (edit)',
    description: 'Same as the create variant, scoped to one existing entry.',
    security: [['bearerAuth' => []]],
    parameters: [
        new OA\Parameter(name: 'entryId', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Entry form options'),
        new OA\Response(response: 401, description: 'Missing or invalid bearer token'),
        new OA\Response(response: 422, description: 'entryId is not a positive integer'),
        new OA\Response(response: 503, description: 'CMS entry-form-options source unavailable'),
    ],
)]
#[OA\Get(
    path: '/api/v1/me/admin-cms/page-form-options',
    tags: ['Me'],
    summary: 'CMS page form options (create)',
    description: 'Languages, pages and collections needed to render the page-creation form.',
    security: [['bearerAuth' => []]],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Page form options',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'status', type: 'string', example: 'success'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'context', type: 'string', example: 'page-form-options'),
                    new OA\Property(property: 'source', type: 'object'),
                    new OA\Property(property: 'sections', type: 'object', properties: [
                        new OA\Property(property: 'languages', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'pages', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'collections', type: 'array', items: new OA\Items(type: 'object')),
                    ]),
                ]),
            ], type: 'object'),
        ),
        new OA\Response(response: 401, description: 'Missing or invalid bearer token'),
        new OA\Response(response: 503, description: 'CMS page-form-options source unavailable'),
    ],
)]
#[OA\Get(
    path: '/api/v1/me/admin-cms/page-form-options/{pageId}',
    tags: ['Me'],
    summary: 'CMS page form options (edit)',
    description: 'Same as the create variant, scoped to one existing page.',
    security: [['bearerAuth' => []]],
    parameters: [
        new OA\Parameter(name: 'pageId', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Page form options'),
        new OA\Response(response: 401, description: 'Missing or invalid bearer token'),
        new OA\Response(response: 422, description: 'pageId is not a positive integer'),
        new OA\Response(response: 503, description: 'CMS page-form-options source unavailable'),
    ],
)]
#[OA\Get(
    path: '/api/v1/me/admin-cms/menus/{menuId}/editor-bootstrap',
    tags: ['Me'],
    summary: 'CMS menu editor bootstrap',
    description: 'Menu, items, languages, pages, entries and collections needed to render the menu editor. Pass `itemId` to also scope a single-item section.',
    security: [['bearerAuth' => []]],
    parameters: [
        new OA\Parameter(name: 'menuId', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Menu editor bootstrap sections',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'status', type: 'string', example: 'success'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'context', type: 'string', example: 'menu-editor-bootstrap'),
                    new OA\Property(property: 'source', type: 'object'),
                    new OA\Property(property: 'sections', type: 'object', properties: [
                        new OA\Property(property: 'menu', type: 'object'),
                        new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'languages', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'pages', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'entries', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'collections', type: 'array', items: new OA\Items(type: 'object')),
                    ]),
                ]),
            ], type: 'object'),
        ),
        new OA\Response(response: 401, description: 'Missing or invalid bearer token'),
        new OA\Response(response: 422, description: 'menuId is not a positive integer'),
        new OA\Response(response: 503, description: 'CMS menu-editor-bootstrap source unavailable'),
    ],
)]
#[OA\Get(
    path: '/api/v1/me/admin-cms/menus/{menuId}/editor-bootstrap/{itemId}',
    tags: ['Me'],
    summary: 'CMS menu editor bootstrap (scoped to one item)',
    description: 'Same as the menu-level bootstrap, plus a `sections.item` entry for the given menu item.',
    security: [['bearerAuth' => []]],
    parameters: [
        new OA\Parameter(name: 'menuId', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'itemId', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Menu editor bootstrap sections including the item'),
        new OA\Response(response: 401, description: 'Missing or invalid bearer token'),
        new OA\Response(response: 422, description: 'menuId or itemId is not a positive integer'),
        new OA\Response(response: 503, description: 'CMS menu-editor-bootstrap source unavailable'),
    ],
)]
#[OA\Get(
    path: '/api/v1/me/admin-cms/site-identity-bootstrap',
    tags: ['Me'],
    summary: 'CMS site identity bootstrap',
    description: 'Identity/social settings and languages needed to render the site identity editor.',
    security: [['bearerAuth' => []]],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Site identity sections',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'status', type: 'string', example: 'success'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'context', type: 'string', example: 'site-identity-bootstrap'),
                    new OA\Property(property: 'source', type: 'object'),
                    new OA\Property(property: 'sections', type: 'object', properties: [
                        new OA\Property(property: 'settings', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'languages', type: 'array', items: new OA\Items(type: 'object')),
                    ]),
                ]),
            ], type: 'object'),
        ),
        new OA\Response(response: 401, description: 'Missing or invalid bearer token'),
        new OA\Response(response: 503, description: 'CMS site-identity-bootstrap source unavailable'),
    ],
)]
final class AdminCmsBootstrapEndpoints
{
}
