# CI Pipeline Demo

A minimal but realistic repository demonstrating **Scaled TBD** (GitHub Flow with pre-merge QA gating) CI/CD pipelines. Observe and interact with the full workflow: feature PRs, conditional base image builds, label-triggered QA images, base version enforcement, and CalVer releases.

Key design principles:

- **Scaled TBD** — short-lived feature branches (3-6 days), squash merge to `main`
- **Separate CI and QA images** — dev image for tests, prod image for QA/release
- **Base image versioning** — immutable `X.Y.Z` tags, single source of truth in app Dockerfile
- **Label-triggered QA** — `app-image` label triggers prod image build for e2e testing
- **CalVer releases** — `vYYYY-MM-DD.$seq` tags, zero-input release workflow

## Repository Structure

```
ci-pipeline-demo/
├── .github/workflows/
│   ├── ci-branch.yml        # Branch CI (PR-triggered: tests + static analysis)
│   ├── qa-branch.yml        # Branch QA (label-triggered: prod image build)
│   ├── promote-base.yml     # Base promotion (post-merge to main)
│   ├── release.yml          # Release pipeline (CalVer tag + prod image + GHCR)
│   └── cleanup.yml          # Branch image cleanup (PR close)
├── docker/
│   ├── base/
│   │   └── Dockerfile       # Base image: prod target + dev inherits prod
│   └── app/
│       └── Dockerfile       # App image: ci, prod, dev targets
│                             # ARG BASE_PROD_TAG=8.4.1 ← single source of truth
├── src/
│   ├── Greeter.php          # Domain layer
│   └── Runner.php           # Application layer
├── tests/
│   └── GreeterTest.php      # Unit tests
├── composer.json             # PHP 8.4 + PHPStan, CS-Fixer, PHPUnit, Deptrac
├── phpstan.neon              # Level 9
├── .php-cs-fixer.php         # @Symfony rules
├── deptrac.yaml              # 2-layer: Domain, Application
├── phpunit.xml               # Unit suite
├── .dockerignore             # Excludes .git, vendor, etc. from app build context
└── WALKTHROUGH.md            # Step-by-step scenarios
```

## Process Model

```
feature branch ──→ CI pipeline (auto) ──→ QA pipeline (label) ──→ PR merge ──→ main ──→ release
     │                    │                       │                               │           │
   2-3 days          static + unit           prod image                    base promotion   prod image
                      dev image               for QA                      (if base changed)  + release notes
```

## Workflows

### 1. Branch CI (`ci-branch.yml`)

Fires on every PR push targeting `main`. Fast feedback loop.

| Job | Purpose | Condition |
|-----|---------|-----------|
| `detect-changes` | `dorny/paths-filter` checks `docker/base/**` | Always |
| `enforce-base-version` | Verify `BASE_PROD_TAG` bumped + version not published | Only if base changed |
| `build-base-dev` | Build `demo-base-dev:ci-$slug` | Only if base changed + enforcement passed |
| `build-app-ci` | Build `demo-app-dev:ci-$slug` (CI image with all deps + tools) | Always |
| `phpunit` | Run unit tests | After CI image built |
| `phpstan` | Static analysis | After CI image built |
| `cs-fixer` | Code style check | After CI image built |
| `deptrac` | Architecture layer check | After CI image built |

Test jobs run **in parallel** inside the CI image container — no checkout or `composer install` needed.

### 2. Branch QA (`qa-branch.yml`)

Fires when `app-image` label is present on a PR + on subsequent pushes.

| Job | Purpose | Condition |
|-----|---------|-----------|
| `check-label` | Gate: verify `app-image` label exists | Always |
| `detect-changes` | Check base file changes | After label check |
| `build-base-prod` | Build `demo-base-prod:ci-$slug` (temporary tag) | Only if base changed |
| `build-app-prod` | Build `demo-app-prod:qa-$slug` | Always |
| `comment` | Post image tag as PR comment | After build |

### 3. Base Promotion (`promote-base.yml`)

Fires on push to `main` when `docker/base/**` changed. Builds permanent base images:

- `demo-base-dev:latest` — default for other branches' CI pipelines
- `demo-base-prod:X.Y.Z` — immutable version, used by QA/release pipelines

### 4. Release (`release.yml`)

Manual `workflow_dispatch` — select the tag in the "Use workflow from" dropdown. The developer creates and pushes a git tag first, then runs this workflow on it.

