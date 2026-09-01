# BFF Architecture Overview

## Role in the platform stack

`ci4-bff-starter` is a stateless HTTP gateway placed between decoupled clients
(SPA, mobile) and the backend services of the platform. It runs on port 8188.

```
Client (SPA / mobile)
        |
        v
  ci4-bff-starter  (:8188)
        |
        +---> ci4-api-starter / Hub  (:8180)   [users, auth, IAM, hub domain]
        |
        +---> ci4-domain-starter     (:8190)   [domain-specific data]
```

The BFF exists to solve three gateway concerns that the hub and domain apps
should not own:

1. **CORS** — a single configurable origin allow-list for all client origins.
2. **Request shaping** — aggregate N upstream calls into one client response,
   or reshape a request before forwarding it upstream.
3. **Optional user-context injection** — introspect the client's JWT against
   the hub (cached) so aggregator endpoints can personalize their responses
   without the hub knowing about the BFF's shape.

## Fundamental query rule: SQL first

The BFF's performance value is not merely reducing the number of client HTTP
requests. Its read projections must give the database engine the work it is
designed to do. When related data belongs to one database, prefer one bounded
SQL projection with `JOIN`s, conditional aggregation, filtering, grouping,
ordering and limits. Do not issue several queries and reconstruct the result
with loops, in-memory joins, counts, grouping or sorting in PHP.

PHP should be limited to transport, authenticated context, response envelopes
and source-level fallback. It must not materialize large datasets to calculate
metrics or relationships that SQL can calculate more efficiently.

The platform has independent CMS, Catalog, Event and Hub databases, so a single
cross-database `JOIN` is not always technically available. In that case the
BFF uses one bounded, permission-aware SQL projection per database and performs
only minimal composition of the completed source sections. Any additional
query or PHP-side computation must have a documented reason, hard bounds and
performance coverage.

---

## What the BFF is NOT

These invariants are enforced by design, not by convention. Breaking any of
them turns the BFF into something it is not.

| Invariant | Reason |
|-----------|--------|
| **No database** | The BFF is stateless. Every call to the BFF can land on any instance without shared state. |
| **No JWT validation** | The BFF never holds the JWT secret. It forwards the `Authorization` header upstream; the hub validates it. For routes that need user context, `IntrospectAuthFilter` delegates validation to the hub via `/auth/introspect`. |
| **No RBAC enforcement** | Permission checks live in the hub (via `PermissionFilter`) and in domain apps. The BFF never blocks a request based on permissions. |
| **No user storage** | Users are a hub concern. The BFF holds no identity data across requests. |
| **No audit log** | Audit logging belongs to the service that owns the data. The BFF traces requests via `X-Request-ID` propagation but does not record structured audit events. |

---

## Component map

```
                          ┌─────────────────────────────────────────────────────┐
                          │                  ci4-bff-starter                    │
                          │                                                     │
  Client request          │  Config/Filters.php (globals)                       │
  ─────────────────────►  │    cors → correlationid → throttle (except probes)  │
                          │          ↓                                          │
                          │  Route match (Config/Routes/v1/*.php via glob)      │
                          │          ↓                                          │
                          │  [optional] IntrospectAuthFilter  ──► HubClient     │
                          │          ↓                            ::introspect() │
                          │  Controller extends BaseProxyController              │
                          │    ::proxy()      → HubClient::forward()            │
                          │    ::aggregate()  → HubClient::request() × N        │
                          │          ↓                                          │
  Client response         │  ApiResponse / upstream passthrough                 │
  ◄─────────────────────  │                                                     │
                          └─────────────────────────────────────────────────────┘
```

### Key components

**`Config\Bff`** — local server view. Holds `hubUrl`, `domainUrl`, and `allowedOrigins`.
`Bff::resolveHubUrl()` is the canonical hub URL resolver; both `Config\Bff` and
`Config\Hub` call it.

**`Config\Hub`** — outbound client view. Holds `apiKey`, `appCode`, endpoint
paths (`introspectPath`, `serviceTokenPath`, `permissionsPath`), timeouts, and
cache TTLs. Endpoint paths are configurable so a hub API version bump is a
one-config change.

