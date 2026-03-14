# Interactive Walkthrough

Six scenarios that demonstrate the full CI/CD pipeline. Run them in order — each builds on the previous one.

## Prerequisites

- Repository cloned and `composer install` run successfully
- GitHub CLI (`gh`) authenticated with push access to `freyr/ci-pipeline-demo`
- Branch protection configured (see README.md)

---

## Scenario 1: Normal Feature Flow

A developer adds a feature, opens a PR, and releases it.

### Steps

1. **Create a feature branch and add a method:**

   ```bash
   git checkout -b feature/add-farewell
   ```

   Edit `src/Greeter.php` — add a `farewell()` method:

   ```php
   public function farewell(string $name): string
   {
       return sprintf('Goodbye, %s!', $name);
   }
   ```

   Add a test in `tests/GreeterTest.php`:

   ```php
   #[Test]
   public function it_says_farewell(): void
   {
       $greeter = new Greeter();

       self::assertSame('Goodbye, World!', $greeter->farewell('World'));
   }
   ```

2. **Verify locally:**

   ```bash
   composer quality
   ```

3. **Push and open a PR:**

   ```bash
   git add -A && git commit -m "Add farewell method"
   git push -u origin feature/add-farewell
   gh pr create --title "Add farewell method" --body "Demonstrates the normal feature flow."
   ```

4. **Observe CI:** Go to the Actions tab. The `CI` workflow runs `detect-changes`, `quality`, and `test` jobs. The `build-base` job is **skipped** (no changes to `docker/base/**`).

5. **Merge the PR:** After CI passes, squash merge via the GitHub UI.

6. **Cut a release:**

   ```bash
   # Via GitHub Actions UI: go to Actions → Release → Run workflow (select main)
   # Or via CLI:
   gh workflow run release.yml --ref main
   ```

7. **Observe the release pipeline:** The Release run shows `create-tag` followed by the Deploy jobs (`build-base-images`, `build-app-image`, `release-notes`) — all in one unified view. A GitHub release appears with auto-generated notes.

### What to verify

- [ ] `build-base` job was skipped in CI
- [ ] `quality` and `test` jobs passed
- [ ] CalVer tag was created on `main`
- [ ] All three Docker images were pushed to GHCR
- [ ] GitHub release was created with notes

---

## Scenario 2: Base Dockerfile Change

A PR that modifies files under `docker/base/` triggers the conditional `build-base` job. This ensures tests run against the updated base image, not the stale `:latest`.

### Why this matters

The `quality` and `test` jobs run inside a container pulled from GHCR. If someone changes the base Dockerfile (e.g., adds a PHP extension) but tests run against the old image, the CI result is meaningless. The conditional build catches this.

### Steps

1. **Start from a clean main:**

   ```bash
   git checkout main && git pull
   ```

2. **Create a feature branch:**

   ```bash
   git checkout -b feature/add-curl-to-base
   ```

3. **Modify the base Dockerfile.** Edit `docker/base/Dockerfile` — add `curl` to the prod target's `apk add` line:

   ```dockerfile
   RUN apk add --no-cache git unzip curl
   ```

4. **Verify the Docker build works locally:**

   ```bash
   docker build --target prod -t demo-base-prod:test docker/base/
   docker build --target dev -t demo-base-dev:test docker/base/
   ```

   Confirm `curl` is available:

   ```bash
   docker run --rm demo-base-prod:test curl --version
   ```

5. **Commit and push:**

   ```bash
   git add docker/base/Dockerfile
   git commit -m "Add curl to base image"
   git push -u origin feature/add-curl-to-base
   ```

6. **Open a PR:**

   ```bash
   gh pr create --title "Add curl to base image" \
     --body "Demonstrates conditional base image rebuild."
   ```

7. **Observe CI in the Actions tab:**

   ```bash
   gh run list --limit 1
   gh run view <run-id>
   ```

   Watch the job sequence:
   - `detect-changes` — completes first, reports `base-changed: true`
   - `build-base` — **runs** (not skipped). Builds the dev base image and pushes it as `demo-base-dev:dev-<sha7>` to GHCR
   - `quality` — starts after `build-base` completes. Pulls the `dev-<sha7>` image
   - `test` — same, runs against `dev-<sha7>`

8. **Verify the image tag in GHCR:**

   ```bash
   gh api orgs/freyr/packages/container/ci-pipeline-demo%2Fdemo-base-dev/versions \
     --jq '.[].metadata.container.tags[]' | head -5
   ```

   You should see a `dev-` prefixed tag matching the PR's HEAD commit.

