<?php

declare(strict_types=1);

/**
 * Exception language strings (English)
 *
 * Default messages for API exceptions.
 */
return [
    // 400 Bad Request
    'badRequest'          => 'Bad request',

    // 401 Unauthorized
    'authenticationFailed' => 'Authentication failed',

    // 403 Forbidden
    'insufficientPermissions' => 'Insufficient permissions',

    // 404 Not Found
    'resourceNotFound'    => 'Resource not found',

    // 409 Conflict
    'conflictState'       => 'Request conflicts with current state',

    // 422 Unprocessable Entity
    'validationFailed'    => 'Validation failed',

    // 429 Too Many Requests
    'tooManyRequests'     => 'Too many requests',

    // 503 Service Unavailable
    'serviceUnavailable'  => 'Service temporarily unavailable',
];
