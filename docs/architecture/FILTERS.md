# Filter Stack

## Overview

The BFF's filter stack is intentionally minimal. The gateway's job is CORS,
rate limiting, and optional user-context injection — not RBAC enforcement or
request logging.

```
Inbound request
  │
  ▼  [Global — before]
  cors          → enforce CORS preflight, attach ACAO headers
  correlationid → read or generate X-Request-ID; stamp to RequestIdHolder
  locale        → resolve Accept-Language header
  invalidchars  → reject requests with null bytes / control characters
  maintenance   → return 503 if MAINTENANCE_MODE=true
  throttle*     → rate-limit by IP (and by user_id if auth context exists)
  │                 * except: /ping, /live, /ready
  ▼
  Route match
  │
  ▼  [Route-level — optional]
  introspectauth → validate JWT via hub introspect; populate ContextHolder
  featureToggle  → gate endpoint behind a runtime flag
  │
  ▼
  Controller
  │
  ▼  [Global — after]
  cors              → CORS headers for actual responses
  secureheaders     → X-Frame-Options, X-Content-Type-Options, etc.
  deprecationheaders → Deprecation / Sunset headers for versioned routes
  correlationid     → write X-Request-ID back to response
  throttle*         → attach X-RateLimit-Limit, X-RateLimit-Remaining headers
  performance       → (dev only) X-Response-Time
```

---

## ThrottleFilter — global rate limiting

### What it does

- **IP bucket:** every IP address has its own counter. Default: 60 requests per
  60-second window (`RATE_LIMIT_REQUESTS` / `RATE_LIMIT_WINDOW`).
- **User bucket:** if a user ID is available in `ContextHolder` (set by
  `IntrospectAuthFilter`), a separate per-user counter is tracked. Default: 100
  requests per window (`RATE_LIMIT_USER_REQUESTS`).
- **429 response:** includes `Retry-After`, `X-RateLimit-Limit`,
  `X-RateLimit-Remaining`, and `X-RateLimit-Reset` headers.

### Why ThrottleFilter is global in the BFF

Every client request entering the BFF should be rate-limited at the gateway,
regardless of whether the route requires authentication. The BFF is the
natural boundary for per-client rate enforcement — it is the first hop from
the client.

This is different from domain apps, where ThrottleFilter appears only in
per-route filter chains (`['domainauth', 'permission:items.read', 'throttle']`).
Domain apps assume that the BFF or hub has already limited the client at the
edge; applying throttle globally at the domain level would double-count and
reject requests that are legitimately under the per-service limit.

### Health probe exclusions

Infrastructure probes (`/ping`, `/live`, `/ready`) are excluded from both the
before and after throttle passes:

```php
// app/Config/Filters.php
$globals['before'] = [
    'throttle' => ['except' => ['ping', 'live', 'ready']],
];
$globals['after'] = [
    'throttle' => ['except' => ['ping', 'live', 'ready']],
];
```

Kubernetes liveness and readiness probes fire every few seconds. Counting them
against the IP bucket would cause false 429s for the cluster nodes that run the
probes. If you add another infrastructure endpoint that should be exempt, extend
both lists.

---

## IntrospectAuthFilter — opt-in user-context injection

### What it does

1. Extracts the `Bearer` token from `Authorization` header.
2. Calls `HubClient::introspect(token)` — response cached by SHA-256(token) for
   `Config\Hub::$introspectCacheTtl` seconds (default 60s).
3. If the introspect response says `valid: false`, returns 401.
4. If valid, calls `ContextHolder::set({ user_id, permissions })` so the
   controller can access user context without reading from the request object.

### Why IntrospectAuthFilter is opt-in (route-level) and not global

Most BFF endpoints are pure proxies. The upstream hub or domain validates the
token on every call — adding a global introspect step would:
- Cost an extra hub round-trip on every request.
- Reject unauthenticated requests (public endpoints, health probes) unless
  explicitly excluded.
- Duplicate the validation the upstream already performs.

Apply it only at routes that genuinely need user identity:

```php
$routes->get('me/dashboard', '...', ['filter' => 'introspectauth']);
```

### ContextHolder vs $this->request

The filter writes to `ContextHolder`, not to `$this->request`. In CI4 feature
tests, the framework replaces the request with a vanilla `IncomingRequest` that
does not carry `getAuthUserId()`. Reading from `ContextHolder::get()` in the
controller is the test-safe pattern regardless of the request type.

---

## Absent filters — intentional gaps

### No DomainAuthFilter

`DomainAuthFilter` is a domain-app concern. It makes the domain's auth flow
always-on because every endpoint in a domain app requires authentication.
The BFF is a gateway — most endpoints are unauthenticated proxies.
`IntrospectAuthFilter` is the BFF's equivalent, applied only where needed.

### No PermissionFilter

The BFF never enforces RBAC. Authorization belongs to the service that owns
the data (hub or domain). A BFF that enforces permissions would need to
understand every permission of every upstream service, creating tight coupling
between the gateway and business logic.

The `IntrospectAuthFilter` provides identity (`user_id`, `permissions[]`) for
personalisation, not for access control.

### No requestLogging

The domain registers a `requestLogging` filter (after global) that persists
structured request/response pairs to the database. The BFF is stateless — it
has no database, so DB-backed request logs are not an option.

Observability at the BFF layer is achieved via:
- **`X-Request-ID`** propagated by the `correlationid` filter end-to-end
  (client → BFF → upstream → BFF → client). Correlate BFF logs with hub logs
  by this header.
- **Sentry breadcrumbs** emitted by `AbstractServiceClient` for every outbound
  HTTP call (method, path, status, duration, attempt number).
- **Structured stdout logging** via `log_message()` / Monolog for any additional
  BFF-layer context. There is no framework filter for this — add it inline in
  controllers or service methods where needed.

---

## Adding a new filter

1. Create `app/Filters/MyFilter.php` implementing `CodeIgniter\Filters\FilterInterface`.
2. Register the alias in `app/Config/Filters.php`:
   ```php
   $aliases['myfilter'] = MyFilter::class;
   ```
3. Apply globally (add to `$globals`) or per-route (`['filter' => 'myfilter']`).
4. The architecture test `tests/Unit/Architecture/FilterConventionsTest.php` will
   catch any filter that does not implement `FilterInterface`.