9. **Merge the PR** (squash merge via GitHub UI).

10. **Cut a release:**

    ```bash
    gh workflow run release.yml --ref main
    ```

11. **Observe the release pipeline.** The `build-base-images` job rebuilds both `prod` and `dev` targets and pushes them with the CalVer tag **and** `:latest`. From this point on, all PRs that don't touch the base use the updated `:latest`.

### What to verify

- [ ] `detect-changes` reported `base-changed: true`
- [ ] `build-base` job ran and pushed a `dev-<sha7>` tag to GHCR
- [ ] `quality` and `test` containers used the `dev-<sha7>` image (check the "Initialize containers" step log)
- [ ] After merge + release, `:latest` was updated to include `curl`
- [ ] Subsequent PRs (without base changes) pull the new `:latest` automatically

---

## Scenario 3: App Image Build on PR (label-triggered)

When QA needs a deployable image from a PR, add the `build-app` label. This triggers the `build-app` job, which builds the prod app image and pushes it to GHCR tagged with a slug of the branch name.

### Why this matters

Not every PR needs a Docker image — most are validated by the quality + test jobs alone. But when QA wants to pull and test a feature before merge, they ask the developer to add the `build-app` label. The image is the same prod artifact the release pipeline would produce, just tagged with the branch name instead of a CalVer tag.

### How the branch slug works

The branch name is converted to a Docker-safe tag:

| Branch name | Slug |
|-------------|------|
| `feature/add-farewell` | `feature-add-farewell` |
| `feature/JIRA-1234-fix-greeting` | `feature-jira-1234-fix-greeting` |
| `hotfix/urgent-fix` | `hotfix-urgent-fix` |

Rules: lowercased, non-alphanumeric characters (except `.` and `-`) replaced with `-`, consecutive dashes collapsed, leading/trailing dashes stripped.

### Steps

1. **Start from a clean main:**

   ```bash
   git checkout main && git pull
   ```

2. **Create a feature branch:**

   ```bash
   git checkout -b feature/update-app-dockerfile
   ```

3. **Make a change.** Edit `app/Dockerfile` — add a label for traceability:

   ```dockerfile
   # Add after the FROM line
   LABEL org.opencontainers.image.description="CI Pipeline Demo application"
   ```

4. **Verify the Docker build works locally:**

   ```bash
   docker build --build-arg BASE_IMAGE=demo-base-prod:test \
     -t demo-app:test -f app/Dockerfile .
   docker run --rm demo-app:test
   ```

   Should still print `Hello, demo!`.

5. **Commit and push:**

   ```bash
   git add app/Dockerfile
   git commit -m "Add OCI label to app Dockerfile"
   git push -u origin feature/update-app-dockerfile
   ```

6. **Open a PR without the label:**

   ```bash
   gh pr create --title "Add OCI label to app Dockerfile" \
     --body "Demonstrates label-triggered app image build."
   ```

7. **Observe CI — no app image build:**

   ```bash
   gh run list --limit 1
   gh run view <run-id>
   ```

   - `detect-changes` — completes
   - `build-base` — **skipped** (no base changes)
   - `quality` — passes
   - `test` — passes
   - `build-app` — **skipped** (no `build-app` label)

