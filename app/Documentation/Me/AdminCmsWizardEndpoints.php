<?php

declare(strict_types=1);

namespace App\Documentation\Me;

use OpenApi\Attributes as OA;

/** Composite CMS read for the Admin structure wizard. */
#[OA\Get(
    path: '/api/v1/me/admin-cms/wizard-bootstrap',
    tags: ['Me'],
    summary: 'CMS structure wizard bootstrap',
    description: 'Wizard configuration and its derived block-type map, resolved via the CMS Domain\'s own compound `/cms/wizard/config` endpoint (one HTTP round trip, no second call for block types).',
    security: [['bearerAuth' => []]],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Wizard bootstrap sections',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'status', type: 'string', example: 'success'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'version', type: 'integer', example: 1),
                    new OA\Property(property: 'context', type: 'string', example: 'wizard-bootstrap'),
                    new OA\Property(property: 'source', type: 'object', example: ['cms' => 'ok', 'state' => 'ok']),
                    new OA\Property(property: 'sections', type: 'object', properties: [
                        new OA\Property(property: 'config', type: 'object'),
                        new OA\Property(property: 'blockTypes', type: 'array', items: new OA\Items(type: 'object')),
                    ]),
                ]),
            ], type: 'object'),
        ),
        new OA\Response(response: 401, description: 'Missing or invalid bearer token'),
        new OA\Response(response: 403, description: 'Missing cms.entries.read permission'),
        new OA\Response(response: 503, description: 'CMS wizard bootstrap source unavailable'),
    ],
)]
final class AdminCmsWizardEndpoints
{
}
