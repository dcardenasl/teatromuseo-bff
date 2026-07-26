<?php

declare(strict_types=1);

namespace App\Validations\Rules;

/**
 * Custom Validation Rules
 *
 * Contains custom validation rules for the application.
 * Registered in Config/Validation.php $ruleSets array.
 */
class CustomRules
{
    /**
     * Validate password strength
     *
     * Requirements:
     * - 8-128 characters
     * - At least one uppercase letter
     * - At least one lowercase letter
     * - At least one digit
     * - At least one special character
     *
     * @param string|null $value The password to validate
     * @param string|null $error Error message (passed by reference)
     * @return bool
     */
    public function strong_password(?string $value, ?string &$error = null): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        $length = strlen($value);

        if ($length < 8) {
            $error = lang('Validation.strong_password_min_length');
            return false;
        }

        if ($length > 128) {
            $error = lang('Validation.strong_password_max_length');
            return false;
        }

        if (!preg_match('/[a-z]/', $value)) {
            $error = lang('Validation.strong_password_lowercase');
            return false;
        }

        if (!preg_match('/[A-Z]/', $value)) {
            $error = lang('Validation.strong_password_uppercase');
            return false;
        }

        if (!preg_match('/\d/', $value)) {
            $error = lang('Validation.strong_password_digit');
            return false;
        }

        if (!preg_match('/[\W_]/', $value)) {
            $error = lang('Validation.strong_password_special');
            return false;
        }

        return true;
    }

    /**
     * Validate email with International Domain Name (IDN) support
     *
     * Converts international domain names to ASCII (punycode) before validation.
     *
     * @param string|null $value The email to validate
     * @param string|null $error Error message (passed by reference)
     * @return bool
     */
    public function valid_email_idn(?string $value, ?string &$error = null): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        $emailToValidate = $value;

        if (strpos($value, '@') !== false) {
            [$localPart, $domain] = explode('@', $value, 2);

            // Convert international domain to punycode for validation
            $asciiDomain = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            if ($asciiDomain !== false) {
                $emailToValidate = $localPart . '@' . $asciiDomain;
            }
        }

        if (!filter_var($emailToValidate, FILTER_VALIDATE_EMAIL)) {
            $error = lang('Validation.valid_email_idn');
            return false;
        }

        return true;
    }

    /**
     * Validate that a value is a valid UUID v4
     *
     * @param string|null $value The value to validate
     * @param string|null $error Error message (passed by reference)
     * @return bool
     */
    public function valid_uuid(?string $value, ?string &$error = null): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

        if (!preg_match($pattern, $value)) {
            $error = lang('Validation.valid_uuid');
            return false;
        }

        return true;
    }

    /**
     * Validate that a value is a valid token (hex string)
     *
     * @param string|null $value The value to validate
     * @param string      $params Expected length (default: 64 for 32 bytes hex)
     * @param array       $data   All validation data
     * @param string|null $error  Error message (passed by reference)
     * @return bool
     */
    public function valid_token(?string $value, string $params = '64', array $data = [], ?string &$error = null): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        $expectedLength = (int) $params;

        if (!ctype_xdigit($value)) {
            $error = lang('Validation.valid_token_format');
            return false;
        }

        if (strlen($value) !== $expectedLength) {
            $error = lang('Validation.valid_token_length', [$expectedLength]);
            return false;
        }

        return true;
    }

    /**
     * Validate that a value is a sequential, 0-indexed array (a "list").
     *
     * @param mixed $value
     */
    public function is_list($value, ?string &$error = null): bool
    {
        if (!is_array($value) || !array_is_list($value)) {
            $error = lang('Validation.is_list');
            return false;
        }

        return true;
    }
}
