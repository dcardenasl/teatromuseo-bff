<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;
use RuntimeException;

/**
 * BFF configuration — the **local server**'s view of the world.
 *
 * Owns three things the BFF itself decides:
 *   - which upstream hub to talk to (`hubUrl`)
 *   - which upstream domain app to talk to (`domainUrl`, optional)
 *   - which client origins to accept (`allowedOrigins`)
 *
 * For the **upstream client's** view (M2M auth, paths, timeouts), see
 * {@see Hub} — they're intentionally separate so future BFF deployments
 * fronting a different hub API version only touch `Hub`. The shared piece
 * — the hub's base URL — is canonicalised under `bff.hubUrl` here; `hub.url`
 * is accepted as a fallback so older `.env` files keep working.
 *
 * Authentication is forward-only: the BFF does not validate JWTs, it relays
 * the client's `Authorization` header to the upstream. This config therefore
 * has nothing to say about JWT secrets or sessions.
 */
class Bff extends BaseConfig
{
    /**
     * Base URL of the upstream hub (no trailing slash). e.g. http://localhost:8180
     *
     * Resolved from `bff.hubUrl` first; falls back to `hub.url` if unset.
     */
    public string $hubUrl = '';

    /**
     * Base URLs of upstream domain apps.
     *
     * @var array<string, string> Key is the domain identifier, value is the URL.
     */
    public array $domains = [];

    /**
     * Origins permitted by CORS. Populated from the comma-separated
     * `BFF_ALLOWED_ORIGINS` env var.
     *
     * @var list<string>
     */
    public array $allowedOrigins = [];

    public function __construct()
    {
        parent::__construct();

        $this->hubUrl = self::resolveHubUrl();

        // Parse domains from env: BFF_DOMAINS="auth:http://localhost:8190,billing:http://localhost:8091"
        $rawDomains = (string) env('BFF_DOMAINS', '');
        $this->domains = $this->parseDomains($rawDomains);

        $rawOrigins = (string) env('BFF_ALLOWED_ORIGINS', '');
        $this->allowedOrigins = $this->parseCsv($rawOrigins);

        if (ENVIRONMENT === 'production' && $this->allowedOrigins === []) {
            throw new RuntimeException(
                'BFF misconfigured for production: BFF_ALLOWED_ORIGINS is empty. '
                . 'Set a comma-separated list of permitted client origins in .env.'
            );
        }
    }

    /**
     * Parses the BFF_DOMAINS env var into an associative array.
     *
     * @return array<string, string>
     */
    private function parseDomains(string $value): array
    {
        if ($value === '') {
            return [];
        }

        $domains = [];
        foreach (explode(',', $value) as $item) {
            $parts = explode(':', trim($item), 2);
            if (count($parts) === 2) {
                $domains[trim($parts[0])] = trim($parts[1]);
            }
        }

        return $domains;
    }


    /**
     * Single resolver shared with {@see Hub} so both configs land on the same
     * hub URL regardless of which env var the operator wrote.
     */
    public static function resolveHubUrl(): string
    {
        $primary = (string) env('bff.hubUrl', '');
        if ($primary !== '') {
            return $primary;
        }

        return (string) env('hub.url', '');
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
