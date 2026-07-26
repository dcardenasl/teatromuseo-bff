# GitHub Actions Workflows

This directory contains automated workflows for continuous integration and security scanning.

## Available Workflows

### 1. CI Workflow (`ci.yml`)

**Purpose:** Automated testing on every push and pull request

**Triggers:**
- Push to `main` or `cc` branches
- Pull requests to `main` branch

**What it does:**
- Tests on PHP 8.2 and 8.3
- Sets up MySQL 8.0 test database
- Installs dependencies with composer
- Generates secure keys automatically
- Runs full PHPUnit test suite (49 tests)
- Validates composer.json

**Average runtime:** ~2-3 minutes per PHP version

### 2. Security Scan Workflow (`security.yml`)

**Purpose:** Automated security vulnerability scanning

**Triggers:**
- Push to `main` branch
- Pull requests to `main` branch
- Every Monday at 9:00 AM UTC (scheduled)

**What it does:**
- Checks for known vulnerabilities with `composer audit`
- Scans for hardcoded secrets in code
- Verifies .env files are not committed
- Validates .gitignore configuration
- Checks for outdated dependencies

**Average runtime:** ~1-2 minutes

## Workflow Status

View workflow status:
- Click the **Actions** tab in your repository
- See all workflow runs and their status
- Click on any run for detailed logs

## Testing Workflows Locally

You can test workflows locally using [act](https://github.com/nektos/act):

```bash
# Install act (macOS)
brew install act

# Run CI workflow locally
act push

# Run specific job
act -j test

# Run with specific event
act pull_request
```

**Note:** Local testing with `act` may not perfectly replicate GitHub's environment, especially for services like MySQL.

## Customizing Workflows

### Adding PHP Versions

Edit `ci.yml` matrix:

```yaml
strategy:
  matrix:
    php-version: ['8.2', '8.3', '8.4']  # Add 8.4
```

### Adding More Tests

The workflow automatically runs all tests in the `tests/` directory. Just add your test files there.

### Changing Triggers

Edit the `on:` section:

```yaml
on:
  push:
    branches: [ main, develop ]  # Add develop branch
  pull_request:
    branches: [ main ]
```

### Adding Coverage Reports

Add coverage step to `ci.yml`:

```yaml
- name: Setup PHP
  uses: shivammathur/setup-php@v2
  with:
    php-version: ${{ matrix.php-version }}
    extensions: mysqli, mbstring, intl, json, xdebug
    coverage: xdebug

- name: Run tests with coverage
  run: vendor/bin/phpunit --coverage-clover coverage.xml

- name: Upload coverage to Codecov
  uses: codecov/codecov-action@v3
  with:
    files: ./coverage.xml
```

## Workflow Best Practices

1. **Keep workflows fast**
   - Use caching for dependencies
   - Run only necessary tests
   - Use matrix strategy for parallel jobs

2. **Fail fast**
   - Stop on first error
   - Use `continue-on-error: false` for critical checks

3. **Secure secrets**
   - Never hardcode credentials in workflows
   - Use GitHub Secrets for sensitive data
   - Use environment-specific secrets

4. **Monitor workflow usage**
   - Check Actions usage in Settings → Billing
   - Optimize to stay within free tier (2000 min/month)

5. **Keep dependencies updated**
   - Use Dependabot to update actions
   - Pin action versions with @v2, @v3, etc.

## Troubleshooting

### Workflow not triggering

**Check:**
- Branch name matches trigger configuration
- Workflow file is in `.github/workflows/`
- YAML syntax is valid
- Repository has Actions enabled

### Tests fail in CI but pass locally

**Common causes:**
- PHP version differences
- Database configuration
- Environment variables
- Timezone differences

**Solutions:**
- Test locally with same PHP version
- Check workflow logs for error messages
- Verify .env.example is up to date

### Composer install fails

**Common causes:**
- Invalid composer.json
- Version conflicts
- Missing PHP extensions

**Solutions:**
- Run `composer validate --strict`
- Check required PHP version in composer.json
- Update composer.lock locally

### MySQL connection fails

**Check:**
- Service health check passes
- Credentials match in environment config
- Database name is set correctly
- Port mapping is correct (3306)

## Getting Help

- [GitHub Actions Documentation](https://docs.github.com/en/actions)
- [Workflow Syntax Reference](https://docs.github.com/en/actions/reference/workflow-syntax-for-github-actions)
- [PHPUnit on GitHub Actions](https://github.com/shivammathur/setup-php)

For project-specific CI/CD documentation, see [CI_CD.md](../../CI_CD.md) in the root directory.
