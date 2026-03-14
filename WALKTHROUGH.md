# Interactive Walkthrough

Five scenarios that demonstrate the full CI/CD pipeline. Each scenario is self-contained — run any of them independently, or follow them in order.

## Prerequisites

- Repository cloned and `composer install` run successfully
- GitHub CLI (`gh`) authenticated with push access to `freyr/ci-pipeline-demo`
- Branch protection and `app-image` label configured (see README.md)
- Base images bootstrapped: `demo-base-dev:latest` and `demo-base-prod:8.4.1` exist in GHCR

---

## Scenario 1: Feature Branch QA and Merge (App Code Only)

No Docker file changes — just application code. This is the most common flow.

### What will happen

- **CI:** builds `demo-app-dev:ci-$slug`, runs PHPUnit + PHPStan + CS-Fixer + Deptrac in parallel
- **QA:** builds `demo-app-prod:qa-$slug` (only if `app-image` label applied)
- **Main:** no action (no base changes)
- **Cleanup:** deletes branch-scoped images on PR close

### Steps

1. **Create a feature branch:**

   ```bash
   git checkout main && git pull
   git checkout -b feat/add-farewell
   ```

2. **Add a method to `src/Greeter.php`:**

   ```php
   public function farewell(string $name): string
   {
       return sprintf('Goodbye, %s!', $name);
   }
   ```

   Add a test in `tests/GreeterTest.php`:

   ```php
   #[Test]
   public function itSaysFarewell(): void
   {
       $greeter = new Greeter();
       self::assertSame('Goodbye, World!', $greeter->farewell('World'));
   }
   ```

3. **Verify locally:**

   ```bash
   composer quality
   ```

4. **Push and open a PR:**

   ```bash
   git add -A && git commit -m "Add farewell method"
   git push -u origin feat/add-farewell
   gh pr create --title "Add farewell method" --body "Demonstrates normal feature flow."
   ```

5. **Observe CI Pipeline (`ci-branch.yml`):**

   ```bash
   gh run list --limit 1
   gh run view <run-id>
   ```

   Expected job sequence:
   - `detect-changes` → `base-changed: false`
   - `enforce-base-version` → **skipped**
   - `build-base-dev` → **skipped**
   - `build-app-ci` → builds `demo-app-dev:ci-feat-add-farewell`
   - `phpunit`, `phpstan`, `cs-fixer`, `deptrac` → run in parallel inside CI image

6. **Apply `app-image` label to trigger QA build:**

   ```bash
   gh pr edit --add-label app-image
   ```

7. **Observe QA Pipeline (`qa-branch.yml`):**

   - `build-base-prod` → **skipped** (no base changes)
   - `build-app-prod` → builds `demo-app-prod:qa-feat-add-farewell`
   - `comment` → posts image tag on PR

8. **Merge the PR.** After CI passes, squash merge via GitHub UI.

9. **Observe cleanup (`cleanup.yml`):**

   Deletes `demo-app-dev:ci-feat-add-farewell` and `demo-app-prod:qa-feat-add-farewell` from GHCR.

### What to verify

- [ ] `enforce-base-version` and `build-base-dev` were **skipped** in CI
- [ ] `phpunit`, `phpstan`, `cs-fixer`, `deptrac` ran in parallel
- [ ] QA image was built only after `app-image` label was applied
- [ ] PR comment shows the QA image tag
- [ ] Trunk Pipeline did **not** run after merge (no base changes)
- [ ] Cleanup deleted branch-scoped images

---

## Scenario 2: Feature Branch QA (App Dockerfile Changes)

Changes to `docker/app/Dockerfile` (not the base). The pipeline is identical to Scenario 1 — app Dockerfile changes don't trigger base rebuilds.

### What will happen

- Same as Scenario 1 — base unchanged, only app image rebuilt with modified Dockerfile
- Risk is slightly higher: Dockerfile changes affect build behavior

### Steps

1. **Create a feature branch:**

   ```bash
   git checkout main && git pull
   git checkout -b feat/optimize-dockerfile
   ```

2. **Modify `docker/app/Dockerfile`.** For example, add a health check to the prod target:

   ```dockerfile
   # Add after the CMD line in the prod target
   HEALTHCHECK --interval=30s --timeout=3s CMD php -r "echo 'ok';" || exit 1
   ```

3. **Push and open a PR:**

   ```bash
   git add docker/app/Dockerfile
   git commit -m "Add health check to app prod image"
   git push -u origin feat/optimize-dockerfile
   gh pr create --title "Add health check to app Dockerfile" \
     --body "Demonstrates app Dockerfile change flow."
   ```

4. **Observe CI:** Identical to Scenario 1 — `detect-changes` reports `base-changed: false`, no base rebuild, CI image built normally.

5. **Apply `app-image` label → observe QA:** Prod image built with the new Dockerfile (includes health check).

6. **Merge and observe:** Trunk Pipeline does not run (no base changes).

### What to verify

