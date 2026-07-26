# Request Flow — The Three Composable Patterns

The BFF exposes three composable request patterns. Every endpoint in the BFF
is one of these, or a combination. Pick the one that matches the endpoint's
job; the shipped examples in this repo are copy-paste starting points.

---

## Pattern 1: Proxy

**Use when:** The BFF does not need to touch the payload — just forward it.

Status code, body, and content-type flow back unchanged from the upstream.
The client sees exactly what the upstream returned.

### Flow

```
Client
  │  GET /api/v1/users/42
  │  Authorization: Bearer <token>
  ▼
BFF (UsersProxyController::show)
  │
  │  AbstractServiceClient::forward(request, '/api/v1/users/42')
  │    headers forwarded: Authorization, Accept-Language, Content-Type, X-Request-Id
  ▼
Hub (GET /api/v1/users/42)
  │  validates JWT, enforces permission:users.read
  ▼
BFF
  │  upstream status + body + content-type → copied to $this->response
  ▼
Client  ←  200 { data: { id: 42, ... } }
```

If the upstream returns 4xx, the BFF passes it through unchanged. If a network
error or 5xx occurs (after one retry), `AbstractServiceClient` throws
`ServiceUnavailableException`, which `BaseProxyController::proxy()` catches and
renders as a 503 in the platform wire shape.

### Implementation

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
// app/Config/Routes/v1/users.php
$routes->get('users/(:num)', '\App\Controllers\Api\V1\Users\UsersProxyController::show/$1');
```

### Shipped example

`app/Controllers/Api/V1/Users/UsersProxyController.php`
`tests/Feature/Proxy/UsersProxyTest.php`

---

## Pattern 2: Aggregator

**Use when:** One client request fans out to N upstream calls and the response
merges all results into a single envelope.

No user context is required. If any callable throws an `ApiException`, the
aggregation aborts immediately (fail-fast) and the error is rendered in the
platform wire shape.

### Flow

```
Client
  │  GET /api/v1/status
  ▼
BFF (StatusController::index)
  │
  │  aggregate([
  │    'hub_version'   => fn() => hub->request('GET', '/api/v1/health'),
  │    'platform_info' => fn() => ['env' => ENVIRONMENT],
  │  ])
  │
  │  ┌── call 1: GET hub /api/v1/health ──► hub responds 200
  │  └── call 2: local computation
  ▼
Client  ←  200 {
              status: "success",
              data: {
                hub_version: { ... },
                platform_info: { env: "development" }
              }
            }
```

If call 1 throws (e.g. hub unreachable → `ServiceUnavailableException`), call 2
is never executed and the client receives a 503.

### Implementation

```php
class StatusController extends BaseProxyController
{
    public function index(): ResponseInterface
    {
        $hub = Services::hubClient();
        return $this->aggregate([
            'hub_version'   => static fn () => $hub->request('GET', '/api/v1/health'),
            'platform_info' => static fn () => ['env' => ENVIRONMENT],
        ]);
    }
}
```

### Shipped example

There is no pure unauthenticated aggregator in the default routes. The
`DashboardController` below (Pattern 3) is an aggregator with auth context
added. For a no-auth aggregator, copy the structure above.

---

## Pattern 3: Introspect-protected aggregator

**Use when:** The response depends on the authenticated user — and the BFF must
therefore know who they are.

Add `introspectauth` at the route level. The filter calls
`HubClient::introspect()` (cached by SHA-256 of the token, TTL 60s by default),
writes `{user_id, permissions[]}` to `ContextHolder`, and lets the controller
proceed. If the token is absent or invalid, the filter returns 401 before
the controller runs.

### Flow

```
Client
  │  GET /api/v1/me/dashboard
  │  Authorization: Bearer <token>
  ▼
BFF (IntrospectAuthFilter — route-level)
  │
  │  HubClient::introspect(token)  ──►  Hub POST /auth/introspect
  │  [cached if seen within introspectCacheTtl seconds]
  │
  │  if invalid → 401 (never reaches controller)
  │  if valid   → ContextHolder::set({ user_id: 42, permissions: [...] })
  ▼
BFF (DashboardController::index)
  │
  │  context = ContextHolder::get()   // user_id: 42
  │  bearer  = extractBearerToken()
  │
  │  aggregate([
  │    'profile'     => fn() => hub->getUser(42, bearer),
  │    'permissions' => fn() => ['scope' => context->permissions],
  │  ])
  │
  │  ┌── call 1: GET hub /api/v1/users/42  (with Authorization header)
  │  └── call 2: local data from context
  ▼
Client  ←  200 {
              status: "success",
              data: {
                profile:     { id: 42, first_name: "Ada", ... },
                permissions: { scope: ["users.read", "items.write"] }
              }
            }
```

### Why ContextHolder and not $this->request?

CI4 replaces the request object with a vanilla `IncomingRequest` in feature
tests. `IncomingRequest` does not carry `getAuthUserId()` or `getAuthPermissions()`.
`ContextHolder` is set by the filter regardless of which request type the
framework hands the controller, so it is the safe, test-compatible choice.

### Implementation

```php
// Route (app/Config/Routes/v1/me.php)
$routes->get(
    'me/dashboard',
    '\App\Controllers\Api\V1\Me\DashboardController::index',
    ['filter' => 'introspectauth'],
);
```

```php
// Controller (app/Controllers/Api/V1/Me/DashboardController.php)
class DashboardController extends BaseProxyController
{
    public function index(): ResponseInterface
    {
        $context     = ContextHolder::get();
        $userId      = $context?->user_id;
        $permissions = $context !== null ? $context->permissions : [];
        $bearer      = $this->extractBearerToken();

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

### Shipped example

`app/Controllers/Api/V1/Me/DashboardController.php`
`tests/Feature/Me/DashboardAggregatorTest.php`

---

## Combining patterns

The three patterns compose freely within a single controller method. Common
real-world combinations:

| Scenario | Combination |
|----------|-------------|
| Personalized catalog | Pattern 3 (introspect) + Pattern 2 (aggregate user prefs + catalog items) |
| Write-then-read | Pattern 1 (proxy POST to domain) + Pattern 1 (proxy GET to hub) |
| Conditional fan-out | Pattern 2 with a callable that either forwards or returns local data |

---

## Error handling

All three patterns share the same error surface via `BaseProxyController`:

| Error | Source | BFF behaviour |
|-------|--------|---------------|
| Upstream 4xx | Upstream service | Passed through unchanged (proxy) or rendered via `ExceptionFormatter` (aggregate) |
| Upstream 5xx / network (after retry) | `AbstractServiceClient` throws `ServiceUnavailableException` | Rendered as 503 |
| Invalid token | `IntrospectAuthFilter` | 401 before controller runs |
| Missing auth context in controller | Controller throws `AuthenticationException` | Rendered as 401 |
| Aggregate callable throws `ApiException` | First exception wins; rest are skipped | Rendered as the exception's status |

The error wire shape matches the rest of the platform:
`{ status: "error", message: "...", errors: { general: "..." } }`.
