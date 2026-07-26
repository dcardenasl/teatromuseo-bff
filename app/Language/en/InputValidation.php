<?php

declare(strict_types=1);

/**
 * Input Validation Language Strings
 *
 * Contains all validation error messages for the InputValidation layer.
 * Organized by domain (auth, user, file, token, audit) and common messages.
 */
return [
    // ========================================
    // Common/Shared Messages
    // ========================================
    'common' => [
        // ID validation
        'idRequired'         => '{0} is required',
        'idMustBePositive'   => '{0} must be a positive integer',

        // Pagination
        'pageMustBePositive'     => 'Page must be a positive integer',
        'perPageMustBePositive'  => 'Items per page must be a positive integer',
        'perPageExceedsMax'      => 'Items per page cannot exceed 100',
        'sortFieldInvalid'       => 'Sort field contains invalid characters',
        'sortDirInvalid'         => 'Sort direction must be either asc or desc',
        'searchTooLong'          => 'Search query is too long',

        // Names
        'nameRequired'            => 'Name is required',
        'nameMaxLength'           => 'Name cannot exceed 255 characters',
        'firstNameMaxLength'     => 'First name cannot exceed 100 characters',
        'lastNameMaxLength'      => 'Last name cannot exceed 100 characters',

        // Email
        'emailRequired'          => 'Email is required',
        'emailInvalid'           => 'Please provide a valid email address',
        'emailMaxLength'         => 'Email cannot exceed 255 characters',
        'emailAlreadyRegistered' => 'This email is already registered',

        // Password
        'passwordRequired'       => 'Password is required',
        'passwordStrength'       => 'Password must be 8-128 characters with uppercase, lowercase, number, and special character',
        'newPasswordRequired'    => 'New password is required',

        // Role
        'roleInvalid'            => 'Role must be either user, admin, or superadmin',

        // OAuth
        'oauthProviderInvalid'   => 'OAuth provider is not supported',
        'oauthProviderIdMaxLength' => 'OAuth provider id cannot exceed 255 characters',
        'avatarUrlInvalid'       => 'Avatar URL must be a valid URL',
        'avatarUrlMaxLength'     => 'Avatar URL cannot exceed 255 characters',

        // User ID
        'userIdMustBeInteger'    => 'User ID must be an integer',
        'userIdMustBePositive'   => 'User ID must be a positive integer',
        'userIdRequired'         => 'User ID is required',

        // Validation configuration
        'unknownValidationDomain' => 'Validation domain "{0}" is not registered',
        'unknownValidationAction' => 'Validation action "{0}" is not defined for domain "{1}"',
    ],

    // ========================================
    // Auth Domain
    // ========================================
    'auth' => [
        // Login
        'emailRequired'          => 'Email is required',
        'idTokenRequired'        => 'Google ID token is required',
        'idTokenInvalid'         => 'Google ID token is invalid',
        'clientBaseUrlInvalid'   => 'Client base URL must be a valid URL',

        // Token
        'resetTokenRequired'      => 'Reset token is required',
        'resetTokenInvalid'       => 'Invalid reset token format',
        'verificationTokenRequired' => 'Verification token is required',
        'verificationTokenInvalid'  => 'Invalid verification token format',
        'verificationTokenMinLength' => 'Verification token must be at least 10 characters',
        'refreshTokenRequired'    => 'Refresh token is required',
        'refreshTokenInvalid'     => 'Invalid refresh token',
    ],

    // ========================================
    // File Domain
    // ========================================
    'file' => [
        'noFileUploaded'         => 'No file was uploaded',
        'fileTooLarge'           => 'File size cannot exceed 10MB',
        'fileTypeNotAllowed'     => 'File type is not allowed',
    ],

    // ========================================
    // Token Domain
    // ========================================
    'token' => [
        'refreshTokenRequired'   => 'Refresh token is required',
        'refreshTokenInvalid'    => 'Invalid refresh token format',
    ],

    // ========================================
    // API Key Domain
    // ========================================
    'apiKey' => [
        'nameRequired'                => 'API key name is required',
        'nameMaxLength'               => 'Name cannot exceed {0} characters',
        'keyPrefixRequired'           => 'Key prefix is required',
        'keyPrefixMaxLength'          => 'Key prefix cannot exceed {0} characters',
        'keyHashRequired'             => 'Key hash is required',
        'keyHashMaxLength'            => 'Key hash cannot exceed {0} characters',
        'rateLimitRequestsInteger'    => 'Rate limit requests must be an integer',
        'rateLimitRequestsGreaterThan' => 'Rate limit requests must be greater than 0',
        'rateLimitWindowInteger'      => 'Rate limit window must be an integer',
        'rateLimitWindowGreaterThan'  => 'Rate limit window must be greater than 0',
        'userRateLimitInteger'        => 'User rate limit must be an integer',
        'userRateLimitGreaterThan'    => 'User rate limit must be greater than 0',
        'ipRateLimitInteger'          => 'IP rate limit must be an integer',
        'ipRateLimitGreaterThan'      => 'IP rate limit must be greater than 0',
    ],

    // ========================================
    // Audit Domain
    // ========================================
    'audit' => [
        'actionInvalidChars'     => 'Action contains invalid characters',
        'actionTooLong'          => 'Action cannot exceed 50 characters',
        'entityTypeRequired'     => 'Entity type is required',
        'entityTypeInvalidChars' => 'Entity type contains invalid characters',
        'entityTypeTooLong'      => 'Entity type cannot exceed 50 characters',
        'entityIdRequired'       => 'Entity ID is required',
        'entityIdMustBePositive' => 'Entity ID must be a positive integer',
        'fromDateInvalid'        => 'From date must be in Y-m-d format',
        'toDateInvalid'          => 'To date must be in Y-m-d format',
        'auditLogIdRequired'     => 'Audit log ID is required',
        'auditLogEntityRequired' => 'Entity type and ID are required',
        'auditLogNotFound'       => 'Audit log not found',
    ],
];
