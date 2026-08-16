# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

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

### Changed

- **Runtime dependency** — upgraded `dcardenasl/ci4-api-core` to `v1.1.1`.
- **Local deployment hygiene** — ignored local `.deploy` tooling so deployment helpers are not
  accidentally included in the BFF source tree.
- **Menu item destinations** — CMS/catalog/event menu items now publish `is_clickable`
  alongside `custom_url`, returning `custom_url: null` and `is_clickable: false` instead
  of a broken/omitted URL when the CMS defines no valid destination.
