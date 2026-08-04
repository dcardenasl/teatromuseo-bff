# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- **Runtime dependency** — upgraded `dcardenasl/ci4-api-core` to `v1.1.1`.
- **Local deployment hygiene** — ignored local `.deploy` tooling so deployment helpers are not
  accidentally included in the BFF source tree.
