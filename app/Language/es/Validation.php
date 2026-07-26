<?php

declare(strict_types=1);

/**
 * Cadenas de validación personalizadas (Español)
 *
 * Este archivo contiene cadenas de idioma para reglas de validación personalizadas
 * definidas en App\Validations\Rules\CustomRules
 */
return [
    // Mensajes de regla de contraseña fuerte
    'strong_password'           => 'El campo {field} debe tener 8-128 caracteres y contener mayúscula, minúscula, dígito y carácter especial.',
    'strong_password_min_length' => 'La contraseña debe tener al menos 8 caracteres.',
    'strong_password_max_length' => 'La contraseña no debe exceder 128 caracteres.',
    'strong_password_lowercase'  => 'La contraseña debe contener al menos una letra minúscula.',
    'strong_password_uppercase'  => 'La contraseña debe contener al menos una letra mayúscula.',
    'strong_password_digit'      => 'La contraseña debe contener al menos un dígito.',
    'strong_password_special'    => 'La contraseña debe contener al menos un carácter especial.',

    // Mensajes de regla de email IDN
    'valid_email_idn' => 'El campo {field} debe ser una dirección de email válida.',

    // Mensajes de regla UUID
    'valid_uuid' => 'El campo {field} debe ser un UUID válido.',

    // Mensajes de regla de token
    'valid_token'        => 'El campo {field} debe ser un token válido.',
    'valid_token_format' => 'El campo {field} debe contener solo caracteres hexadecimales.',
    'valid_token_length' => 'El campo {field} debe tener exactamente {0} caracteres.',

    // Mensaje de regla de lista secuencial
    'is_list' => 'El campo {field} debe ser una lista secuencial de valores.',
];
