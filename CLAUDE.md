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
- **Direct read seams.** `app/PublicRead/**` may use the four named
  `BaseConnection` groups (`cms_readonly`, `catalog_readonly`,
  `event_readonly`, `hub_readonly`) for SELECT-only reads. It must never use a
  Model, `model()`, or a write query; `Config\Database::$default` remains the
  SQLite compatibility stub. `app/AdminRead/**` uses the same named groups for
  authenticated, permission-filtered projections: dashboard, analytics,
  translations, file usages, Event lookups and CMS bootstrap/workspace reads.
  These exceptions are local to `teatromuseo-bff` and do not change the
  `ci4-bff-starter` template contract.
  - **Documented exception:** `AdminCmsWizardSource` and `AdminCmsBootstrapSource`
    (under `app/AdminRead/Cms/`) additionally call the CMS Domain over
    authenticated HTTP via `DomainClient`, instead of reading `cms_readonly`
    directly — `AdminCmsWizardSource` exclusively, `AdminCmsBootstrapSource` as
    a fallback path alongside its direct-SQL projections. This reuses an
    existing CMS Domain compound endpoint (`/api/v1/cms/wizard/config`) rather
    than adding a second HTTP call or duplicating its business rules in SQL —
    it is a deliberate exception to the "SQL-only" rule above, not a violation
    of it.
  - **Cross-seam reuse:** `AdminReadContainer::cmsWorkspace()` constructs
    `App\PublicRead\Cms\FileUrlResolver` and
    `App\PublicRead\Support\DirectDbFileMetaResolver` — both `PublicRead`
    namespace classes — to resolve file URLs for the authenticated CMS
    workspace projection. This is intentional reuse of file-URL resolution
    logic, not a boundary violation; `AdminRead` and `PublicRead` still each
    own their own query/permission logic.
- **No JWT validation.** The BFF forwards the client's `Authorization`
  header to the upstream hub/domain. The upstream validates and either
  returns the response or a 401 — the BFF just relays.
- **No permission model.** Authorization lives in the hub (RBAC) and is
  enforced by the hub/domain on every call.
- **No user storage.** Users live in the hub.

The public route policy is versioned by the Web application in
`teatromuseo-web/docs/contracts/public-routes.json`. This repository keeps
`PublicPagePaths` as an incoming-path adapter and exports the same contract;
do not change one adapter without updating the other and the cross-repository
CI check.

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
composer test:integration               # opt-in MySQL public-read contract

