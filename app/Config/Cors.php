<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Cross-Origin Resource Sharing (CORS) Configuration
 *
 * The BFF's allow-list is the single source of truth for CORS origins.
 * This class reads `Config\Bff::$allowedOrigins` (parsed from
 * `BFF_ALLOWED_ORIGINS` in .env).
 *
 * Methods, headers, exposed headers, credentials and maxAge are still
 * overridable via the dedicated `CORS_*` env vars for fine-grained tuning.
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
 */
class Cors extends BaseConfig
{
    /**
     * @var array{
     *      allowedOrigins: list<string>,
     *      allowedOriginsPatterns: list<string>,
     *      supportsCredentials: bool,
     *      allowedHeaders: list<string>,
     *      exposedHeaders: list<string>,
     *      allowedMethods: list<string>,
     *      maxAge: int,
     *  }
     */
    public array $default = [
        'allowedOrigins'         => [],
        'allowedOriginsPatterns' => [],
        'supportsCredentials'    => false,
        'allowedHeaders'         => ['Content-Type', 'Authorization', 'X-App-Key', 'X-Requested-With', 'X-Request-Id', 'Accept', 'Origin'],
        'exposedHeaders'         => [],
        'allowedMethods'         => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        'maxAge'                 => 86400,
    ];

    public function __construct()
    {
        parent::__construct();

        /** @var \Config\Bff $bff */
        $bff = config('Bff');

        $this->default['allowedOrigins']      = $bff->allowedOrigins;
        $this->default['supportsCredentials'] = filter_var(
            env('CORS_SUPPORTS_CREDENTIALS', false),
            FILTER_VALIDATE_BOOL
        );
        $this->default['maxAge'] = (int) env('CORS_MAX_AGE', $this->default['maxAge']);

        $allowedMethods = $this->parseCsv((string) env('CORS_ALLOWED_METHODS', ''));
        $allowedHeaders = $this->parseCsv((string) env('CORS_ALLOWED_HEADERS', ''));
        $exposedHeaders = $this->parseCsv((string) env('CORS_EXPOSED_HEADERS', ''));

        if ($allowedMethods !== []) {
            $this->default['allowedMethods'] = $allowedMethods;
        }
        if ($allowedHeaders !== []) {
            $this->default['allowedHeaders'] = $allowedHeaders;
        }
        if ($exposedHeaders !== []) {
            $this->default['exposedHeaders'] = $exposedHeaders;
        }
    }

    /**
     * @return list<string>
     */
    private function parseCsv(string $value): array
    {
        if ($value === '') {
            return [];
        }

        $items = array_map(static fn (string $item): string => trim($item), explode(',', $value));

        return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
    }
}
