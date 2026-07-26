<?php

declare(strict_types=1);

namespace App\Documentation\Common;

use OpenApi\Attributes as OA;

/**
 * Authentication Token Schema
 *
 * Schema for authentication responses containing JWT token and user data.
 * Used by login and refresh endpoints.
 */
#[OA\Schema(
    schema: 'AuthToken',
    title: 'Authentication Token',
    description: 'JWT token with associated user data',
    required: ['token', 'user'],
    properties: [
        new OA\Property(
            property: 'token',
            type: 'string',
            description: 'JWT access token',
            example: 'eyJ0eXAiOiJKV1QiLCJhbGc...'
        ),
        new OA\Property(
            property: 'user',
            type: 'object',
            description: 'Authenticated user data'
        ),
    ],
    type: 'object'
)]
class AuthTokenSchema
{
}
