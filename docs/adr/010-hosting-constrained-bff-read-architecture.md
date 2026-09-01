# ADR-010: Hosting-constrained direct-read architecture for `AdminRead`/`PublicRead`

## Status

Accepted — retroactively documented 2026-08-20 (decision made and shipped 2026-08-13/17; this file
formalizes it after `teatromuseo-bff/TASKS.md` was found to cite "ADR-010" in two places with no backing
file).

## Context

`docs/audits/2026-08-13-endpoints-compuestos-y-limite-real-de-hosting.md` confirmed, against cPanel's live
resource panel for `beta.teatromuseo.cl`: **5 concurrent Entry Processes** for the entire hosting account
(all PHP-FPM/CGI/cron/shell combined, shared across all 7 apps on the account, not per-subdomain), with
**277 Entry Process limit hits in 24 hours** logged by the host. Memory, NPROC and CPU all had wide margin
in the same panel — the ceiling is specifically simultaneous PHP processes, not compute.

Under the pre-BFF architecture, a single public page view could trigger 4+ internal HTTP calls (CMS,
Catalog, Event, plus Hub for JWT introspection on admin routes) — each one a *separate* PHP process on the
same account, competing for the same 5-process ceiling. A single visitor could consume 2-3 processes just
from cascading internal calls. This made the hosting ceiling, not query design or code speed, the dominant
bottleneck for cold-load latency (confirmed in the same-day audit
`docs/audits/2026-08-13-auditoria-carga-fria-web-domains.md`: 5 round-trips for a static page, 7-8 for one
with a dynamic listing).

## Decision

`teatromuseo-bff` reads directly against the four domains' databases (`cms_readonly`, `catalog_readonly`,
`event_readonly`, `hub_readonly` — SELECT-only named connections) instead of proxying every read over HTTP
to each domain's own API. This applies to both:

- `app/PublicRead/**` — the unauthenticated, app-key-gated public read seam consumed by `teatromuseo-web`
  and `teatromuseo-totem-ci4`.
- `app/AdminRead/**` — the authenticated, permission-filtered seam consumed by `teatromuseo-admin`'s
  composite dashboards, analytics, and CMS workspace/bootstrap projections.

Each seam owns its own query and permission logic independently — `AdminRead` does not call `PublicRead`
internals, and neither proxies to the domain's own HTTP API for a read it can express as a bounded,
permission-aware `SELECT`. This is the precedent later decisions in this codebase point back to when they
choose to duplicate a bounded amount of read/authorization logic instead of adding another HTTP hop (see
`teatromuseo-bff/docs/adr/002-cms-scoped-access-duplicated-not-proxied.md` for the most recent instance:
the CMS scoped-access authorization predicate).

A composed HTTP call still exists where the seam is not SELECT-only or where a single domain already owns
the full composition — see `AdminCmsWizardSource`'s documented exception in `CLAUDE.md` (calls the CMS
Domain's existing compound wizard endpoint because the dynamic block configuration composition is already
owned there, not duplicable as a bounded SQL projection).

## Consequences

**Positive**

- One PHP process (the BFF) replaces up to 4 for a single page/dashboard load, directly relieving the
  Entry Process ceiling that was empirically confirmed as the dominant bottleneck.
- Both seams stay bounded, SELECT-only, and testable against a real MySQL fixture (`composer
  test:integration`), without a shared Composer package between the BFF and the domains (see
  `docs/plan/2026-08-13-plan-bff-completo.md`, decision #5: a single consumer doesn't justify one).

**Negative**

- Each seam re-implements query/permission logic that already exists, in a different form, inside its
  source domain — a domain schema or authorization change can silently drift out of sync with the BFF's
  copy if the cross-repo coordination note in each domain's `CLAUDE.md` ("Cross-repo public-read contract")
  isn't followed.
- `cms_readonly`/`catalog_readonly`/`event_readonly`/`hub_readonly` being SELECT-only is enforced by MySQL
  user grants (infrastructure) and by `StatelessArchitectureTest` (code), not by the connection type
  itself — a regression here is possible if either enforcement point is weakened without the other
  noticing.
- The ceiling this decision responds to is a hosting-tier constraint (5 Entry Processes on the current
  plan), not a permanent architectural fact — if the hosting plan changes, this decision should be
  revisited rather than assumed to still be load-bearing.

## Pointer

- `docs/audits/2026-08-13-endpoints-compuestos-y-limite-real-de-hosting.md` — the Entry Process ceiling
  evidence this ADR formalizes.
- `docs/plan/2026-08-13-plan-bff-completo.md` — the execution plan (`BFF-DB-01..10`) that shipped this.
- `docs/adr/002-cms-scoped-access-duplicated-not-proxied.md` — the most recent decision built on this
  precedent.
- `teatromuseo-bff/CLAUDE.md` — "Direct read seams" and "Fundamental query rule" sections; the living
  contract this ADR explains the origin of.
