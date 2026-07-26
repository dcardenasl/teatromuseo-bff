<?php

declare(strict_types=1);

/**
 * Custom Validation Language Strings
 *
 * This file contains language strings for custom validation rules
 * defined in App\Validations\Rules\CustomRules
 */
return [
    // Strong password rule messages
    'strong_password'           => 'The {field} must be 8-128 characters and contain uppercase, lowercase, digit, and special character.',
    'strong_password_min_length' => 'Password must be at least 8 characters.',
    'strong_password_max_length' => 'Password must not exceed 128 characters.',
    'strong_password_lowercase'  => 'Password must contain at least one lowercase letter.',
    'strong_password_uppercase'  => 'Password must contain at least one uppercase letter.',
    'strong_password_digit'      => 'Password must contain at least one digit.',
    'strong_password_special'    => 'Password must contain at least one special character.',

    // Email IDN rule messages
    'valid_email_idn' => 'The {field} must be a valid email address.',

    // UUID rule messages
    'valid_uuid' => 'The {field} must be a valid UUID.',

    // Token rule messages
    'valid_token'        => 'The {field} must be a valid token.',
    'valid_token_format' => 'The {field} must contain only hexadecimal characters.',
    'valid_token_length' => 'The {field} must be exactly {0} characters.',

    // Sequential list rule message
    'is_list' => 'The {field} must be a sequential list of values.',
];
