# CLAUDE.md

Guidance for Claude Code when working in this repository.

## What this is

`ci4-bff-starter` is a CodeIgniter 4 **Backend-for-Frontend** template. It is
a stateless HTTP gateway placed between decoupled clients (SPA, mobile) and
the rest of the platform:

```
Client (SPA/mobile)  →  ci4-bff-starter (:8188)
                            ├─▶ ci4-api-starter (hub, :8180)
                            └─▶ ci4-domain-starter (:8190)
```

## Boundaries

- **No owned/write database.** No migrations, no models, no repositories or
  writes. The only exception is the direct public-read seam below.
- **Direct public-read seam.** `app/PublicRead/**` may use the four named
  `BaseConnection` groups (`cms_readonly`, `catalog_readonly`,
  `event_readonly`, `hub_readonly`) for SELECT-only reads. It must never use a
  Model, `model()`, or a write query; `Config\Database::$default` remains the
  SQLite compatibility stub. This exception is local to `teatromuseo-bff` and
  does not change the `ci4-bff-starter` template contract.
- **No JWT validation.** The BFF forwards the client's `Authorization`
  header to the upstream hub/domain. The upstream validates and either
  returns the response or a 401 — the BFF just relays.
- **No permission model.** Authorization lives in the hub (RBAC) and is
  enforced by the hub/domain on every call.
- **No user storage.** Users live in the hub.

The BFF's job is: CORS, request shaping, response aggregation across
hub + domain, optional service-token-based admin calls, and the Web's
cross-domain public-read surface.

## Essential commands

```bash
# Dev server (default port 8188 to fit the 808X series of the kit)
php spark serve --port 8188

# Tests
vendor/bin/phpunit                     # all
vendor/bin/phpunit tests/Unit          # unit only
vendor/bin/phpunit tests/Feature       # feature/HTTP

# Quality gates
composer quality   # phpstan + cs-check + phpunit + arch-drift
composer cs-fix    # auto-fix style
```

## Architecture cheat sheet

```
Controller (extends BaseProxyController)
   ↓
HubClient (extends AbstractServiceClient)
   ↓
upstream HTTP call  →  retry 1× on 5xx/network · X-Request-Id forwarded
                       · canonical exceptions on 4xx · ResponseInterface on success
```

Base classes live in `dcardenasl/ci4-api-core` (Packagist):

- `dcardenasl\Ci4ApiCore\Http\Client\AbstractServiceClient` (BFF-101) — outbound
  HTTP base shared with `ci4-domain-starter`. Provides `request()` (typed
  JSON call) and `forward()` (transparent proxy). The BFF's `HubClient` is a
  thin subclass that adds hub-specific cached endpoints (introspect, service
  token, permission registration).
- `App\Controllers\BaseProxyController` (BFF-103) — `proxy()` for one-to-one
  passthroughs, `aggregate()` for fan-out + merge into a single
  `ApiResponse::success({...})` envelope. Catches `ApiException` and renders
  via `ExceptionFormatter` so error wire-shape matches the rest of the kit.
- `App\Filters\IntrospectAuthFilter` (BFF-106) — opt-in JWT auth. Delegates
  `decodeToken()` to `HubClient::introspect()` and populates
  `ContextHolder::get()` with `{user_id, permissions}`. The BFF never holds
  the JWT secret; introspect responses are cached.
- `App\Libraries\Hub\HubClient` — the only place that calls the hub. Holds
  the cached service token (`getServiceToken()`); auto-renews
  `Config\Hub::$serviceTokenSafetyMargin` seconds before expiry.
- `Config\Bff` (BFF-002) — local server config: `hubUrl`, `domainUrl`,
  `allowedOrigins`. `Bff::resolveHubUrl()` (BFF-108) is the canonical
  resolver — both `Config\Bff::$hubUrl` and `Config\Hub::$url` flow from it.
- `Config\Hub` — outbound client config: `apiKey`, `appCode`, paths,
  timeouts. Endpoint paths (`$introspectPath`, `$serviceTokenPath`,
  `$permissionsPath`) live here so a hub API bump is a one-config change.
- **No** `DomainAuthFilter` and **no** `PermissionFilter` — by design.
  Backend validates; BFF forwards. `IntrospectAuthFilter` is route-level
  opt-in only.

`BaseProxyController::aggregate()` is currently sequential by design. The
existing dashboard aggregator has one upstream call, so adding concurrency
would add complexity without a current benefit. A future aggregator with two
or more independent upstream calls must trigger an explicit concurrency
review and preserve the existing fail-fast error semantics.

## Adding an endpoint — three patterns

The BFF ships three composable patterns. Pick the one that matches the
endpoint's job; copy the example, change the upstream path.

### Pattern 1 — Proxy (one upstream call, transparent passthrough)

Use when the BFF doesn't need to touch the payload — just forward it.
Status, body and content-type flow back unchanged. Three lines:

```php
// app/Controllers/Api/V1/Users/UsersProxyController.php
class UsersProxyController extends BaseProxyController
{
    public function show(int $id): ResponseInterface
    {
        return $this->proxy(Services::hubClient(), '/api/v1/users/' . $id);
    }
}
```

```php
// app/Config/Routes/v1/users.php  (auto-loaded via glob)
$routes->get('users/(:num)', '\App\Controllers\Api\V1\Users\UsersProxyController::show/$1');
```

