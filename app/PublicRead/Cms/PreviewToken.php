<?php

declare(strict_types=1);

namespace App\PublicRead\Cms;

/** Fail-closed verifier for the CMS/Admin signed preview link contract. */
final class PreviewToken
{
    public static function verify(string $type, string $identifier, ?string $expiresRaw, ?string $signatureRaw): bool
    {
        $secret = (string) env('CMS_PREVIEW_SECRET', '');
        if ($secret === '' || $expiresRaw === null || $expiresRaw === '' || $signatureRaw === null || $signatureRaw === '') {
            return false;
        }
        if (! ctype_digit($expiresRaw)) {
            return false;
        }

        $expires = (int) $expiresRaw;
        if ($expires < time()) {
            return false;
        }

        $expected = hash_hmac('sha256', $type . ':' . $identifier . ':' . $expires, $secret);

        return hash_equals($expected, $signatureRaw);
    }
}
