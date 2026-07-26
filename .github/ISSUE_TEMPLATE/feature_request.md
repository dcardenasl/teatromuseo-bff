---
name: Feature request
about: Propose a new capability for ci4-api-starter
title: '[Feature] '
labels: enhancement
assignees: ''
---

## Problem statement

<!-- What pain are we solving? Who is affected? -->

## Proposed solution

<!-- High-level description. If you have a preferred shape (new endpoint, new filter, new DTO field), say so. -->

## Architectural fit

- Does this fit the **DTO-first** layered architecture (`Controller → RequestDTO → Service → Model → ResponseDTO`)?
- New endpoint(s)? List route + method:
- New permission codes (dot-separated)?
- New tables / migrations?
- New ADR needed?

## Alternatives considered

<!-- What else did you think about? Why is this the right path? -->

## Out of scope

<!-- What this proposal explicitly does not touch. Helps reviewers stay focused. -->

## Definition of done

- [ ] Feature tests cover the happy path
- [ ] Permission gating, if any, is locked by a regression test
- [ ] OpenAPI spec regenerated (`php spark swagger:generate`)
- [ ] CHANGELOG `[Unreleased]` entry
- [ ] CLAUDE.md updated if architecture or commands change
- [ ] ADR added if a non-trivial decision is made
