<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Hub configuration — the **upstream client**'s view of the world.
 *
 * Owns the M2M credentials, endpoint paths and timeouts the `HubClient` uses
 * to talk to the central ci4-api-starter ("hub"). The hub's *location* is
 * shared with {@see Bff}; everything else here is HubClient-internal.
 *
 * Boundary with {@see Bff}:
 *   - `Bff` = local server config (what this BFF serves, who it accepts)
 *   - `Hub` = outbound client config (auth, paths, timeouts for hub calls)
 *
 * Both classes resolve `$url` / `$hubUrl` via {@see Bff::resolveHubUrl()} so a
 * single env var (`bff.hubUrl`, falling back to `hub.url`) drives both.
 */
class Hub extends BaseConfig
{
    /**
     * Base URL of the hub (no trailing slash). e.g. http://localhost:8180
     *
     * Resolved from `bff.hubUrl` first; falls back to `hub.url`.
     */
    public string $url = '';

    /**
     * App-key used in the X-App-Key header for hub calls. Created from the hub
     * with `php spark apps:bootstrap <code>` (which also creates the API Key
     * bound to the application).
     */
    public string $apiKey = '';

    /**
     * Domain app code as registered in the hub (matches the application code).
     */
    public string $appCode = '';

    /**
     * Cache TTL (seconds) for /auth/introspect responses keyed by JTI.
     * Lower = fresher revocation; higher = less load on the hub.
     */
    public int $introspectCacheTtl = 60;

    /**
     * Refresh the cached service token this many seconds before its expiry.
     */
    public int $serviceTokenSafetyMargin = 30;

    /**
     * Hard timeout (seconds) for HTTP calls to the hub.
     */
    public int $httpTimeout = 5;

    /**
     * Hub endpoint paths. Override here to point at a different hub API version
     * without forking the HubClient.
     */
    public string $introspectPath   = '/api/v1/auth/introspect';
    public string $serviceTokenPath = '/api/v1/auth/service-token';
    public string $permissionsPath  = '/api/v1/iam/permissions';

    public function __construct()
    {
        parent::__construct();
        $this->url     = Bff::resolveHubUrl();
        $this->apiKey  = (string) (env('hub.apiKey') ?: $this->apiKey);
        $this->appCode = (string) (env('hub.appCode') ?: $this->appCode);

        $ttl = env('hub.introspectCacheTtl');
        if ($ttl !== null && $ttl !== false && $ttl !== '') {
            $this->introspectCacheTtl = (int) $ttl;
        }
    }
}
