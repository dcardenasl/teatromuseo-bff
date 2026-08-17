# BFF Context

## Role

`teatromuseo-bff` is a stateless Backend-for-Frontend. It composes the
read-only public surface for the Web and decoupled clients; it does not own
write data, migrations, models, sessions or user identity.

## Seams

- `app/PublicRead/**` is the only direct SQL seam. It uses named SELECT-only
  connections for CMS, Catalog, Event and Hub file metadata.
- `PageResolver` is the public routing/composition seam. It resolves redirects,
  CMS pages, domain details, collection entries and fallback indexes into the
  single `page-resolve` envelope.
- `PublicPagePaths` is a local adapter for incoming route resolution. The Web
  owns the visitor-facing route policy; CI compares both adapters through the
  versioned Web contract at `teatromuseo-web/docs/contracts/public-routes.json`.

## Invariants

- Detail readers return the complete localized `slugs` map, including their
  default `fields=[]` projection. List projections remain locale-scoped unless
  the caller explicitly requests the complete slug map.
- The BFF never performs a write query or hides an upstream 4xx/404 behind
  stale data.
- The BFF is SQL-first: related data in one database is projected with bounded
  database-side `JOIN`s and aggregates; PHP does not replace joins, counts,
  grouping, sorting or filtering with multiple queries and in-memory work.
  Cross-database projections use one bounded SQL projection per source and
  minimal envelope composition only.
- Changes to public route segments or aliases update Web and BFF together and
  must pass the cross-repository route-contract check.

## Verification

Run `composer quality` for the local gate. The CI matrix includes PHP 8.2,
the declared minimum runtime, and runs `composer check-platform-reqs --no-dev`.
The route contract job loads the two independent adapters without sharing
runtime code or deployment dependencies.

`composer test:integration` runs the opt-in MySQL 8 projection contract. It
uses disposable fixtures and named read connections; it does not turn the BFF
into an application that owns schema or write migrations.
