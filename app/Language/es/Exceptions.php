<?php

declare(strict_types=1);

/**
 * Cadenas de excepciones (Español)
 *
 * Mensajes predeterminados para las excepciones de la API.
 */
return [
    // 400 Bad Request
    'badRequest'          => 'Solicitud incorrecta',

    // 401 Unauthorized
    'authenticationFailed' => 'Error de autenticación',

    // 403 Forbidden
    'insufficientPermissions' => 'Permisos insuficientes',

    // 404 Not Found
    'resourceNotFound'    => 'Recurso no encontrado',

    // 409 Conflict
    'conflictState'       => 'La solicitud entra en conflicto con el estado actual',

    // 422 Unprocessable Entity
    'validationFailed'    => 'Error de validación',

    // 429 Too Many Requests
    'tooManyRequests'     => 'Demasiadas solicitudes',

    // 503 Service Unavailable
    'serviceUnavailable'  => 'Servicio temporalmente no disponible',
];
