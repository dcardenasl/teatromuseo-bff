# Deployment helper

This repository uses the versioned helper in `scripts/deploy_ftp.py` through
the compatibility entrypoint `.deploy/deploy.py`. Keep credentials and local
state out of Git; the helper, wrapper, template and this document are
versioned.

## Configure

```bash
cp .deploy/.env.deploy.example .deploy/.env.deploy
chmod 600 .deploy/.env.deploy
```

FTPS is the default and certificate verification is enabled. Plain FTP or
disabled certificate verification require an explicit temporary
`ALLOW_INSECURE_FTP=true` opt-in.

Set `DEPLOY_HEALTHCHECK_URL` to an HTTPS health endpoint when the application
has one. An empty value skips the post-deploy check.

## Run

```bash
python3 .deploy/deploy.py --dry-run
python3 .deploy/deploy.py --yes
```

Useful options:

- `--only PATH` limits the upload to one or more changed repository-relative paths.
- `--bootstrap` includes `composer.json` and `composer.lock` when the host provisions runtime dependencies.
- `--prune` explicitly removes remote non-runtime files absent locally; it requires a confirmation or `--yes` and FTP `MLSD` support.
- `--rollback RELEASE_ID` restores the backup created before a deploy.

Every deploy stores a local rollback manifest under `.deploy/.rollback/` and
updates `.deploy/.last_ftp_deploy` only after upload and health-check success.
The helper never deletes remote files unless `--prune` is supplied.

Application-specific migrations, permission synchronization and cache
invalidation remain explicit post-deploy operations and are not executed over
FTP.
