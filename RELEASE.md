# Release procedure — teatromuseo-bff

Git tags and `.github/workflows/release.yml` publish the GitHub Release. The
runtime is deployed separately to cPanel through the shared versioned helper;
the release workflow does not upload production files.

## Pre-flight

```bash
git status --porcelain
composer quality
php spark swagger:generate
git diff --exit-code public/swagger.json
```

Configure `.deploy/.env.deploy` from its example and run `chmod 600` on it.
The helper defaults to verified FTPS and runs `DEPLOY_HEALTHCHECK_URL` after
upload if it is configured.

## Deploy

```bash
python3 .deploy/deploy.py --dry-run
python3 .deploy/deploy.py --yes
```

Use `--bootstrap` when the target host needs the Composer manifests for a
separate runtime provisioning step:

```bash
python3 .deploy/deploy.py --bootstrap --yes
composer install --no-dev --optimize-autoloader
```

The helper stores a release backup ID before overwriting files. Roll back with:

```bash
python3 .deploy/deploy.py --rollback <release-id>
```

`--prune` is opt-in and must be reviewed before deleting remote files. Database
migrations, permission synchronization and cache invalidation remain explicit
post-deploy operations.
