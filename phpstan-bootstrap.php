<?php

declare(strict_types=1);

// PHPStan-only bootstrap. Loaded via `bootstrapFiles:` in phpstan.neon, NOT at
// runtime — CI4 defines these globals in system/Common.php when the app boots.
// Stubbing them here lets PHPStan resolve the calls instead of suppressing a
// broad "function not found" regex in phpstan.neon (a misspelled helper now
// surfaces as an error instead of being silently ignored).
//
// Factory-style helpers (service/model/config/cache/env/old/session) return
// `mixed` on purpose: returning `null` would make every chained call
// (`service('x')->y()`) a "method on null" error. ENVIRONMENT is intentionally
// NOT defined so PHPStan treats `ENVIRONMENT === 'production'` as runtime-unknown
// rather than always-false; the matching constant warning stays in phpstan.neon.

foreach ([
    'APPPATH'    => __DIR__ . '/app/',
    'ROOTPATH'   => __DIR__ . '/',
    'WRITEPATH'  => __DIR__ . '/writable/',
    'FCPATH'     => __DIR__ . '/public/',
    'SYSTEMPATH' => __DIR__ . '/vendor/codeigniter4/framework/system/',
] as $constant => $value) {
    if (! defined($constant)) {
        define($constant, $value);
    }
}

if (! function_exists('service')) {
    function service(string $name, mixed ...$params): mixed
    {
        return null;
    }
}

if (! function_exists('model')) {
    function model(string $name, bool $getShared = true, mixed $conn = null): mixed
    {
        return null;
    }
}

if (! function_exists('config')) {
    function config(?string $name = null): mixed
    {
        return null;
    }
}

if (! function_exists('cache')) {
    function cache(?string $key = null): mixed
    {
        return null;
    }
}

if (! function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return $default;
    }
}

if (! function_exists('old')) {
    function old(string $key, mixed $default = null, mixed $escape = 'html'): mixed
    {
        return $default;
    }
}

if (! function_exists('session')) {
    function session(?string $val = null): mixed
    {
        return null;
    }
}

if (! function_exists('lang')) {
    function lang(string $line, array $args = [], ?string $locale = null): string
    {
        return $line;
    }
}

if (! function_exists('esc')) {
    function esc(mixed $data, string $context = 'html', ?string $encoding = null): mixed
    {
        return $data;
    }
}

if (! function_exists('helper')) {
    /**
     * @param string|list<string> $filenames
     */
    function helper($filenames): void
    {
    }
}

if (! function_exists('view')) {
    function view(string $name, array $data = [], array $options = []): string
    {
        return $name;
    }
}

if (! function_exists('base_url')) {
    function base_url(mixed $relativePath = '', ?string $scheme = null): string
    {
        return is_string($relativePath) ? $relativePath : '';
    }
}

if (! function_exists('site_url')) {
    function site_url(mixed $relativePath = '', ?string $scheme = null, ?\Config\App $config = null): string
    {
        return is_string($relativePath) ? $relativePath : '';
    }
}

if (! function_exists('is_cli')) {
    function is_cli(): bool
    {
        return false;
    }
}

if (! function_exists('log_message')) {
    function log_message(string $level, string $message, array $context = []): void
    {
    }
}

if (! function_exists('clean_path')) {
    function clean_path(string $path): string
    {
        return $path;
    }
}

if (! function_exists('sanitize_filename')) {
    function sanitize_filename(string $filename, bool $relativePath = false): string
    {
        return $filename;
    }
}

if (! function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return '';
    }
}

if (! function_exists('csrf_hash')) {
    function csrf_hash(): string
    {
        return '';
    }
}

if (! function_exists('csrf_field')) {
    function csrf_field(?string $id = null): string
    {
        return '';
    }
}

if (! function_exists('csrf_meta')) {
    function csrf_meta(?string $id = null): string
    {
        return '';
    }
}