The upstream's `Authorization` header is forwarded verbatim by
`AbstractServiceClient::forward()` (allow-list: `Authorization`,
`Accept-Language`, `Content-Type`, `X-Request-Id`). 4xx/5xx pass through;
only network failures map to a canonical `ServiceUnavailableException`.

### Pattern 2 — Aggregator (fan out, merge, no auth context)

Use when one client request fans out to N upstream calls and you want a
single response. The callable for each key may throw an `ApiException` —
fail-fast aborts the rest and renders the exception:

```php
class StatusController extends BaseProxyController
{
    public function index(): ResponseInterface
    {
        $hub = Services::hubClient();
        return $this->aggregate([
            'hub_version'    => static fn () => $hub->getVersion(),     // throws on 5xx
            'platform_info'  => static fn () => ['env' => ENVIRONMENT], // pure local
        ]);
    }
}
```

The wire shape is `{status: "success", data: {hub_version: {...}, platform_info: {...}}}`.

### Pattern 3 — Introspect-protected aggregator (needs user context)

Use when the response depends on the authenticated user — and the BFF
must therefore know who they are. Attach `introspectauth` at the route
level and read `ContextHolder::get()` inside the controller:

```php
// app/Config/Routes/v1/me.php
$routes->get(
    'me/dashboard',
    '\App\Controllers\Api\V1\Me\DashboardController::index',
    ['filter' => 'introspectauth'],
);
```

```php
// app/Controllers/Api/V1/Me/DashboardController.php
class DashboardController extends BaseProxyController
{
    public function index(): ResponseInterface
    {
        $context     = ContextHolder::get();
        $userId      = $context?->user_id;
        $permissions = $context !== null ? $context->permissions : [];
        $bearer      = $this->extractBearerToken(); // your helper

        if ($userId === null || $bearer === null) {
            throw new AuthenticationException('Missing authenticated user context.');
        }

        $hub = Services::hubClient();
        return $this->aggregate([
            'profile'     => static fn () => $hub->getUser($userId, $bearer),
            'permissions' => static fn () => ['scope' => $permissions],
        ]);
    }
}
```

Why read from `ContextHolder` and not `$this->request`? In feature tests
CI4 replaces the request with a vanilla `IncomingRequest` (not
`ApiRequest`), so `getAuthUserId()` would not be available. The context
holder is set by the filter regardless of which request type the framework
hands the controller.

### What ships out of the box

After scaffolding you already have:

| Pattern | Example | Test |
|---|---|---|
| Proxy | `GET /api/v1/users/{id}` → hub | `tests/Feature/Proxy/UsersProxyTest.php` |
| Aggregator (auth) | `GET /api/v1/me/dashboard` | `tests/Feature/Me/DashboardAggregatorTest.php` |
| Health probe | `GET /ready` (pings hub) | `tests/Feature/Controllers/System/HealthControllerTest.php` |

Copy whichever fits, change the upstream path, write a feature test that
mocks `curlrequest`. `composer swagger:generate` regenerates the OpenAPI
spec; `tests/Feature/Swagger/SwaggerGenerationTest.php` will fail if the
new endpoint isn't annotated under `app/Documentation/`.

## Required environment variables

| Variable | Purpose |
|---|---|
| `bff.hubUrl` | Base URL of the hub (e.g. `http://localhost:8180`) |
| `bff.domainUrl` | Base URL of the upstream domain app (optional) |
| `BFF_ALLOWED_ORIGINS` | Comma-separated CORS allow-list. Empty in production = throw. |
| `encryption.key` | CI4 encryption key (32 bytes after `hex2bin:` decode) |
| `hub.appCode`, `hub.apiKey` | Only needed if the BFF uses a service token for M2M calls |
| `CMS_READONLY_DB_*`, `CATALOG_READONLY_DB_*`, `EVENT_READONLY_DB_*`, `HUB_READONLY_DB_*` | SELECT-only credentials for the isolated `app/PublicRead/**` seam |

## Common pitfalls

- ❌ **Decoding JWTs locally.** The BFF never holds the JWT secret. If a
  route needs the user context, use `IntrospectAuthFilter` (delegates to
  the hub) — never `firebase/php-jwt` or similar.
- ❌ **Making `IntrospectAuthFilter` global.** It is route-level opt-in by
  design. The default flow stays forward-only so most endpoints incur no
  introspect cost.
- ❌ **Persisting anything** (users, sessions, audit). The BFF is stateless.
  Use the hub/domain for state.
- ❌ **Reading per-user state from caches keyed by JWT.** The token is
  opaque to the BFF. Key by `auth_user_id` from `ContextHolder` instead,
  and only on routes already gated by `introspectauth`.
- ❌ **Skipping the throttle `except` list.** `Config\Filters` adds
  `throttle` globally with `except: [ping, live, ready]` — orchestrator
  probes must stay out of the bucket. If you add another infrastructure
  endpoint, extend the list.
- ❌ **Returning a fresh `Response` from a proxy action.** CI4 test
  infrastructure wraps the body unless you mutate `$this->response`. The
  `BaseProxyController::proxy()` helper already does this correctly —
  use it rather than rolling your own.

## Where to read next

- `../ci4-api-starter/CLAUDE.md` — hub's API patterns, auth, RBAC.
- `../ci4-domain-starter/CLAUDE.md` — domain app delegation model.
- `vendor/dcardenasl/ci4-api-core/docs/ARCHITECTURE_CONTRACT.md` — DTO-first
  patterns enforced by the shared base classes.
