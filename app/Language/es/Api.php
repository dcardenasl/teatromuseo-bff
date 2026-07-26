<?php

declare(strict_types=1);

return [
    'requestFailed' => 'La solicitud ha fallado.',
    'validationFailed' => 'La validación ha fallado.',
    'resourceNotFound' => 'Recurso no encontrado.',
    'resourceCreated' => 'Recurso creado correctamente.',
    'resourceUpdated' => 'Recurso actualizado correctamente.',
    'resourceDeleted' => 'Recurso eliminado correctamente.',
    'noFieldsToUpdate' => 'No se proporcionaron campos válidos para actualizar.',
    'jwtSecretTooShort' => 'JWT_SECRET_KEY debe tener al menos 32 caracteres.',
    'unauthorized' => 'Acceso no autorizado.',
    'forbidden' => 'Permisos insuficientes.',
    'authRequired' => 'Se requiere autenticación.',
    'insufficientPermissions' => 'Permisos insuficientes para esta acción.',
    'invalidToken' => 'Token de autenticación inválido o expirado.',
    'hubUnreachable' => 'El servicio de autenticación no está disponible.',
    'serverError' => 'Ha ocurrido un error interno en el servidor.',
    'tooManyRequests' => 'Demasiadas solicitudes.',
    'invalidRequest' => 'Datos de solicitud inválidos.',
    'invalidJsonPayload' => 'Carga JSON malformada.',
    'transactionFailed' => 'La transacción de base de datos ha fallado.',
    'updateError' => 'No se pudo actualizar el recurso.',
    'deleteError' => 'No se pudo eliminar el recurso.',
    'saveFailed' => 'No se pudo guardar el recurso.',
    'responseDtoNotDefined' => 'Clase Response DTO no definida para {0}.',
    'responseDtoMustImplement' => 'La clase {0} debe implementar DataTransferObjectInterface.',
    'fieldRequired' => 'El campo {0} es obligatorio.',
];
