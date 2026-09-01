# Agent Quick Reference — `teatromuseo-bff`

Read `CLAUDE.md` and `TASKS.md` before editing. The BFF is stateless and runs
on port `8188`; it forwards requests to the Hub (`8180`) or configured domain
apps.

## Commands

```bash
php spark serve --port 8188
composer test:unit
composer test:feature
composer test
composer quality
composer cs-fix
php spark swagger:generate
```

## Endpoint patterns

1. **Proxy:** one transparent upstream request through
   `BaseProxyController::proxy()`.
2. **Aggregator:** fan out calls and merge them through
   `BaseProxyController::aggregate()`.
3. **Introspect-protected aggregator:** add `introspectauth` at route level,
   then read the authenticated context from `ContextHolder::get()`.

Example route:

```php
$routes->get(
    'me/dashboard',
    '\\App\\Controllers\\Api\\V1\\Me\\DashboardController::index',
    ['filter' => 'introspectauth'],
);
```

## Important files

- `app/Controllers/BaseProxyController.php` — proxy and aggregate helpers.
- `app/Controllers/Api/V1/` — endpoint controllers.
- `app/Libraries/Hub/HubClient.php` — sole direct Hub egress point.
- `app/Filters/IntrospectAuthFilter.php` — opt-in Hub introspection.
- `app/Config/Bff.php` — `hubUrl`, domain map, and CORS origins.
- `app/Config/Hub.php` — outbound credentials, paths, and timeouts.
- `app/Config/Routes/v1/*.php` — auto-loaded versioned routes.

## Invariants

- No database, migrations, sessions, users, or local IAM.
- Never decode JWTs locally or hold the JWT secret; forward `Authorization`.
- Never make `IntrospectAuthFilter` global.
- Never call Hub URLs directly from controllers; use `HubClient`.
- Use `BFF_ALLOWED_ORIGINS` in production; an empty value fails startup.
- Mock upstream HTTP in tests and regenerate OpenAPI after endpoint changes.
