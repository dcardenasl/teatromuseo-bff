<?php

declare(strict_types=1);

/**
 * Cadenas de validacion de entrada (Espanol)
 *
 * Contiene todos los mensajes de error de validacion para la capa InputValidation.
 * Organizado por dominio (auth, user, file, token, audit) y mensajes comunes.
 */
return [
    'common' => [
        'idRequired'               => '{0} es obligatorio',
        'idMustBePositive'         => '{0} debe ser un entero positivo',

        'pageMustBePositive'       => 'La pagina debe ser un entero positivo',
        'perPageMustBePositive'    => 'Los elementos por pagina deben ser un entero positivo',
        'perPageExceedsMax'        => 'Los elementos por pagina no pueden exceder 100',
        'sortFieldInvalid'         => 'El campo de ordenamiento contiene caracteres invalidos',
        'sortDirInvalid'           => 'La direccion de ordenamiento debe ser asc o desc',
        'searchTooLong'            => 'La consulta de busqueda es demasiado larga',

        'nameRequired'             => 'El nombre es obligatorio',
        'nameMaxLength'            => 'El nombre no puede exceder 255 caracteres',
        'firstNameMaxLength'       => 'El nombre no puede exceder 100 caracteres',
        'lastNameMaxLength'        => 'El apellido no puede exceder 100 caracteres',

        'emailRequired'            => 'El email es obligatorio',
        'emailInvalid'             => 'Proporcione una direccion de email valida',
        'emailMaxLength'           => 'El email no puede exceder 255 caracteres',
        'emailAlreadyRegistered'   => 'Este email ya esta registrado',

        'passwordRequired'         => 'La contrasena es obligatoria',
        'passwordStrength'         => 'La contrasena debe tener 8-128 caracteres con mayuscula, minuscula, numero y caracter especial',
        'newPasswordRequired'      => 'La nueva contrasena es obligatoria',

        'roleInvalid'              => 'El rol debe ser user, admin o superadmin',

        'oauthProviderInvalid'     => 'El proveedor OAuth no es compatible',
        'oauthProviderIdMaxLength' => 'El ID del proveedor OAuth no puede exceder 255 caracteres',
        'avatarUrlInvalid'         => 'La URL del avatar debe ser valida',
        'avatarUrlMaxLength'       => 'La URL del avatar no puede exceder 255 caracteres',

        'userIdMustBeInteger'      => 'El ID de usuario debe ser un entero',
        'userIdMustBePositive'     => 'El ID de usuario debe ser un entero positivo',
        'userIdRequired'           => 'El ID de usuario es obligatorio',

        'unknownValidationDomain'  => 'El dominio de validacion "{0}" no esta registrado',
        'unknownValidationAction'  => 'La accion de validacion "{0}" no esta definida para el dominio "{1}"',
    ],

    'auth' => [
        'emailRequired'              => 'El email es obligatorio',
        'idTokenRequired'            => 'El token de Google es obligatorio',
        'idTokenInvalid'             => 'El token de Google es invalido',
        'clientBaseUrlInvalid'       => 'La URL base del cliente debe ser valida',

        'resetTokenRequired'         => 'El token de restablecimiento es obligatorio',
        'resetTokenInvalid'          => 'Formato de token de restablecimiento invalido',
        'verificationTokenRequired'  => 'El token de verificacion es obligatorio',
        'verificationTokenInvalid'   => 'Formato de token de verificacion invalido',
        'verificationTokenMinLength' => 'El token de verificacion debe tener al menos 10 caracteres',
        'refreshTokenRequired'       => 'El token de actualizacion es obligatorio',
        'refreshTokenInvalid'        => 'Token de actualizacion invalido',
    ],

    'file' => [
        'noFileUploaded'         => 'No se subio ningun archivo',
        'fileTooLarge'           => 'El tamano del archivo no puede exceder 10MB',
        'fileTypeNotAllowed'     => 'El tipo de archivo no esta permitido',
    ],

    'token' => [
        'refreshTokenRequired'   => 'El token de actualizacion es obligatorio',
        'refreshTokenInvalid'    => 'Formato de token de actualizacion invalido',
    ],

    'apiKey' => [
        'nameRequired'                 => 'El nombre de la clave de API es obligatorio',
        'nameMaxLength'                => 'El nombre no puede exceder {0} caracteres',
        'keyPrefixRequired'            => 'El prefijo de la clave es obligatorio',
        'keyPrefixMaxLength'           => 'El prefijo de la clave no puede exceder {0} caracteres',
        'keyHashRequired'              => 'El hash de la clave es obligatorio',
        'keyHashMaxLength'             => 'El hash de la clave no puede exceder {0} caracteres',
        'rateLimitRequestsInteger'     => 'El limite de solicitudes debe ser un entero',
        'rateLimitRequestsGreaterThan' => 'El limite de solicitudes debe ser mayor que 0',
        'rateLimitWindowInteger'       => 'La ventana de limite debe ser un entero',
        'rateLimitWindowGreaterThan'   => 'La ventana de limite debe ser mayor que 0',
        'userRateLimitInteger'         => 'El limite por usuario debe ser un entero',
        'userRateLimitGreaterThan'     => 'El limite por usuario debe ser mayor que 0',
        'ipRateLimitInteger'           => 'El limite por IP debe ser un entero',
        'ipRateLimitGreaterThan'       => 'El limite por IP debe ser mayor que 0',
    ],

    'audit' => [
        'actionInvalidChars'     => 'La accion contiene caracteres invalidos',
        'actionTooLong'          => 'La accion no puede exceder 50 caracteres',
        'entityTypeRequired'     => 'El tipo de entidad es obligatorio',
        'entityTypeInvalidChars' => 'El tipo de entidad contiene caracteres invalidos',
        'entityTypeTooLong'      => 'El tipo de entidad no puede exceder 50 caracteres',
        'entityIdRequired'       => 'El ID de entidad es obligatorio',
        'entityIdMustBePositive' => 'El ID de entidad debe ser un entero positivo',
        'fromDateInvalid'        => 'La fecha desde debe estar en formato Y-m-d',
        'toDateInvalid'          => 'La fecha hasta debe estar en formato Y-m-d',
        'auditLogIdRequired'     => 'El ID del registro de auditoria es obligatorio',
        'auditLogEntityRequired' => 'El tipo de entidad y el ID son obligatorios',
        'auditLogNotFound'       => 'Registro de auditoria no encontrado',
    ],
];
