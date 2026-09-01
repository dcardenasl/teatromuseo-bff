<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Static registry for which trusted server-side caller (web/totem) is
 * making the current public-read request, as resolved by
 * {@see \App\Filters\WebAppKeyRequiredFilter} from the `X-App-Key` header.
 *
 * Readers use this — never a client-supplied query param — to apply
 * caller-specific rules (e.g. kiosk-only catalog curation), because the
 * filter always overwrites this value before the controller runs; a client
 * cannot spoof it.
 */
final class PublicReadCallerContext
{
    private static ?string $caller = null;

    public static function set(?string $caller): void
    {
        self::$caller = $caller;
    }

    public static function get(): ?string
    {
        return self::$caller;
    }

    public static function isTotem(): bool
    {
        return self::$caller === 'totem';
    }

    public static function flush(): void
    {
        self::$caller = null;
    }
}
