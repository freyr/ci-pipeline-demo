# CI Pipeline Demo

A minimal but realistic repository demonstrating a trunk-based CI/CD pipeline. Observe and interact with the full workflow: feature PRs, conditional base image builds, CalVer releases, tag-triggered image pipelines, and the hotfix flow.

Key design principles:

- **Trunk-based development** — permanent `main` branch, short-lived feature branches, squash merge only
- **QA workflows** — conditional CI, Docker image caching, split test jobs
- **CalVer versioning** — `YYYY.0M.0D.BUILD` tags, zero-input release workflow, tag-triggered builds

## Repository Structure

```
ci-pipeline-demo/
├── .github/workflows/
│   ├── ci.yml                # Feature/QA workflow (PR-triggered)
│   ├── release.yml           # CalVer tag factory (manual dispatch)
│   └── deploy.yml            # Release pipeline (reusable + manual dispatch)
├── docker/
│   └── base/
│       └── Dockerfile        # Multi-stage: prod + dev targets (Alpine)
├── app/
│   └── Dockerfile            # App image (FROM base)
├── src/
│   ├── Greeter.php           # Domain layer — single class
│   └── Runner.php            # Application layer — depends on Greeter
├── tests/
│   └── GreeterTest.php       # Single passing test
├── composer.json              # PHP 8.4 + PHPStan, CS-Fixer, PHPUnit, Deptrac
├── phpstan.neon               # Level 9
├── .php-cs-fixer.php          # @Symfony rules
├── deptrac.yaml               # 2-layer: Domain, Application
├── phpunit.xml                # Single unit suite
└── WALKTHROUGH.md             # Interactive hotfix scenario
```

## Workflows

### 1. CI (`ci.yml`) — PR-triggered

Fires on every PR targeting `main` (and via `workflow_dispatch` for manual runs).

| Job | Purpose | Condition |
|-----|---------|-----------|
| `detect-changes` | Runs `dorny/paths-filter` to check `docker/base/**` and `src/**`, `app/**`, `composer.*` | Always |
| `build-base` | Builds dev base image, pushes as `dev-<sha7>` to GHCR | Only if base changed |
| `quality` | PHP-CS-Fixer, PHPStan, Deptrac | Always (skips if build-base failed) |
| `test` | PHPUnit | Always (skips if build-base failed) |
| `build-app` | Builds prod app image, pushes as `<branch-slug>` to GHCR | Only if PR has `build-app` label, after quality + test pass |

Concurrency: cancel-in-progress per PR.

### 2. Release (`release.yml`) — manual dispatch

Available on `main` and `hotfix/*` branches. Zero inputs.

1. Computes the next CalVer tag (`YYYY.0M.0D.BUILD`) from existing tags.
2. Creates and pushes the git tag.
3. Calls the Deploy workflow as a downstream job — all deploy jobs appear inside the Release run.

### 3. Deploy (`deploy.yml`) — reusable + manual dispatch

Called by Release as a reusable workflow (`workflow_call`). Also available as a standalone manual dispatch.

| Job | Purpose | Dependencies |
|-----|---------|--------------|
| `build-base-images` | Build prod + dev base images, push with CalVer + `latest` tags | None |
| `build-app-image` | Build app image `FROM base-prod:<calver>` | Waits for base |
| `release-notes` | Creates GitHub release with auto-generated notes | None (parallel) |

### Trigger Map

```
Push to branch with open PR  →  CI workflow (tests + quality)
Push to main (PR merge)      →  Nothing (tests already passed)
Push to hotfix/*              →  Nothing
Click "Run workflow" button   →  Release workflow (creates tag → calls Deploy)
Manual deploy.yml dispatch   →  Deploy workflow (standalone)
```

## Docker Images

| Image | GHCR Path | Source |
|-------|-----------|--------|
| Base (prod) | `ghcr.io/freyr/ci-pipeline-demo/demo-base-prod` | `docker/base/Dockerfile` target `prod` |
| Base (dev) | `ghcr.io/freyr/ci-pipeline-demo/demo-base-dev` | `docker/base/Dockerfile` target `dev` |
| App | `ghcr.io/freyr/ci-pipeline-demo/demo-app` | `app/Dockerfile` |

### Tag Convention

| Tag | Mutable? | Who pushes it |
|-----|----------|---------------|
| `YYYY.0M.0D.BUILD` | Immutable | Release pipeline (tag push) |
| `latest` | Floating | Release pipeline (tag push) |
| `dev-<sha7>` | Immutable | CI workflow (PR with base changes) |

## Branch Protection Setup

After creating the repository, configure branch protection on `main`:

```bash
# Set repository defaults: squash-only merge, auto-delete branches
gh repo edit freyr/ci-pipeline-demo \
  --delete-branch-on-merge \
  --enable-squash-merge \
  --disable-merge-commit \
  --disable-rebase-merge \
  --squash-merge-commit-title PR_TITLE

# Create branch protection rule
gh api repos/freyr/ci-pipeline-demo/branches/main/protection \
  --method PUT \
  --input - <<'EOF'
{
  "required_status_checks": {
    "strict": true,
    "contexts": ["quality", "test"]
  },
  "enforce_admins": true,
  "required_pull_request_reviews": {
    "required_approving_review_count": 1,
    "dismiss_stale_reviews": true
  },
  "restrictions": null,
  "required_linear_history": true
}
EOF
```

## Getting Started

```bash
# Install dependencies
composer install

# Run all quality checks
composer quality

# Run individual checks
composer test       # PHPUnit
composer phpstan    # Static analysis (level 9)
composer cs-check   # Code style (Symfony rules)
composer deptrac    # Architecture layer checks
```

## Interactive Walkthrough

See [WALKTHROUGH.md](WALKTHROUGH.md) for step-by-step scenarios covering normal feature flow, conditional base image builds, the hotfix process, and CalVer auto-increment.
