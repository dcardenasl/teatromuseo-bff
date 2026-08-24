# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **`me/admin-cms/categories/{bootstrap,{id}/bootstrap}`, `me/admin-catalog/collection-items/list-bootstrap`,
  `me/admin-catalog/techniques/{workspace,{id}/workspace}`, `me/admin-event/events/list-bootstrap`** —
  four new `effectivepermissionsauth`-gated Admin bootstrap/list/workspace projections, each backed
  by a bounded SELECT-only `AdminRead` source over the relevant domain's readonly connection.
- **`GET /me/admin-dashboard` reports per-source `duration_ms` and hosting `diagnostics`** —
  `ReadOnlyQuery::timedSql()` times the existing dashboard query instead of issuing a second
  probe, and `Monitoring\RuntimeDiagnostics` adds cheap local disk/writable-path checks, so
  degraded sources are distinguishable from a slow host without extra requests.
- **`ReadOnlyDatabaseGuard`** — `AdminReadContainer`/`PublicReadContainer` now fail fast in
  production if a `*_readonly` database group is missing required connection settings, instead
  of silently falling through to the SQLite compatibility stub.
- **Catalog listing accepts detail-field bulk requests (TOTEM-BFF-19)** — `CatalogPublicReadController::index()`
  now allows `DETAIL_FIELDS` via `?fields=` (previously restricted to `LIST_FIELDS`), so a bounded,
  single-consumer bulk reader (the totem's cache warm-up) can request the full detail projection
  for an entire category in one call instead of one call per item. The default projection with no
  `fields` param is unchanged — existing callers (Web) see no behavior change.
- **Trusted multi-caller `X-App-Key` + kiosk catalog curation** — `WebAppKeyRequiredFilter`
  now accepts a dedicated `TOTEM_BFF_API_KEY` alongside the existing web key, records the
  resolved caller (`web`/`totem`) in `App\Support\PublicReadCallerContext`, and the public-read
  catalog seam applies `collection_items.show_in_totem` curation only for the totem caller.
  `GET /api/v1/public/{locale}/catalog/categories|techniques` also accept `with_counts=1` to
  return a per-row `item_count` matching that same curated eligibility, without a separate
  full-listing fetch.
- **`last_occurrence_at` in the public event listing** — `GET /api/v1/public/{locale}/events`
  now exposes `last_occurrence_at` alongside `next_occurrence_at`, so a consumer like the totem
  kiosk can show the real date of a past event used as filler content.
- **`GET /api/v1/public-read/{locale}/page-resolve/{path}`** — domain detail pages
  (`template_catalog_item`/`template_event_item`) now inherit their owning CMS
  template's `robots`, `og_type`, `og_image` and `schema_data`, carry
  `published_at`/`updated_at` from the underlying catalog/event record, and expose
  `showPageHeading` (suppressed when a rendered block declares
  `presentation.owns_page_heading`) plus a per-locale `localized_urls` map so
  `teatromuseo-web` no longer has to rebuild them from route keys. The template's
  `meta_description` is also truncated to the block's declared
  `presentation.seo.description_max_length`, when set.
- **`GET /api/v1/public-read/**` and `GET /api/v1/public/**`** — the BFF now serves CMS
  layout/pages/entries/taxonomy, Catalog collection items/facets, and Event
  listings/detail/types directly from four dedicated SELECT-only MySQL connections
  (`cms_readonly`, `catalog_readonly`, `event_readonly`, `hub_readonly` for file
  metadata), removing the extra upstream HTTP hop for `teatromuseo-web`. Gated by a new
  `webappkey` filter; `/health` and `/ready` now probe all four connections.
- **`GET /api/v1/public-read/cms/{locale}/entries/{collection}`** — entry listings now
  accept `filter_by`/`filter_value`/`filter_operator`, `order_by=field:*` with
  `order_direction=UPCOMING`, and `include=listing_content.*` to resolve block-derived
  listing content, matching the query contract `teatromuseo-web` needs for CMS-owned
  listing pages.
- **Signed page previews** — `GET /api/v1/public-read/cms/{locale}/pages/{path}` and its
  `/bootstrap` variant accept an HMAC-signed `preview` token (`CMS_PREVIEW_SECRET`) to
  resolve unpublished pages; without a valid signature they fail closed to the published
  page.
- **Structured request telemetry** — the BFF now emits one bounded, structured log entry per
  request (`request_id`, path, seam, duration, status, response bytes, per-source duration/state
  and cache hit/miss counts) via a new global `telemetry` filter. Proxy/aggregator calls and
  `page-resolve` record their own source timing; admin read sources record cache hit/miss.
  Payloads, tokens and upstream bodies are never logged.

### Changed

- **`app/AdminRead/Cms/CmsTranslationsDashboardSource`** — the dashboard translations widget
  now issues one bounded SQL projection per active language against the CMS read-only
  connection instead of calling the CMS audit endpoint, matching the BFF's SQL-first rule.
  Response shape is unchanged.
- **`app/AdminRead/**` dashboard/analytics sources** — Catalog, Event, CMS dashboard and
  CMS analytics projections now issue one bounded SQL query per source (UNION ALL / CTE
  with database-side aggregation) instead of one query per resource followed by PHP-side
  merging and sorting. Response shape is unchanged. Codified as the BFF's "SQL-first"
  fundamental query rule in `CLAUDE.md`/`AGENTS.md`/`CONTEXT.md`/`docs/architecture/BFF_OVERVIEW.md`.
- **Runtime dependency** — upgraded `dcardenasl/ci4-api-core` to `v1.1.1`.
- **Local deployment hygiene** — ignored local `.deploy` tooling so deployment helpers are not
  accidentally included in the BFF source tree.
- **Menu item destinations** — CMS/catalog/event menu items now publish `is_clickable`
  alongside `custom_url`, returning `custom_url: null` and `is_clickable: false` instead
  of a broken/omitted URL when the CMS defines no valid destination.
- **`BlockTreeResolver`** — repeated list/detail/facet/form lookups within one page
  resolve (the same source, locale and query appearing on more than one block) are now
  memoized per request instead of issuing a duplicate upstream/DB call for each
  occurrence. Response shape is unchanged.
- **`GET /ready`** — now probes only the Hub instead of also checking the four
  read-only database connections; `/health` keeps the database probes for explicit
  diagnostics. Avoids consuming scarce hosting processes/DB connections when an
  orchestrator polls readiness every few seconds.
- **`AdminCmsBootstrapSource`** — form options, menu editor and site identity
  bootstraps drop the CMS Domain HTTP fallback and use only their direct SQL
  projections; a missing `cms_readonly` connection now fails closed instead of
  falling back to the old multi-request HTTP path.
- **`PageResolver`/`PublicReadPageReader`** — resolving a page now batches every
  equivalent localized path candidate (slug aliases) into one set-based `showAny()`
  read instead of one sequential `show()` call per candidate. Response shape is
  unchanged.
- **`PublicReadFormReader`** — form field translations are now resolved with one
  bounded `whereIn` query instead of up to three queries per field. Response
  shape is unchanged.
- **`BlockInstanceSerializer`** — block instance data/config/schema are now
  decoded once per instance instead of on every pass over the instance list, and
  the block instance projection selects only the columns it uses. Response shape
  is unchanged.

### Fixed

- **`GET /me/admin-file-usages`** — the file-usage source now consumes the Hub's authoritative
  `usage-snapshot` endpoint instead of issuing a second CMS query to reconstruct the same
  context; per-source health and completeness now come straight from the Hub, and the seam's
  stable deduplication is preserved.
- **`CmsWorkspaceProjectionQuery`** — block types are now filtered by the owner's
  capability (`supports_pages`/`supports_entries`), and the languages, collections,
  pages, entries and forms catalogs are only projected when the caller holds the
  matching `cms.*.read` permission — previously every auxiliary catalog was always
  returned regardless of the caller's actual read scope.
- **`PublicReadNavigationReader`** — the public navigation seam now keys menus by
  `menu_key` instead of matching a fixed `location` whitelist (`header`/`main`/
  `footer`/`legal`); an editor-defined `location` value no longer drops the menu
  from the public response.
