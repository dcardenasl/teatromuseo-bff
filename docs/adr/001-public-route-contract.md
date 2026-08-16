# ADR-001: Public route contract between Web and BFF

## Status

Accepted — 2026-08-15

## Context

The Web and BFF are independent applications. The Web owns the canonical
visitor-facing URL policy, while the BFF must recognize those paths when it
resolves `page-resolve` requests. Both applications therefore need a local
adapter, but silent drift between their locale segments would produce broken
language links or 404s.

## Decision

Keep the two adapters independent at runtime:

- Web: `App\Support\PublicPaths`.
- BFF: `App\PublicRead\Page\PublicPagePaths`.

Each adapter exports the same versioned, deterministic route contract. The Web
stores the canonical artifact in
`teatromuseo-web/docs/contracts/public-routes.json`; both repositories' CI
check the local export, and a cross-repository job compares the two exports.
The checkouts use the shared `dev` integration branch. A route change is
therefore a coordinated Web+BFF change, without adding a runtime HTTP call,
shared filesystem, Composer path repository or third deployment artifact.

## Consequences

This preserves repository and deployment independence while making drift a
merge-time failure. It does not remove the adapter seam: the BFF still needs
its own incoming-path resolver and the Web still owns final URL generation.
Adding a third package would add release/versioning overhead without being
consumed by the production runtime; revisit that option only if a third
application becomes a route-policy consumer.