8. **Add the `build-app` label to trigger the image build:**

   ```bash
   gh pr edit --add-label build-app
   ```

   This triggers a new CI run. (If the label doesn't exist yet, create it first: `gh label create build-app --description "Build and push app image from PR" --color 0E8A16`)

9. **Observe the new CI run:**

   ```bash
   gh run list --limit 1
   gh run view <run-id>
   ```

   This time `build-app` **runs** after quality + test pass. It builds the app image and pushes it as `demo-app:feature-update-app-dockerfile`.

10. **Verify the image was pushed to GHCR:**

    ```bash
    gh api users/freyr/packages/container/ci-pipeline-demo%2Fdemo-app/versions \
      --jq '.[].metadata.container.tags[]' | head -5
    ```

    You should see the `feature-update-app-dockerfile` tag.

11. **Pull and run the PR image:**

    ```bash
    docker pull ghcr.io/freyr/ci-pipeline-demo/demo-app:feature-update-app-dockerfile
    docker run --rm ghcr.io/freyr/ci-pipeline-demo/demo-app:feature-update-app-dockerfile
    ```

    Should print `Hello, demo!`. Verify the label:

    ```bash
    docker inspect ghcr.io/freyr/ci-pipeline-demo/demo-app:feature-update-app-dockerfile \
      --format '{{ index .Config.Labels "org.opencontainers.image.description" }}'
    ```

12. **Merge the PR** (squash merge via GitHub UI).

13. **Cut a release** and observe the release pipeline produces the same image under CalVer + `:latest` tags:

    ```bash
    gh workflow run release.yml --ref main
    ```

### What to verify

- [ ] Without the label: `build-app` was **skipped**
- [ ] After adding `build-app` label: a new CI run started
- [ ] `quality` and `test` passed before `build-app` started
- [ ] `build-app` pushed `demo-app:feature-update-app-dockerfile` to GHCR
- [ ] The PR image is pullable and runnable
- [ ] After merge + release, `:latest` contains the same change

### Key takeaway

| Trigger | CI `build-base`? | CI `build-app`? | Tag format |
|---------|:----------------:|:---------------:|:----------:|
| PR changes `docker/base/**` | Yes (automatic) | No | `demo-base-dev:dev-<sha7>` |
| PR has `build-app` label | No | Yes (on-demand) | `demo-app:<branch-slug>` |
| PR changes `docker/base/**` + has `build-app` label | Yes | Yes | Both tags |
| PR without label, no base changes | No | No | — |

---

## Scenario 4: Hotfix Flow

The main demo. A bug is found in production, but `main` has unshippable work. The fix goes out from a `hotfix/*` branch.

### Steps

1. **Ensure a release exists.** If you completed Scenario 1, you already have one. Note the CalVer tag (e.g., `2026.03.14.1`).

2. **Simulate unshippable work on `main`:**

   ```bash
   git checkout main && git pull
   git checkout -b feature/wip-experiment
   ```

   Add a dummy file, open a PR, and merge it. This represents work that isn't ready for production.

3. **Discover the bug.** The `Greeter` says "Hello" but it should say "Hi" (or imagine a real typo — e.g., "Helo").

4. **Create a hotfix branch from the release tag:**

   ```bash
   TAG=2026.03.14.1  # replace with your actual tag
   git checkout -b hotfix/fix-greeting "$TAG"
   ```

5. **Fix the bug:**

   Edit `src/Greeter.php`:

   ```php
   return sprintf('Hi, %s!', $name);
   ```

   Update the test in `tests/GreeterTest.php`:

   ```php
   self::assertSame('Hi, World!', $greeter->greet('World'));
   ```

6. **Verify locally and push:**

   ```bash
   composer quality
   git add -A && git commit -m "Fix greeting message"
   git push -u origin hotfix/fix-greeting
   ```

7. **Release from the hotfix branch:**

   ```bash
   gh workflow run release.yml --ref hotfix/fix-greeting
   ```

   A new CalVer tag is created on the hotfix branch (e.g., `2026.03.14.2`). The release pipeline runs — same workflow, same images, same process as a normal release.

8. **Cherry-pick the fix back to `main`:**

   ```bash
   git checkout main && git pull
   git checkout -b feature/backport-greeting-fix
   git cherry-pick hotfix/fix-greeting  # cherry-pick the fix commit
   git push -u origin feature/backport-greeting-fix
   gh pr create --title "Backport: Fix greeting message" --body "Cherry-pick from hotfix/fix-greeting"
   ```

   Merge the backport PR to `main`.

### What to verify

- [ ] `release.yml` ran successfully from the `hotfix/*` branch
- [ ] CalVer tag was created on the hotfix branch (not on `main`)
- [ ] Release pipeline built and pushed images from the hotfix tag
- [ ] The fix was cherry-picked back to `main`
- [ ] **Golden rule:** `main` contains the fix. The hotfix branch can be deleted.

---

## Scenario 5: CalVer Auto-Increment

Multiple releases on the same day produce incrementing build numbers.

### Steps

1. **Cut two releases in quick succession:**

   ```bash
   gh workflow run release.yml --ref main
   # Wait for the first to complete
   gh workflow run release.yml --ref main
   ```

2. **Check the tags:**

   ```bash
   git fetch --tags
   git tag --sort=-v:refname | head -5
   ```

   You should see two tags for today with `.1` and `.2` suffixes.

### What to verify

- [ ] First release: `YYYY.0M.0D.1`
- [ ] Second release: `YYYY.0M.0D.2`
- [ ] Both triggered independent release pipeline runs
- [ ] Both produced distinct Docker image tags
