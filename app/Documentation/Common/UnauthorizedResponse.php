<?php

declare(strict_types=1);

namespace App\Documentation\Common;

use OpenApi\Attributes as OA;

/**
 * Unauthorized Response
 *
 * Standard 401 Unauthorized response used by all protected endpoints.
 * Returned when JWT token is missing, invalid, or expired.
 */
#[OA\Response(
    response: 'UnauthorizedResponse',
    description: 'Unauthorized - Invalid or missing authentication token',
    content: new OA\JsonContent(
        properties: [
            new OA\Property(
                property: 'status',
                type: 'string',
                description: 'Response status',
                example: 'error'
            ),
            new OA\Property(
                property: 'message',
                type: 'string',
                description: 'Error message (localized by Accept-Language)',
                example: 'Unauthorized / No autorizado'
            ),
            new OA\Property(
                property: 'errors',
                type: 'object',
                description: 'Error details',
                nullable: true
            ),
        ],
        type: 'object'
    )
)]
class UnauthorizedResponse
{
}