- [ ] `base-changed: false` despite Dockerfile changes (only `docker/base/**` triggers base rebuild)
- [ ] QA prod image includes the health check
- [ ] Flow identical to Scenario 1

---

## Scenario 3: Feature Branch QA (Base Dockerfile Changes)

Changes to `docker/base/**` trigger base image rebuilds and CI enforcement.

### What will happen

- **CI:** enforces `BASE_PROD_TAG` bump, builds `demo-base-dev:ci-$slug` + `demo-app-dev:ci-$slug`
- **QA:** builds `demo-base-prod:ci-$slug` + `demo-app-prod:qa-$slug` (temporary branch tags)
- **Main (post-merge):** builds `demo-base-dev:latest` + `demo-base-prod:X.Y.Z` (immutable)
- **Cleanup:** deletes 4 branch-scoped images

### Steps

1. **Create a feature branch:**

   ```bash
   git checkout main && git pull
   git checkout -b feat/add-curl-to-base
   ```

2. **Modify the base Dockerfile.** Edit `docker/base/Dockerfile` — add `curl`:

   ```dockerfile
   RUN apk add --no-cache git unzip curl
   ```

3. **Bump `BASE_PROD_TAG`** in `docker/app/Dockerfile` (required by CI enforcement):

   Change `ARG BASE_PROD_TAG=8.4.1` to `ARG BASE_PROD_TAG=8.4.2`

4. **Verify locally:**

   ```bash
   docker build --target prod -t demo-base-prod:test docker/base/
   docker run --rm demo-base-prod:test curl --version
   ```

5. **Push and open a PR:**

   ```bash
   git add docker/base/Dockerfile docker/app/Dockerfile
   git commit -m "Add curl to base image"
   git push -u origin feat/add-curl-to-base
   gh pr create --title "Add curl to base image" \
     --body "Demonstrates base Dockerfile change flow with version enforcement."
   ```

6. **Observe CI Pipeline (`ci-branch.yml`):**

   - `detect-changes` → `base-changed: true`
   - `enforce-base-version` → **runs**: checks `BASE_PROD_TAG` was bumped + version `8.4.2` doesn't exist in GHCR
   - `build-base-dev` → builds `demo-base-dev:ci-feat-add-curl-to-base`
   - `build-app-ci` → builds `demo-app-dev:ci-feat-add-curl-to-base` using the new base
   - Tests run against the updated base

7. **Apply `app-image` label → observe QA Pipeline (`qa-branch.yml`):**

   - `build-base-prod` → builds `demo-base-prod:ci-feat-add-curl-to-base` (temporary branch tag)
   - `build-app-prod` → builds `demo-app-prod:qa-feat-add-curl-to-base` using the temp base

8. **Merge the PR.**

9. **Observe Trunk Pipeline (`promote-base.yml`):**

   ```bash
   gh run list --workflow=promote-base.yml --limit 1
   ```

   Builds and pushes:
   - `demo-base-dev:latest` (overwrites previous)
   - `demo-base-prod:8.4.2` (immutable, new version)

10. **Verify the immutable base tag:**

    ```bash
    gh api users/freyr/packages/container/ci-pipeline-demo%2Fdemo-base-prod/versions \
      --jq '.[].metadata.container.tags[]' | head -5
    ```

    You should see `8.4.2` alongside `8.4.1`.

### Negative test: forget to bump version

Try modifying `docker/base/Dockerfile` without changing `BASE_PROD_TAG`:

```bash
git checkout -b feat/forget-bump
# Edit docker/base/Dockerfile but don't touch docker/app/Dockerfile
git add -A && git commit -m "Change base without version bump"
git push -u origin feat/forget-bump
gh pr create --title "Forget to bump version" --body "Should fail CI enforcement."
```

The `enforce-base-version` job will fail with:
```
::error::Base docker files changed but BASE_PROD_TAG in docker/app/Dockerfile was not bumped
```

### What to verify

- [ ] `enforce-base-version` ran and passed (version bumped correctly)
- [ ] 2 base images built in CI (dev) and QA (prod) with branch-scoped tags
- [ ] Tests ran against the **new** base image, not stale `:latest`
- [ ] After merge, Trunk Pipeline built `demo-base-dev:latest` + `demo-base-prod:8.4.2`
- [ ] `8.4.2` is immutable — it will never be overwritten
- [ ] Cleanup deleted 4 branch-scoped images
- [ ] New PRs (without base changes) now use the updated `:latest` base

---

## Scenario 4: Release — No Base Image Change

One or more features merged, none changed the base. The base version is the same as the last release. The release is a two-step manual process: create a tag, then trigger the workflow.

### Steps

1. **Ensure at least one feature is merged to main** (e.g., Scenario 1).

2. **Create and push a git tag:**

   ```bash
   git checkout main && git pull
   git tag v2026-03-14.1
   git push origin v2026-03-14.1
   ```

