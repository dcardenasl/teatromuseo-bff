# Agent & Developer Quick Reference

This file is the fastest path to productivity. Read it before touching any code in this repo.

## Core commands

| Command | Purpose | When |
|---------|---------|------|
| `php spark serve --port 8188` | Start the BFF dev server | First terminal |
| `vendor/bin/phpunit` | Run all tests | Before pushing |
| `vendor/bin/phpunit tests/Unit` | Unit tests only (fast, no HTTP) | During development |
| `vendor/bin/phpunit tests/Feature` | Feature / HTTP tests | Before pushing |
| `composer quality` | Full quality gate (phpstan + cs + arch + tests) | Before pushing |
| `composer cs-fix` | Auto-fix code style | After writing code |
| `php spark swagger:generate` | Regenerate OpenAPI spec | After adding an endpoint |
| `php spark core:check` | Validate services wiring | After editing `Config/Services.php` |

## Adding an endpoint — pick a pattern

### Pattern 1: Proxy (forward one call transparently)

Use when the BFF does not need to touch the payload. Status, body, and
content-type flow back unchanged from the upstream.

```php
// app/Controllers/Api/V1/Products/ProductsProxyController.php
class ProductsProxyController extends BaseProxyController
{
    public function show(int $id): ResponseInterface
    {
        return $this->proxy(Services::hubClient(), '/api/v1/products/' . $id);
    }
}
```

```php
// app/Config/Routes/v1/products.php  (auto-loaded via glob)
$routes->get('products/(:num)', '\App\Controllers\Api\V1\Products\ProductsProxyController::show/$1');
```

Then annotate in `app/Documentation/Products/ProductsEndpoints.php` and run
`php spark swagger:generate`.

If the endpoint is declared in a template's `public_endpoints[]`, kickstart
will generate the corresponding route file automatically; the controller
surface is `App\Controllers\Api\V1\PublicProxyController::forward()`.

### Pattern 2: Aggregator (fan out, merge, no auth required)

Use when one client request fans out to N upstream calls and the response
merges all results. First exception aborts the rest.

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

Wire shape: `{ status: "success", data: { hub_version: {...}, platform_info: {...} } }`.

### Pattern 3: Introspect-protected aggregator (needs user context)

Use when the response depends on who is calling. Add `introspectauth` at the
route level; read `ContextHolder::get()` inside the controller.

```php
// Route (app/Config/Routes/v1/me.php)
$routes->get('me/profile', '\App\Controllers\Api\V1\Me\ProfileController::index', ['filter' => 'introspectauth']);
```

```php
// Controller
class ProfileController extends BaseProxyController
{
    public function index(): ResponseInterface
    {
        $context = ContextHolder::get();
        $userId  = $context?->user_id;
        $bearer  = $this->extractBearerToken();

        if ($userId === null || $bearer === null) {
            throw new AuthenticationException('Missing auth context.');
        }

        return $this->aggregate([
            'profile' => static fn () => Services::hubClient()->getUser($userId, $bearer),
        ]);
    }
}
```

## File structure map

```
app/
├── Config/
│   ├── Bff.php                  # Local server: hubUrl, domainUrl, allowedOrigins
│   ├── Hub.php                  # Outbound client: apiKey, appCode, paths, timeouts
│   ├── Filters.php              # Filter globals (throttle) + aliases (introspectauth)
│   ├── Services.php             # DI: hubClient(), healthChecker()
│   ├── Routes.php               # Root routes; glob-loads Routes/v1/*.php
│   └── Routes/v1/               # One file per resource group (auto-discovered)
├── Controllers/
│   ├── BaseProxyController.php  # proxy() + aggregate() helpers
│   └── Api/V1/
│       ├── System/HealthController.php    # /ping /live /ready /health
│       ├── Users/UsersProxyController.php # proxy example
│       └── Me/DashboardController.php     # aggregator example
├── Documentation/               # OpenAPI annotations (one file per group)
├── Filters/
│   ├── IntrospectAuthFilter.php # Route-level opt-in auth via hub introspect
│   ├── ThrottleFilter.php       # Global rate limiting (IP + optional user bucket)
│   └── Concerns/RateLimitResponseHelpers.php
└── Libraries/
    └── Hub/
        ├── HubClient.php        # Single egress point to the hub
        └── IntrospectResult.php # Value object for /auth/introspect responses
```

## Required environment variables

| Variable | Purpose |
|---|---|
| `bff.hubUrl` | Hub base URL (e.g. `http://localhost:8180`). Canonical — preferred over `hub.url`. |
| `bff.domainUrl` | Domain app base URL (optional; only needed if BFF calls domain). |
| `BFF_ALLOWED_ORIGINS` | Comma-separated CORS allow-list. Empty in production throws. |
| `encryption.key` | CI4 encryption key (`hex2bin:` + 32 random bytes). |
| `hub.appCode` | App code registered in the hub. Required for service-token M2M calls. |
| `hub.apiKey` | `X-App-Key` value. Required for introspect and service-token calls. |

## Quality standards checklist

1. Every new controller extends `BaseProxyController` (or `Controller` for infra endpoints with a docblock explaining why).
2. Every new endpoint uses `proxy()` or `aggregate()` — never build a raw `ResponseInterface` from scratch.
3. `IntrospectAuthFilter` is route-level opt-in only. Never add it to `$globals`.
4. Every new endpoint is annotated under `app/Documentation/` and committed with the regenerated spec.
5. Feature tests mock the upstream CURL calls; never hit a real upstream in tests.
6. `composer quality` passes before pushing.

## Common mistakes

See `CLAUDE.md` "Common pitfalls" for the full list. The most frequent ones:

- Decoding JWTs locally — never. Use `IntrospectAuthFilter` and `HubClient::introspect()`.
- Returning a fresh `Response` from a proxy action instead of using `BaseProxyController::proxy()`.
- Reading `$this->request->getAuthUserId()` in a controller — use `ContextHolder::get()` instead (CI4 replaces the request object in feature tests).
- Adding a new infrastructure endpoint (health-variant) without adding it to the throttle `except` list in `Config\Filters`.