# Quality gates
composer quality   # phpstan + cs-check + phpunit + arch-drift
composer cs-fix    # auto-fix style
```

`composer test:integration` requires `RUN_PUBLIC_READ_INTEGRATION=1` and a
disposable MySQL 8 database configured through the named `*_READONLY_DB_*`
variables. CI provisions that database; the BFF test fixture creates only the
tables needed for the projection contract and never adds application
migrations or write paths.

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
- `App\Filters\IntrospectAuthFilter` (BFF-106) — opt-in JWT auth for
  application-scoped contexts. Delegates `decodeToken()` to
  `HubClient::introspect()` and populates `ContextHolder::get()` with
  `{user_id, permissions}`. The BFF never holds the JWT secret; introspect
  responses are cached.
- `App\Filters\EffectivePermissionsAuthFilter` — opt-in JWT auth for
  cross-application projections. Delegates validation to the Hub's canonical
  `GET /api/v1/auth/me`, which resolves effective permissions across all
  applications. It adapts only the returned identity/scope into the shared
  security context; it never decodes the JWT locally or shares API keys.
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
  Backend validates; BFF forwards. Both auth-context filters are route-level
  opt-in only.

### Fundamental query rule

The BFF is valuable because it moves read work into the database engine, not
because it moves a collection of small queries into another PHP process.
For every projection, use SQL first: a bounded `SELECT` with `JOIN`s,
conditional aggregates, `WHERE`, `GROUP BY`, `ORDER BY` and database-side
limits whenever the required data belongs to one database. Avoid multiple
queries whose rows are later joined, counted, grouped, sorted or filtered in
PHP, and never load an unbounded result set to calculate a dashboard metric.

For projections spanning independent databases, a cross-database `JOIN` may
not be available. In that case each source must still return one bounded,
permission-aware SQL projection; PHP may only merge the already-computed source
sections into the response envelope. A separate query or PHP-side computation
requires an explicit documented justification, a hard bound and coverage that
guards its performance characteristics.

The global `bff_request` telemetry record includes `db_query_count` for the
direct public-read routes. Use that field when validating a page composition;
it makes accidental per-block query fan-out observable in the hosting logs.

`BaseProxyController::aggregate()` is currently sequential by design. The
existing canonical `/me/dashboard` aggregator has one upstream call, so
adding concurrency would add complexity without a current benefit. The real
Admin consumer is `/me/admin-dashboard`; it uses the separate
`aggregatePartial()` primitive for independent Hub/CMS/Catalog/Event summaries
and reports source-level degradation. The dashboard also exposes CMS analytics
and translation sections while preserving independent source states. Both
primitives remain sequential. A future
aggregator with two or more independent upstream calls must trigger an
explicit concurrency review; do not change `aggregate()`'s fail-fast
semantics to implement partial degradation.

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

### Pattern 3 — Authenticated aggregator (needs user context)

Use when the response depends on the authenticated user — and the BFF
must therefore know who they are. Choose the route-level auth context that
matches the projection:

- `introspectauth` for one application's permission scope and the canonical
  `/me/dashboard` example.
- `effectivepermissionsauth` for a projection that composes permissions and
  data across multiple applications, such as `/me/admin-dashboard`.

Both filters delegate token validation to the Hub and expose the same
`ContextHolder::get()` contract to the controller. The second filter uses the
Hub's canonical `/auth/me` projection because `/auth/introspect` is
intentionally scoped to the caller's `X-App-Key` application.

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
        $bearer      = $this->extractBearerToken(); // inherited from BaseProxyController

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

The BFF exposes two authenticated dashboard aggregators with deliberately
different contracts:

- `GET /api/v1/me/dashboard` is the canonical fail-fast example. It combines
  the Hub profile and token permissions and remains available for reference.
- `GET /api/v1/me/admin-dashboard` is the real consumer used by
  `teatromuseo-admin`. It combines Hub, CMS, Catalog and Event summaries with
  `aggregatePartialData()` (the lower-level primitive behind `aggregatePartial()`
  — used directly, not through the wrapper, because the controller builds a
  custom `source`/`complete` envelope shape around the per-source results), so
  one unavailable source does not hide healthy sections. The Hub summary
  remains an authenticated upstream call; CMS, Catalog and Event use
  `app/AdminRead/**` direct SELECT-only readers. The route uses
  `effectivepermissionsauth` so readers receive the canonical cross-application
  permission scope from Hub `/auth/me`, while the existing source-level
  degradation contract remains unchanged.

### Pattern 4 — Single operation with pre-validation (`handleOperation()`)

Use when an endpoint needs to validate input (query params, path IDs) and
throw a clean `ValidationException`/`AuthorizationException` *before* building
its response envelope, but doesn't fan out to multiple independent sources the
way `aggregate()`/`aggregatePartial()` do. This is the primitive most
`Me/Admin*` controllers actually use — every CMS/Catalog/Event workspace and
bootstrap controller, plus `/me/admin-analytics` and
`/me/admin-cms/wizard-bootstrap`:

```php
class AdminCatalogWorkspaceController extends BaseProxyController
{
    public function workspace(?string $itemId = null): ResponseInterface
    {
        $id = $itemId === null ? null : $this->requirePositiveId($itemId, 'itemId');
        $context = ContextHolder::get();
        if ($context?->user_id === null) {
            throw new AuthenticationException('Missing authenticated user context.');
        }

        return $this->handleOperation(function () use ($id, $context): ResponseInterface {
            $sections = Services::adminReadCatalogCollectionItem()->workspace($id, $context->permissions);

            return $this->response->setJSON(ApiResponse::success([
                'version' => 1,
                'generated_at' => date(DATE_ATOM),
                'sections' => $sections,
            ]));
        }, 'Catalog admin collection item workspace');
    }
}
```

`handleOperation()` wraps the closure in the same sanitized error contract as
`proxy()`/`aggregate()` (`ApiException` → `ExceptionFormatter`; any other
`Throwable` → logged server-side, rendered as a generic `ServiceUnavailableException`)
and records one `RequestTelemetry` source entry keyed by the label passed as
the second argument. Validation that runs *before* `handleOperation()` (like
the ID check above) is not wrapped — let it throw its own `ValidationException`
directly so a 422 doesn't get relabeled as a 503.

### Pattern 5 — Direct public-read seam (`PublicReadSupport`)

The four `PublicRead/**` controllers (`CmsPublicReadController`,
`CatalogPublicReadController`, `EventPublicReadController`,
`PageResolutionController`) don't use `proxy()`/`aggregate()`/`handleOperation()`
at all — they extend `App\Controllers\Api\V1\PublicRead\PublicReadSupport`
(itself a `BaseProxyController` subclass), which provides its own envelope
primitives instead:

- `result(ApiResult $result)` — return a reader's `ApiResult` as-is (already
  shaped by `PublicReadEnvelope`).
- `data(array $data)` — wrap a plain array in `{data, meta: {generated_at}}`
  for endpoints that don't need the full envelope (e.g. `languages()`,
  `collections()`).
- `failure(string $locale, Throwable $exception, int $status = 503)` — the
  sanitized error path: logs the real exception server-side, returns
  `PublicReadEnvelope::unavailable()` to the client. Every action must wrap
  its reader call in `try { ... } catch (Throwable $exception) { return
  $this->failure(...); }` — a method that skips this (as `navigation()`/
  `settings()` used to) lets the exception escape to the framework's global
  handler instead of this sanitized path.

Use this pattern only for the public, unauthenticated, app-key-gated seam;
authenticated Admin projections belong to Pattern 3/4 instead.

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
| `hub.appCode`, `hub.apiKey` | Identity for the BFF's own Hub app-key calls; keep it separate from the Admin key |
| `CMS_READONLY_DB_*`, `CATALOG_READONLY_DB_*`, `EVENT_READONLY_DB_*`, `HUB_READONLY_DB_*` | SELECT-only credentials for the isolated `app/PublicRead/**` and authenticated `app/AdminRead/**` seams |

## Common pitfalls

- ❌ **Decoding JWTs locally.** The BFF never holds the JWT secret. If a
  route needs the user context, use the appropriate Hub-backed filter
  (`IntrospectAuthFilter` or `EffectivePermissionsAuthFilter`) — never
  `firebase/php-jwt` or similar.
- ❌ **Sharing API keys between applications.** `hub.apiKey` identifies the
  BFF application for its own Hub calls; it must not be replaced with the
  Admin key to manufacture a cross-application permission scope. Use
  `effectivepermissionsauth` for that explicit projection instead.
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