3. **Trigger the release workflow on the tag:**

   ```bash
   # Via GitHub Actions UI: Actions → Build Release → Run workflow → select "v2026-03-14.1" from dropdown
   # Or via CLI:
   gh workflow run release.yml --ref v2026-03-14.1
   ```

4. **Observe Build Release (`release.yml`):**

   ```bash
   gh run list --workflow=release.yml --limit 1
   gh run view <run-id>
   ```

   Job sequence:
   - `validate` → verifies ref is a tag
   - `build`:
     - Checks out code at `v2026-03-14.1`
     - Parses `BASE_PROD_TAG=8.4.1` from `docker/app/Dockerfile`
     - Checks `demo-base-prod:8.4.1` exists → **skips base build**
     - Builds `demo-app-prod:v2026-03-14.1` + `demo-app-prod:latest`
     - Pushes to GHCR
   - `release-notes` → creates GitHub release with auto-generated notes

5. **Verify the release:**

   ```bash
   # Check the GitHub release
   gh release view v2026-03-14.1

   # Verify the image
   docker pull ghcr.io/freyr/ci-pipeline-demo/demo-app-prod:v2026-03-14.1
   docker run --rm ghcr.io/freyr/ci-pipeline-demo/demo-app-prod:v2026-03-14.1

   # Check base version label
   docker inspect ghcr.io/freyr/ci-pipeline-demo/demo-app-prod:v2026-03-14.1 \
     | jq -r '.[0].Config.Labels["com.demo.base.version"]'
   # → "8.4.1"
   ```

### What to verify

- [ ] `validate` confirmed ref is a tag
- [ ] Base build was **skipped** (already exists)
- [ ] `demo-app-prod` pushed with version tag + `:latest`
- [ ] GitHub release created with notes
- [ ] `com.demo.base.version` label shows the correct base version

---

## Scenario 5: Release — Base Image Change Exists

A base change was merged (Scenario 3), and `promote-base.yml` already built `demo-base-prod:8.4.2`. Now we release.

### Steps

1. **Ensure Scenario 3 was completed** — base version bumped to `8.4.2`, `promote-base.yml` ran.

2. **Create and push a git tag:**

   ```bash
   git checkout main && git pull
   git tag v2026-03-14.2
   git push origin v2026-03-14.2
   ```

3. **Trigger the release workflow on the tag:**

   ```bash
   gh workflow run release.yml --ref v2026-03-14.2
   ```

4. **Observe the release pipeline:**

   - `validate` → confirms ref is a tag
   - `build`:
     - Parses `BASE_PROD_TAG=8.4.2`
     - Checks `demo-base-prod:8.4.2` exists → **skips base build** (built by promote-base)
     - Builds `demo-app-prod:v2026-03-14.2` using the new base
     - Pushes to GHCR
   - `release-notes` → GitHub release

5. **Compare base versions between releases:**

   ```bash
   # New release — built on new base
   docker inspect ghcr.io/freyr/ci-pipeline-demo/demo-app-prod:v2026-03-14.2 \
     | jq -r '.[0].Config.Labels["com.demo.base.version"]'
   # → "8.4.2"

   # Previous release — built on old base
   docker inspect ghcr.io/freyr/ci-pipeline-demo/demo-app-prod:v2026-03-14.1 \
     | jq -r '.[0].Config.Labels["com.demo.base.version"]'
   # → "8.4.1"
   ```

### Key insight

The release pipeline behaves **identically** in Scenarios 4 and 5. It always:
1. Reads `BASE_PROD_TAG` from the app Dockerfile
2. Checks if that version exists in GHCR
3. Skips building it if it does
4. Builds the app prod image on top

The difference is only in what's inside the base image. The Trunk Pipeline (triggered on merge to main) is what actually builds new base versions — the Build Release pipeline just consumes them.

### What to verify

- [ ] Build Release skipped base build (already built by Trunk Pipeline)
- [ ] App image `com.demo.base.version` label shows `8.4.2` (new base)
- [ ] Previous release still shows `8.4.1` (old base, immutable)
- [ ] Pipeline steps were identical to Scenario 4

---

## Summary: What Runs Where

| Scenario | CI Pipeline | QA Pipeline | Trunk Pipeline | Build Release | Cleanup |
|----------|:-----------:|:-----------:|:--------------:|:-------------:|:-------:|
| 1. App code only | 1 image | 1 image | — | — | 2 deleted |
| 2. App Dockerfile | 1 image | 1 image | — | — | 2 deleted |
| 3. Base Dockerfile | 2 images | 2 images | 2 images | — | 4 deleted |
| 4. Release (no base) | — | — | — | 1 image | — |
| 5. Release (with base) | — | — | — | 1 image | — |

### Release Flow Recap

The release is always a two-step manual process:

```bash
# Step 1: Create and push a tag (you choose the name)
git tag v2026-03-14.1
git push origin v2026-03-14.1

# Step 2: Run the release workflow on that tag
gh workflow run release.yml --ref v2026-03-14.1
```

The workflow validates it's running on a tag (not a branch), builds the production image at that exact commit, and publishes the GitHub release.
