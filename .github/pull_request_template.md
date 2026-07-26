## Summary

- What changed?
- Why was this needed?

## Scope

- [ ] API behavior change
- [ ] Database migration change
- [ ] Security-sensitive change
- [ ] Observability/monitoring change
- [ ] Documentation updated

## Validation

- [ ] `composer cs-check`
- [ ] `composer phpstan`
- [ ] `composer arch-drift`
- [ ] `vendor/bin/phpunit --configuration phpunit.xml --no-coverage --testdox`

## Architecture Drift

- [ ] Documented any `composer arch-drift` failures or guardrail violations with references to `docs/architecture/DRIFT_GUIDE.md`.

## Checklist

- [ ] No secrets/credentials committed
- [ ] Error responses do not expose sensitive internals
- [ ] New/changed endpoints documented (`swagger.json` regenerated if needed)
- [ ] Backward compatibility considered (or breaking changes noted)

## Notes for Reviewers

- Risks:
- Rollback plan:
- Post-deploy checks:
