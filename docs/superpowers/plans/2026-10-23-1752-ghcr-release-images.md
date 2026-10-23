# #1752 Prebuilt release images on GHCR — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** every `vX.Y.Z` release publishes multi-arch `php` and `web` images to
GHCR, and a production install or update on a release pulls them instead of
building on the host.

**Architecture:** `release.yml` gains two jobs after the existing `release` job:
`images` builds each image natively per architecture and pushes it by digest,
`manifests` joins the digests into one `:vX.Y.Z` tag per image.
`docker-compose.prod.yml` names the images (`image:` with `${APP_IMAGE_TAG:-local}`)
and keeps `build:`. `prod-start.sh` decides per run: a checkout on a release tag
pulls and starts with `--no-build`; anything else, or a release whose images
cannot be pulled, takes today's path (`refresh_images`, `up --build`).

**Tech Stack:** GitHub Actions (docker/login-action, setup-buildx-action,
build-push-action, upload/download-artifact), Docker Compose v2, bash 3.2-safe
shell in `scripts/lib.sh`.

**Spec:** [#1752](https://github.com/larspohlmann/simple-feed-reader/issues/1752)

## Global Constraints

- Image names: `ghcr.io/larspohlmann/simple-feed-reader-php`, `ghcr.io/larspohlmann/simple-feed-reader-web`; tag = the release tag `vX.Y.Z`, nothing else (no `latest`).
- Platforms: `linux/amd64` on `ubuntu-24.04`, `linux/arm64` on `ubuntu-24.04-arm` (native, no QEMU).
- The `php` and `worker` services share the php image.
- Dev stack (`docker-compose.yml`, `install-dev.sh`) and Strato are untouched.
- Shell stays bash-3.2-safe (no empty-array expansion under `set -u`) and passes `shellcheck scripts/*.sh scripts/test/*.sh`.
- Probed with Compose v5.3.0 in a throwaway project: a service with both `image:` and `build:` tags its local build with the `image:` name; `pull` of a missing tag exits 1; `up` without `--build` uses an existing image.

---

### Task 1: Release image decision in `scripts/lib.sh`

**Files:**
- Create: `scripts/test/release-images.test.sh`
- Modify: `scripts/lib.sh` (after `refresh_images`)
- Modify: `.github/workflows/ci.yml` (shell-scripts job, beside `update-pulls-base-images`)

**Interfaces:**
- Produces: `release_image_tag` (prints the highest plain `vX.Y.Z` tag pointing at HEAD, nothing otherwise), `export_prod_image_tag` (exports `APP_IMAGE_TAG`, `local` when not on a release), `prepare_prod_images` (exports `PROD_BUILD_OPTION`: `--no-build` after a successful release pull, `--build` otherwise).

- [ ] **Step 1: Write the failing test** — `scripts/test/release-images.test.sh`

```bash
#!/usr/bin/env bash
set -euo pipefail

# Unit test for #1752: a release checkout pulls its published images, anything
# else builds. prod_compose is stubbed and records every call.

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
# shellcheck source=scripts/lib.sh
source "${_dir}/../lib.sh"

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }

work=$(mktemp -d)
trap 'rm -rf "${work}"' EXIT

REPO_ROOT="${work}/repo"
git init --quiet "${REPO_ROOT}"
git -C "${REPO_ROOT}" -c user.name=test -c user.email=test@example.invalid \
  commit --quiet --allow-empty -m 'release'

[ -z "$(release_image_tag)" ] || fail 'an untagged commit named a release image tag'
export_prod_image_tag
[ "${APP_IMAGE_TAG}" = local ] || fail "untagged: APP_IMAGE_TAG='${APP_IMAGE_TAG}', expected 'local'"

git -C "${REPO_ROOT}" tag v1.2.3-dev.4
git -C "${REPO_ROOT}" tag v1.2.3
export_prod_image_tag
[ "${APP_IMAGE_TAG}" = v1.2.3 ] || fail "a dev tag beside the release won: '${APP_IMAGE_TAG}'"

calls="${work}/calls"
# shellcheck disable=SC2317,SC2329 # prepare_prod_images calls it by name
prod_compose() { printf '%s\n' "$*" >> "${calls}"; }

: > "${calls}"
prepare_prod_images >/dev/null 2>&1
[ "${PROD_BUILD_OPTION}" = --no-build ] || fail "pulled release: option '${PROD_BUILD_OPTION}'"
grep -qx 'pull --quiet' "${calls}" || fail "release images not pulled: $(cat "${calls}")"
if grep -q '^build' "${calls}"; then fail "a pulled release was built: $(cat "${calls}")"; fi

# shellcheck disable=SC2317,SC2329
prod_compose() {
  printf '%s\n' "$*" >> "${calls}"
  [ "$*" != 'pull --quiet' ]
}
: > "${calls}"
prepare_prod_images >/dev/null 2>&1
[ "${PROD_BUILD_OPTION}" = --build ] || fail "failed pull: option '${PROD_BUILD_OPTION}'"
grep -q '^build --pull' "${calls}" || fail "a failed pull skipped the build: $(cat "${calls}")"

APP_IMAGE_TAG=local
: > "${calls}"
prepare_prod_images >/dev/null 2>&1
[ "${PROD_BUILD_OPTION}" = --build ] || fail "unreleased: option '${PROD_BUILD_OPTION}'"
if grep -qx 'pull --quiet' "${calls}"; then fail 'an unreleased checkout pulled release images'; fi
grep -q '^build --pull' "${calls}" || fail "an unreleased checkout was not built: $(cat "${calls}")"

echo 'release-images: OK'
```

- [ ] **Step 2: Run it, expect FAIL** — `bash scripts/test/release-images.test.sh` → `release_image_tag: command not found`.

- [ ] **Step 3: Implement in `scripts/lib.sh`** after `refresh_images`:

```bash
# A release's php and web images are published to GHCR (#1752). Only a plain
# vX.Y.Z tag names them: a deploy tag can sit on the same commit.
release_image_tag() {
  git -C "${REPO_ROOT}" tag --points-at HEAD 2>/dev/null \
    | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' \
    | sort -V \
    | tail -n 1 \
    || true
}

export_prod_image_tag() {
  APP_IMAGE_TAG=$(release_image_tag)
  APP_IMAGE_TAG=${APP_IMAGE_TAG:-local}
  export APP_IMAGE_TAG
}

# Pulls a release's images, or refreshes the bases and leaves the build to
# `up`. Sets PROD_BUILD_OPTION for the `up` calls that follow.
prepare_prod_images() {
  PROD_BUILD_OPTION=--no-build
  export PROD_BUILD_OPTION
  if [ "${APP_IMAGE_TAG}" != local ] \
    && run_step "Pulling the ${APP_IMAGE_TAG} images" prod_compose pull --quiet; then
    return 0
  fi
  if [ "${APP_IMAGE_TAG}" != local ]; then
    warn "Could not pull the ${APP_IMAGE_TAG} images; building them here instead."
  fi
  PROD_BUILD_OPTION=--build
  run_step 'Pulling updated base images' refresh_images prod_compose \
    || warn 'Could not pull updated base images; building on the cached ones.'
}
```

- [ ] **Step 4: Run it, expect `release-images: OK`.** Also `shellcheck scripts/lib.sh scripts/test/release-images.test.sh`.

- [ ] **Step 5: Add to CI** — in `.github/workflows/ci.yml` after the `update-pulls-base-images` step:

```yaml
      # A release install pulls the images release.yml published; anything
      # else, or a failed pull, builds on the host (#1752).
      - name: release-images
        run: scripts/test/release-images.test.sh
```

- [ ] **Step 6: Commit** — `feat(#1752): decide per run between pulling release images and building`

### Task 2: Compose names the images, `prod-start.sh` uses the decision

**Files:**
- Modify: `docker-compose.prod.yml` (`php`, `worker`, `web`)
- Modify: `scripts/prod-start.sh` (image step, `up_holding_worker`, `start_worker`)

**Interfaces:**
- Consumes: `export_prod_image_tag`, `prepare_prod_images`, `PROD_BUILD_OPTION` from Task 1.

- [ ] **Step 1: Compose** — add above each `build:`:
  - `php` and `worker`: `image: ghcr.io/larspohlmann/simple-feed-reader-php:${APP_IMAGE_TAG:-local}`
  - `web`: `image: ghcr.io/larspohlmann/simple-feed-reader-web:${APP_IMAGE_TAG:-local}`

  and one comment at `x-version-build-args` level or on `php`: "A release pulls these from GHCR (#1752); `build:` serves a `--ref` install and a failed pull."

- [ ] **Step 2: `prod-start.sh`** — replace the `refresh_images` step and the two `--build` uses:

```bash
export_build_version_args
export_prod_image_tag
prepare_prod_images

# --remove-orphans drops containers of services this file no longer defines;
# a service only switched off by profile is kept.
run_step 'Starting the production stack (a build takes a few minutes)' \
  up_holding_worker prod_compose "${PROD_BUILD_OPTION}" --remove-orphans
...
run_step 'Starting the worker' start_worker prod_compose "${PROD_BUILD_OPTION}"
```

  Update the usage text's "Build and start" wording to "Pull or build and start".

- [ ] **Step 3: Verify the compose side** — with a scratch `.env.prod` copied from `.env.prod.example` with dummy required values:
  - `APP_IMAGE_TAG=v9.9.9 docker compose -f docker-compose.prod.yml --env-file <scratch> config --images` lists the three `:v9.9.9` names.
  - `docker compose ... --dry-run up -d --no-build --scale worker=0` and `--build` both parse.

- [ ] **Step 4: shellcheck + all `scripts/test/*.test.sh`.**

- [ ] **Step 5: Commit** — `feat(#1752): production installs on a release pull its published images`

### Task 3: `release.yml` publishes the images

**Files:**
- Modify: `.github/workflows/release.yml` (two new jobs after `release`)

- [ ] **Step 1: Add the jobs**

```yaml
  # The php and web images the Docker production path pulls for this release
  # (#1752). After `release`, so they inherit its two guards. Each architecture
  # builds on a native runner and pushes by digest; `manifests` tags the pair.
  images:
    name: Image ${{ matrix.image }} (${{ matrix.platform }})
    needs: release
    strategy:
      fail-fast: true
      matrix:
        image: [php, web]
        platform: [linux/amd64, linux/arm64]
    runs-on: ${{ matrix.platform == 'linux/arm64' && 'ubuntu-24.04-arm' || 'ubuntu-24.04' }}
    timeout-minutes: 45
    permissions:
      contents: read
      packages: write
    steps:
      - uses: actions/checkout@v5

      - name: Derive the release version
        id: version
        run: |
          set -euo pipefail
          { IFS= read -r version; IFS= read -r commit; IFS= read -r built_at; } \
            < <(scripts/stamp-version.sh derive "${GITHUB_REF_NAME}")
          {
            echo "version=${version}"
            echo "commit=${commit}"
            echo "built_at=${built_at}"
            echo "artifact=${{ matrix.image }}-${PLATFORM//\//-}"
          } >> "${GITHUB_OUTPUT}"
        env:
          PLATFORM: ${{ matrix.platform }}

      - uses: docker/login-action@v3
        with:
          registry: ghcr.io
          username: ${{ github.actor }}
          password: ${{ github.token }}

      - uses: docker/setup-buildx-action@v3

      - name: Build and push by digest
        id: build
        uses: docker/build-push-action@v6
        with:
          context: .
          file: docker/${{ matrix.image }}/Dockerfile
          target: ${{ matrix.image == 'php' && 'prod' || '' }}
          platforms: ${{ matrix.platform }}
          build-args: |
            APP_VERSION=${{ steps.version.outputs.version }}
            APP_COMMIT=${{ steps.version.outputs.commit }}
            APP_BUILT_AT=${{ steps.version.outputs.built_at }}
          labels: org.opencontainers.image.source=https://github.com/${{ github.repository }}
          outputs: type=image,name=ghcr.io/larspohlmann/simple-feed-reader-${{ matrix.image }},push-by-digest=true,name-canonical=true,push=true

      - name: Record the digest
        run: |
          mkdir -p "${RUNNER_TEMP}/digests"
          touch "${RUNNER_TEMP}/digests/${DIGEST#sha256:}"
        env:
          DIGEST: ${{ steps.build.outputs.digest }}

      - uses: actions/upload-artifact@v4
        with:
          name: digest-${{ steps.version.outputs.artifact }}
          path: ${{ runner.temp }}/digests/*
          if-no-files-found: error
          retention-days: 1

  manifests:
    name: Tag ${{ matrix.image }} ${{ github.ref_name }}
    needs: images
    runs-on: ubuntu-latest
    timeout-minutes: 10
    strategy:
      matrix:
        image: [php, web]
    permissions:
      contents: read
      packages: write
    steps:
      - uses: actions/download-artifact@v4
        with:
          path: ${{ runner.temp }}/digests
          pattern: digest-${{ matrix.image }}-*
          merge-multiple: true

      - uses: docker/login-action@v3
        with:
          registry: ghcr.io
          username: ${{ github.actor }}
          password: ${{ github.token }}

      - uses: docker/setup-buildx-action@v3

      - name: Tag the multi-arch image
        working-directory: ${{ runner.temp }}/digests
        run: |
          set -euo pipefail
          repository="ghcr.io/larspohlmann/simple-feed-reader-${{ matrix.image }}"
          # shellcheck disable=SC2046 # one argument per digest file
          docker buildx imagetools create -t "${repository}:${GITHUB_REF_NAME}" \
            $(printf "${repository}@sha256:%s " *)
          docker buildx imagetools inspect "${repository}:${GITHUB_REF_NAME}"
```

  Extend the file's header comment by one line: the release also publishes the images.

- [ ] **Step 2: Validate** — `actionlint .github/workflows/release.yml` if installed (else `python3 -c 'import yaml,sys; yaml.safe_load(open(sys.argv[1]))'`).

- [ ] **Step 3: Commit** — `ci(#1752): a release publishes multi-arch php and web images to GHCR`

### Task 4: Docs

**Files:**
- Modify: `docs/docker-production.md` (§6 Update, and the intro if it says the host builds)
- Modify: `docs/releasing.md` (what the tag push does; one-time package visibility)
- Modify: `CHANGELOG.md` (`## [Unreleased]`)

- [ ] **Step 1:** `docker-production.md` §6: a release update pulls the release's published images (and patched `image:` services); a `--ref` update or a failed pull builds on the host as before.
- [ ] **Step 2:** `releasing.md`: the tag push also publishes `simple-feed-reader-php`/`-web:vX.Y.Z` (both architectures). One-time step after the first such release: set both GHCR packages to **Public** (Package settings → Change visibility); until then installs fall back to building.
- [ ] **Step 3:** CHANGELOG Unreleased entry.
- [ ] **Step 4: Commit** — `docs(#1752): release images on GHCR`

### Task 5: Verify end to end

- [ ] `shellcheck scripts/*.sh scripts/test/*.sh docker/php/entrypoint-prod.sh docker/web/10-select-mode.sh`
- [ ] every `scripts/test/*.test.sh`
- [ ] Real run of the build fallback: a throwaway install from the branch (`SFR_REPO_URL=file://… scripts/install.sh --ref feature/1752-ghcr-release-images <tmp>`) builds and comes up healthy; then `down -v` from the throwaway clone.
- [ ] Real run of the pull path: in that clone, tag HEAD `v9.9.9` locally, push locally built images under that tag to nowhere — instead stub by `docker tag`ging the built images as `…:v9.9.9` and running `prod-start.sh` with the pull expected to fail (no such package) → falls back, warning printed. The success path of the pull is proven only by the first real release; say so in the PR.
