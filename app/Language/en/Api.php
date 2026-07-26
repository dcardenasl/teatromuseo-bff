<?php

declare(strict_types=1);

return [
    'requestFailed' => 'Request failed.',
    'validationFailed' => 'Validation failed.',
    'resourceNotFound' => 'Resource not found.',
    'resourceCreated' => 'Resource created successfully.',
    'resourceUpdated' => 'Resource updated successfully.',
    'resourceDeleted' => 'Resource deleted successfully.',
    'noFieldsToUpdate' => 'No valid fields provided for update.',
    'jwtSecretTooShort' => 'JWT_SECRET_KEY must be at least 32 characters long.',
    'unauthorized' => 'Unauthorized access.',
    'forbidden' => 'Insufficient permissions.',
    'authRequired' => 'Authentication required.',
    'insufficientPermissions' => 'Insufficient permissions for this action.',
    'invalidToken' => 'Invalid or expired authentication token.',
    'hubUnreachable' => 'Authentication service is unavailable.',
    'serverError' => 'An internal server error occurred.',
    'tooManyRequests' => 'Too many requests.',
    'invalidRequest' => 'Invalid request data.',
    'invalidJsonPayload' => 'Malformed JSON payload.',
    'transactionFailed' => 'Database transaction failed.',
    'updateError' => 'Could not update resource.',
    'deleteError' => 'Could not delete resource.',
    'saveFailed' => 'Could not save resource.',
    'responseDtoNotDefined' => 'Response DTO class not defined for {0}.',
    'responseDtoMustImplement' => 'Class {0} must implement DataTransferObjectInterface.',
    'fieldRequired' => 'The field {0} is required.',
];
