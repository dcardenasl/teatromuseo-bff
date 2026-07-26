<?php

declare(strict_types=1);

namespace App\Documentation\Common;

use OpenApi\Attributes as OA;

/**
 * Validation Error Response
 *
 * Standard validation error response used when request data fails validation.
 * Returns field-specific error messages.
 */
#[OA\Response(
    response: 'ValidationErrorResponse',
    description: 'Validation Error - Request data failed validation',
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
                description: 'Main error message (localized by Accept-Language)',
                example: 'Validation failed / Validacion fallida'
            ),
            new OA\Property(
                property: 'errors',
                type: 'object',
                description: 'Field-specific error messages (localized by Accept-Language)',
                example: ['email' => 'Localized validation message']
            ),
            new OA\Property(
                property: 'code',
                type: 'integer',
                description: 'Error code',
                example: 422,
                nullable: true
            ),
        ]
    )
)]
class ValidationErrorResponse
{
}