**`HubClient`** — the only place that calls the hub. Extends
`AbstractServiceClient` from `ci4-api-core`, which provides:
- One retry on 5xx / network errors.
- Configurable timeout from `Config\Hub::$httpTimeout`.
- `X-Request-ID` propagation via `RequestIdHolder`.
- Canonical exception mapping (401 → `AuthenticationException`, 5xx →
  `ServiceUnavailableException`, etc.).

`HubClient` adds hub-specific cached endpoints:
- `introspect(token)` — cached by SHA-256(token), TTL = `introspectCacheTtl` (default 60s).
- `getServiceToken()` — M2M token cached until `serviceTokenSafetyMargin` seconds before expiry.
- `registerPermission(perm, bearerToken)` — idempotent, used by setup tooling only.
- `getUser(userId, bearerToken)` — fetches a user profile from the hub.

**`BaseProxyController`** — abstract base for all BFF controllers. Provides
`proxy()` (transparent 1:1 forward) and `aggregate()` (fan-out + merge). Both
methods catch `ApiException` and render it via `ExceptionFormatter` so the
error wire shape matches the rest of the platform.

**`IntrospectAuthFilter`** — opt-in, route-level only. Calls
`HubClient::introspect()` (cached), adapts the result to the
`AbstractJwtAuthFilter` contract, and writes `{user_id, permissions}` to
`ContextHolder`. Controllers read user context from `ContextHolder::get()`,
not from the request object.

---

## Design decisions

### Why no PermissionFilter?

The BFF is a gateway, not a service. Authorization decisions belong to the
service that owns the data. A BFF that enforces RBAC would need to understand
every permission of every upstream service — defeating the purpose of
decoupling. The BFF trusts that the hub and domain enforce access correctly.

For the one case where the BFF needs user context (personalised aggregation),
`IntrospectAuthFilter` provides identity (`user_id`, `permissions[]`) without
enforcing any specific permission check.

### Why is IntrospectAuthFilter opt-in and not global?

Most BFF endpoints are pure proxies that forward the request including its
`Authorization` header. The upstream validates the token. Adding a global
introspect step would:
- Cost an extra hub round-trip on every request.
- Break unauthenticated endpoints (public catalog, health probes).
- Duplicate the validation the upstream already performs.

Opt-in via `['filter' => 'introspectauth']` at the route level means only
endpoints that genuinely need user context pay the introspect cost.

### Why is ThrottleFilter global?

Every client request entering the BFF should be rate-limited regardless of
whether it reaches an authenticated route. The gateway is the natural boundary
for per-client rate enforcement. Domain apps do not apply throttle globally
because they assume the BFF (or hub) has already limited the client at the
edge — double-counting would reject requests that are legitimately under the
per-service limit.

Infrastructure probes (`/ping`, `/live`, `/ready`) are exempt so orchestrators
(Kubernetes liveness/readiness) can poll without exhausting the IP bucket.

### Why does HealthController extend Controller instead of BaseProxyController?

Health endpoints are called every 5–10s by orchestrators. The `ApiController`
stack (DTOs, request data collection, response mapping) adds overhead that is
not needed for a simple JSON ping. `HealthController` extends the lightweight
`CodeIgniter\Controller` for this reason, following the same justified exception
pattern used by the hub's `HealthController`.

### Why is `/ready` cheap while `/health` is detailed?

The BFF's readiness depends on whether it can reach its primary upstream (the
hub). A tight-timeout `GET {hubUrl}/ping` is the correct operational probe for
a stateless gateway, so `/ready` performs only that one upstream check.

`/health` is an explicit diagnostic endpoint, not a high-frequency monitor. It
also checks the four named read-only database connections plus local disk and
writable-folder state. Polling it every few seconds would consume PHP
processes and database connections on the production shared host; cPanel and
external monitors must use `/ping` or `/live`, and use `/ready` only when the
single Hub dependency must be verified.

---

## Where to read next

- [REQUEST_FLOW.md](REQUEST_FLOW.md) — the three composable endpoint patterns with flow diagrams.
- [FILTERS.md](FILTERS.md) — detailed filter stack, throttle strategy, and absent filters.
- `../../CLAUDE.md` — quick coding guide with copy-pasteable snippets and common pitfalls.
- `../../ci4-api-starter/CLAUDE.md` — the hub's API patterns, auth contract, and RBAC.
- `../../ci4-domain-starter/CLAUDE.md` — the domain app delegation model.
