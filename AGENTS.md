# AGENTS.md — `teatromuseo-bff`

## Purpose and boundaries

This is the stateless Backend-for-Frontend gateway, served locally on port
`8188`. It fronts decoupled clients such as SPAs and mobile applications and
forwards requests to the Hub (`8180`) or configured domain apps.

- No owned/write database, migrations, sessions, users, or persistent audit
  data. The only database exception is `app/PublicRead/**`, which may use the
  four named SELECT-only `BaseConnection` groups and never models or writes.
- The BFF never decodes JWTs or holds the JWT secret.
- It forwards the client's `Authorization` header to upstream services.
- `IntrospectAuthFilter` is an opt-in route filter that asks the Hub to
  introspect a token and puts the application-scoped user context in
  `ContextHolder`.
- `EffectivePermissionsAuthFilter` is an opt-in route filter for
  cross-application projections; it asks the Hub's canonical `/auth/me` for
  the user's effective permissions and adapts that response into
  `ContextHolder`.
- `HubClient` is the only class that calls Hub URLs directly.

Read this repository's `CLAUDE.md` and `TASKS.md` before editing. Check the
repository status first and keep unrelated work intact.

## Important entry points

- `app/Controllers/Api/V1/` — proxy and aggregator controllers.
- `app/Controllers/BaseProxyController.php` — `proxy()` and `aggregate()`.
- `app/Libraries/Hub/HubClient.php` — outbound Hub client and cached Hub calls.
- `app/Filters/IntrospectAuthFilter.php` — optional application-scoped auth context.
- `app/Filters/EffectivePermissionsAuthFilter.php` — optional
  cross-application auth context for composed Admin projections.
- `app/Config/Bff.php` — Hub/domain URLs and CORS origins.
- `app/Config/Hub.php` — Hub client credentials, paths, and timeouts.
- `app/Config/Routes/v1/*.php` — versioned route files, loaded automatically.

`BFF_ALLOWED_ORIGINS` must be configured in production. The Hub base URL is
resolved from `bff.hubUrl`, with `hub.url` retained as a compatibility fallback.

## Commands

Run these from this repository root:

```bash
composer install
php spark serve --port 8188

composer test:unit
composer test:feature
composer test
composer quality
composer cs-fix
php spark swagger:generate
```

## Endpoint patterns

Choose exactly one pattern per endpoint:

1. **Proxy:** one transparent upstream call through
   `BaseProxyController::proxy()`.
2. **Aggregator:** fan out calls and merge them through
   `BaseProxyController::aggregate()`.
3. **Authenticated aggregator:** add `introspectauth` for an
   application-scoped context or `effectivepermissionsauth` for a
   cross-application context; read `ContextHolder::get()` and use the
   authenticated context explicitly.

Forward only through the configured client. Preserve the canonical response
and exception behavior supplied by the base controller and service client.
Keep route files under `app/Config/Routes/v1/`; do not register new endpoints
only in a controller.

## Anti-patterns

- Do not add models, migrations, sessions, user storage, or local IAM here.
- Do not decode or verify JWTs locally; use the appropriate Hub-backed auth
  filter when user context is genuinely required.
- Do not make `IntrospectAuthFilter` global; it is intentionally route-level.
- Do not bypass `BaseProxyController::proxy()` or `aggregate()` for HTTP calls.
- Do not call Hub URLs directly from controllers; use `HubClient`/services.
- Do not commit `.env`, API keys, bearer tokens, or production CORS settings.
