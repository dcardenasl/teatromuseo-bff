# ADR-002: CMS scoped-access authorization is duplicated in BFF SQL, not proxied to CMS Domain

## Status

Accepted — 2026-08-20

## Context

The CMS Domain is gaining row-level editorial authorization: a user with a *scoped* capability
(`cms.pages.scoped-read`, `cms.entries.scoped-write`) can only act on pages/collections they hold an
explicit grant for, decided by `CmsResourceAccessPolicy` against `cms_page_user_access` /
`cms_collection_user_access`. The BFF's `AdminCmsWorkspaceSource` / `CmsWorkspaceProjectionQuery` compose
authenticated Admin projections (workspace, bootstrap) by reading `cms_readonly` directly with raw SQL —
today that SQL only knows "does the caller have the global permission," expressed as an all-or-nothing
per-section switch, never a per-row decision.

Making these projections scope-aware for a scoped caller means either:

1. Add `EXISTS (SELECT 1 FROM cms_page_user_access ...)` predicates to the BFF's own composed SQL,
   independently re-implementing the same grant-matching rule the CMS Domain's policy already implements.
2. For scoped callers only, stop composing SQL and call the CMS Domain's own (now scope-aware) REST
   endpoints over HTTP, composing the aggregate response from those authoritative results instead.

Option 2 would be a single source of truth for the authorization decision, but it reopens exactly the
BFF→domain HTTP-per-projection shape that the direct-SQL `AdminRead`/`PublicRead` seams were built to
replace for performance (see `CLAUDE.md`'s "Fundamental query rule": SQL first, avoid PHP-side
joins/merges of separately-fetched sources). It would also make the BFF's authorization behavior
conditional on caller type (SQL for global callers, HTTP for scoped callers) — a second, asymmetric code
path to test and reason about, not a one-time cost.

This is not a new kind of trade-off for this codebase: `AdminRead` and `PublicRead` already each own their
own query/permission logic independently, by explicit design (see the "Cross-seam reuse" note in
`CLAUDE.md` — file-URL resolution is deliberately shared, but query/permission logic deliberately is not).

## Decision

The BFF's CMS `AdminRead` SQL gains its own `EXISTS`-based scoped-access predicates, duplicating the grant
rule already enforced by the CMS Domain's `CmsResourceAccessPolicy`, rather than proxying scoped callers to
CMS Domain HTTP endpoints. Both copies must:

- read the same two tables (`cms_page_user_access`, `cms_collection_user_access`) and the same
  `access_level` semantics (`write` implies `read`);
- be covered by a documented, explicit cross-repo alignment test (or at minimum a shared fixture/table
  contract test on each side) — because unlike drift in a read-model *shape* (tolerated elsewhere per
  ADR-010 in `ci4-api-core`'s reader-duplication precedent), drift in an *authorization predicate* is a
  security bug, not a cosmetic inconsistency;
- be re-scoped in project planning as comparable effort to the CMS Domain's own enforcement phase — not a
  lighter follow-on.

## Consequences

**Positive**

- Consistent with the established `AdminRead`/`PublicRead` architecture: no new HTTP dependency, no new
  conditional code path by caller type, `cms_readonly` stays SELECT-only.
- No regression on the direct-SQL performance property the seam exists for.

**Negative**

- The grant-matching rule now has two independent implementations (CMS Domain PHP/SQL, BFF raw SQL) that
  must be kept in lockstep by test discipline, not by the compiler or a shared package. A future change to
  grant semantics (e.g. adding a third access level) must land in both places in the same change, or the
  BFF will silently under- or over-authorize a scoped caller relative to the CMS Domain's own endpoints.
- No single place to read "the" authorization rule — a reader must know both `CmsResourceAccessPolicy` and
  `CmsWorkspaceProjectionQuery` exist and agree.
