<?php

declare(strict_types=1);

namespace App\Documentation\PublicRead;

use OpenApi\Attributes as OA;

/**
 * Full public page delivery in one request (`PageResolutionController`).
 *
 * Composes routing, layout and per-block context into a single response so
 * `teatromuseo-web` makes one HTTP call per page instead of up to two. Not
 * the `PublicReadEnvelope` shape — see `docs/adr/004-public-read-page-delivery-contracts.md`
 * for the full `page-resolve` contract.
 */
#[OA\Get(
    path: '/api/v1/public-read/{locale}/page-resolve/{route}',
    tags: ['PublicRead'],
    summary: 'Resolve a full public page',
    description: 'Resolves a redirect, CMS page, collection entry or collection fallback index for the given route, composed with layout (menus, settings) and block context. `route` may contain additional slash-separated segments. HTTP status mirrors the outcome: 200 for a resolved page, 301/302 for a redirect, 404 for not_found.',
    security: [['appKeyAuth' => []]],
    parameters: [
        new OA\Parameter(name: 'locale', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'es')),
        new OA\Parameter(name: 'route', in: 'path', required: true, description: 'Route path; may contain additional slash-separated segments', schema: new OA\Schema(type: 'string', example: 'cartelera')),
        new OA\Parameter(name: 'preview', in: 'query', schema: new OA\Schema(type: 'string', enum: ['1', 'true', 'yes'])),
        new OA\Parameter(name: 'preview_expires', in: 'query', schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'preview_sig', in: 'query', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Resolved page: routing outcome, layout and block context in one envelope',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'outcome', type: 'string', enum: ['page', 'redirect', 'not_found'], example: 'page'),
                    new OA\Property(property: 'redirect', type: 'object', nullable: true),
                    new OA\Property(property: 'page', type: 'object', nullable: true),
                    new OA\Property(property: 'layout', type: 'object', properties: [
                        new OA\Property(property: 'mainMenu', type: 'object'),
                        new OA\Property(property: 'footerMenu', type: 'object'),
                        new OA\Property(property: 'legalMenu', type: 'object'),
                        new OA\Property(property: 'settings', type: 'object'),
                        new OA\Property(property: 'socialLinks', type: 'array', items: new OA\Items(type: 'object')),
                    ]),
                    new OA\Property(property: 'block_context', type: 'object'),
                    new OA\Property(property: 'meta', type: 'object', properties: [
                        new OA\Property(property: 'version', type: 'integer', example: 1),
                        new OA\Property(property: 'locale', type: 'string'),
                        new OA\Property(property: 'route', type: 'string'),
                        new OA\Property(property: 'generated_at', type: 'string', format: 'date-time'),
                    ]),
                    new OA\Property(property: 'source', type: 'object', example: ['domain' => 'bff', 'state' => 'fresh', 'stale' => false]),
                    new OA\Property(property: 'messages', type: 'array', items: new OA\Items(type: 'string')),
                ],
                type: 'object',
            ),
        ),
        new OA\Response(response: 301, description: 'Permanent redirect (outcome: redirect)'),
        new OA\Response(response: 302, description: 'Temporary redirect (outcome: redirect)'),
        new OA\Response(response: 401, description: 'Missing or invalid X-App-Key'),
        new OA\Response(response: 404, description: 'No page, redirect or entry resolves this route (outcome: not_found)'),
        new OA\Response(response: 503, description: 'A required read source is unavailable'),
    ],
)]
final class PageResolutionEndpoints
{
}
