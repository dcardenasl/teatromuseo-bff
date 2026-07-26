# ci4-bff-starter

Stateless Backend-for-Frontend gateway template (port 8188).
Forwards client requests to hub (`ci4-api-starter`) and/or domain apps. No database, no JWT validation, no user storage.

## Entry Points

- `app/Controllers/Api/V1/` — Proxy controllers (extend `BaseProxyController`)
- `app/Libraries/Hub/HubClient.php` — Only place that calls the hub
- `app/Filters/IntrospectAuthFilter.php` — Optional JWT auth via hub introspection
- `app/Config/Routes/v1/*.php` — Auto-loaded route files (glob; one file per domain area)
- `app/Config/Bff.php` — `hubUrl`, `domainUrl`, `allowedOrigins`
- `app/Config/Hub.php` — Outbound client config: `apiKey`, `appCode`, endpoint paths

## Contracts & Invariants

- Never decode JWTs locally — forward the `Authorization` header; the hub validates.
- `IntrospectAuthFilter` is route-level opt-in only, never global.
- BFF is stateless: no sessions, no persistent user data, no audit records here.
- `HubClient` is the only place that calls the hub — never call hub URLs directly from controllers.
- `BFF_ALLOWED_ORIGINS` must be set in production; empty → throws on startup.

## Patterns

Three composable patterns — choose one per endpoint:

1. **Proxy** — transparent passthrough, three lines:
   ```php
   return $this->proxy(Services::hubClient(), '/api/v1/path');
   ```

2. **Aggregator** — fan out N calls, merge into one response:
   ```php
   return $this->aggregate(['key1' => fn() => $hub->call1(), 'key2' => fn() => $hub->call2()]);
   ```

3. **Introspect-protected** — add `'filter' => 'introspectauth'` at the route level, then read `ContextHolder::get()` in the controller for `user_id` and `permissions`.

## Commands

```bash
php spark serve --port 8188
vendor/bin/phpunit
composer quality    # phpstan + cs-check + phpunit + arch-drift
composer cs-fix
```

## Anti-patterns

- Don't bypass `BaseProxyController::proxy()` — CI4 test infrastructure requires it.
- Don't persist anything (sessions, users, audit) — use hub/domain for state.
- Don't make `IntrospectAuthFilter` global — it adds an introspect round-trip cost per request.

## Related Context

- Detailed reference: `CLAUDE.md` (this repo)
- Hub API it talks to: `dcardenasl/ci4-api-starter`
- Domain app it can proxy: `dcardenasl/ci4-domain-starter`
