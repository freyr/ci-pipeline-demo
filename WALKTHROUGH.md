# Interactive Walkthrough

Four scenarios that demonstrate the full CI/CD pipeline. Run them in order — each builds on the previous one.

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

7. **Observe the release pipeline:** The CalVer tag (e.g., `2026.03.14.1`) triggers `Build and Deploy`. Watch the three jobs: `build-base-images`, `build-app-image`, and `release-notes`. A GitHub release appears with auto-generated notes.

### What to verify

- [ ] `build-base` job was skipped in CI
- [ ] `quality` and `test` jobs passed
- [ ] CalVer tag was created on `main`
- [ ] All three Docker images were pushed to GHCR
- [ ] GitHub release was created with notes

---

## Scenario 2: Base Image Change

A PR that modifies the base Dockerfile triggers a conditional rebuild.

### Steps

1. **Create a branch and modify the base image:**

   ```bash
   git checkout main && git pull
   git checkout -b feature/add-curl-to-base
   ```

   Edit `docker/base/Dockerfile` — add `curl` to the prod target:

   ```dockerfile
   RUN apk add --no-cache git unzip curl
   ```

2. **Push and open a PR:**

   ```bash
   git add -A && git commit -m "Add curl to base image"
   git push -u origin feature/add-curl-to-base
   gh pr create --title "Add curl to base image" --body "Demonstrates conditional base image rebuild."
   ```

3. **Observe CI:** This time the `build-base` job **runs**. It builds the dev base image and pushes it as `demo-base-dev:dev-<sha7>`. The `quality` and `test` jobs run against this freshly built image instead of `:latest`.

### What to verify

- [ ] `detect-changes` reported `base-changed: true`
- [ ] `build-base` job ran and pushed `dev-<sha7>` tag
- [ ] `quality` and `test` jobs used the `dev-<sha7>` image
- [ ] After merge + release, the release pipeline updates `:latest`

---

## Scenario 3: Hotfix Flow

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

## Scenario 4: CalVer Auto-Increment

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