| Job | Purpose |
|-----|---------|
| `validate` | Verify the workflow is running on a tag (not a branch) |
| `build` | Parse `BASE_PROD_TAG`, check base exists, build `demo-app-prod:$tag` + `:latest` |
| `release-notes` | `gh release create` with auto-generated notes |

### 5. Cleanup (`cleanup.yml`)

Fires on PR close. Deletes branch-scoped images from GHCR:
- `demo-base-dev:ci-$slug`
- `demo-base-prod:ci-$slug`
- `demo-app-dev:ci-$slug`
- `demo-app-prod:qa-$slug`

### Trigger Map

```
PR push to main               →  ci-branch.yml (tests + static analysis)
PR with `app-image` label     →  qa-branch.yml (prod image for QA)
Merge to main (base changed)  →  promote-base.yml (base dev:latest + base prod:X.Y.Z)
Manual: git tag + git push     →  (nothing yet — tag exists in repo)
Manual: Run workflow on tag    →  release.yml (validate ref is tag → app prod image → release notes)
PR closed                      →  cleanup.yml (delete branch-scoped images)
```

## Docker Images

### Image Matrix

| Image | Dockerfile target | Base image | Tag format | Built by |
|-------|-------------------|------------|------------|----------|
| `demo-base-prod` | `prod` | — | `X.Y.Z` (immutable) | promote-base / release (safety net) |
| `demo-base-dev` | `dev` (inherits prod) | — | `latest` / `ci-$slug` | promote-base / ci-branch |
| `demo-app-dev` | `ci` | `demo-base-dev` | `ci-$slug` | ci-branch |
| `demo-app-prod` | `prod` | `demo-base-prod` | `qa-$slug` / `vYYYY-MM-DD.$seq` / `latest` | qa-branch / release |

### GHCR Paths

| Image | Path |
|-------|------|
| Base (prod) | `ghcr.io/freyr/ci-pipeline-demo/demo-base-prod` |
| Base (dev) | `ghcr.io/freyr/ci-pipeline-demo/demo-base-dev` |
| App (dev) | `ghcr.io/freyr/ci-pipeline-demo/demo-app-dev` |
| App (prod) | `ghcr.io/freyr/ci-pipeline-demo/demo-app-prod` |

### Tag Convention

| Image type | Context | Tag | Mutable? | Retention |
|------------|---------|-----|----------|-----------|
| base prod | Main merge | `X.Y.Z` | No (immutable) | Keep all |
| base dev | Main merge | `latest` | Yes | Always current |
| base dev | Branch CI | `ci-$slug` | Yes | Deleted on PR close |
| base prod | Branch QA | `ci-$slug` | Yes | Deleted on PR close |
| app dev | Branch CI | `ci-$slug` | Yes | Deleted on PR close |
| app prod | Branch QA | `qa-$slug` | Yes | Deleted on PR close |
| app prod | Release | `vYYYY-MM-DD.$seq` | No (immutable) | Keep last N |
| app prod | Release | `latest` | Yes | Always current |

## Base Image Versioning

Format: `X.Y.Z` where `X.Y` = PHP version, `Z` = manual sequence.

**Single source of truth:** `ARG BASE_PROD_TAG=8.4.1` in `docker/app/Dockerfile`.

CI enforcement (on PRs with base changes):
1. **Bump check** — pipeline fails if `docker/base/**` changed but `BASE_PROD_TAG` was not bumped
2. **Duplicate check** — pipeline fails if the new version already exists in GHCR

| Change type | Bump | Example |
|-------------|------|---------|
| php.ini tuning, extension added | Sequence | `8.4.1` → `8.4.2` |
| PHP minor version upgrade | Reset | `8.4.5` → `8.5.0` |

## Branch Protection Setup

```bash
# Set repository defaults
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
    "contexts": ["phpunit", "phpstan", "cs-fixer", "deptrac"]
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

# Create the app-image label
gh label create app-image \
  --description "Build and push prod image from PR for QA" \
  --color 0E8A16
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

See [WALKTHROUGH.md](WALKTHROUGH.md) for step-by-step scenarios covering:

1. Feature branch QA and merge (app code only)
2. Feature branch QA (app Dockerfile changes)
3. Feature branch QA (base Dockerfile changes)
4. Release — no base image change (manual tag + workflow dispatch)
5. Release — base image change exists (manual tag + workflow dispatch)
