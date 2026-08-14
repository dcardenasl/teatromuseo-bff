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

### Changed

- **Runtime dependency** — upgraded `dcardenasl/ci4-api-core` to `v1.1.1`.
- **Local deployment hygiene** — ignored local `.deploy` tooling so deployment helpers are not
  accidentally included in the BFF source tree.
