# Repo Meta Files Implementation Plan (issue #67)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the standard public-repo meta files (LICENSE, CONTRIBUTING, CODE_OF_CONDUCT, SECURITY, CHANGELOG) and update the README with a CI badge, screenshots, and links.

**Architecture:** Docs-only change on branch `feature/67-repo-meta-docs`. Every file links to the existing docs (`docs/local-docker.md`, `docs/releasing.md`, `CLAUDE.md` conventions) instead of duplicating them. No code, no CI changes.

**Tech Stack:** Markdown, git, `gh` CLI (one repo-settings call).

**Spec:** `docs/superpowers/specs/2026-08-01-repo-meta-docs-design.md`

## Global Constraints

- All new files live at the repo root except the screenshots (already at `docs/screenshots/`).
- License: MIT, copyright line exactly `Copyright (c) 2026 Lars Pohlmann`.
- Code of conduct: Contributor Covenant 2.1, contact `lars.pohlmann@googlemail.com`.
- Security reports: GitHub private vulnerability reporting only — no public email in SECURITY.md.
- Changelog format: Keep a Changelog 1.1, semver, starts with only an `Unreleased` section.
- Commit style: `docs(#67): <summary>`.
- Skipped on purpose (do NOT add): issue templates, PR template, license badge.
- Verification for every task: `ls` the file, and check that every relative link target exists. There is no markdown test suite; a docs task passes when the file exists, renders as intended, and its links resolve.

---

### Task 1: LICENSE

**Files:**
- Create: `LICENSE`

**Interfaces:**
- Produces: `LICENSE` at the repo root; Task 6 links to it from the README.

- [ ] **Step 1: Write the file**

Create `LICENSE` (no extension) with exactly this content:

```text
MIT License

Copyright (c) 2026 Lars Pohlmann

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

- [ ] **Step 2: Verify**

Run: `head -3 LICENSE`
Expected: `MIT License`, blank line, `Copyright (c) 2026 Lars Pohlmann`. The text must be the unmodified MIT template so GitHub's license detection recognises it.

- [ ] **Step 3: Commit**

```bash
git add LICENSE
git commit -m "docs(#67): add MIT license"
```

---

### Task 2: CONTRIBUTING.md

**Files:**
- Create: `CONTRIBUTING.md`

**Interfaces:**
- Consumes: existing docs `docs/local-docker.md`, `README.md`, `docs/releasing.md` (links only).
- Produces: `CONTRIBUTING.md` at the repo root; Task 6 links to it from the README.

- [ ] **Step 1: Write the file**

Create `CONTRIBUTING.md` with exactly this content:

````markdown
# Contributing

Thanks for your interest in simple-feed-reader. This page explains how a
change makes it into the project. The short version: **start from an issue,
branch off `develop`, pass the quality gate, open a PR.**

## Before you start: open or claim an issue

Every change starts as a [GitHub issue](https://github.com/larspohlmann/simple-feed-reader/issues)
— a bug report, a feature idea, or a question. Comment on the issue you want
to work on so it can be assigned to you. Please don't open a pull request
without an issue behind it; the project follows written plans and strong
conventions, and a surprise PR is likely to conflict with them.

## Setting up a development environment

The whole stack (MySQL, PHP-FPM, nginx with TLS, Mailpit) runs in Docker.
[docs/local-docker.md](docs/local-docker.md) is the full walkthrough; the
one-line installer in the [README](README.md#quick-start-docker) is the fast
path. The frontend dev server runs natively:

```bash
cd frontend && npm ci && npm start   # http://localhost:4200
```

## Branches and commits

The project uses git-flow:

- Branch off `develop` — never `main`. `main` only fast-forwards from
  `develop` at release time ([docs/releasing.md](docs/releasing.md)).
- Branch names embed the issue number: `feature/67-repo-meta-docs`,
  `fix/112-arbitrary-join-on`.
- Commit messages follow `type(#issue): summary`, for example
  `feat(#206): one-line Docker installer`. Types in use: `feat`, `fix`,
  `test`, `docs`, `refactor`, `chore`.

## The quality gate

A PR must pass everything CI runs. Run it locally first.

Backend (from `backend/`):

```bash
composer check       # PHP_CodeSniffer (PSR-12) + PHPStan level max
composer md          # PHPMD codesize — every touched src file must be clean
php bin/phpunit      # unit/integration suite (SQLite)
```

Also run the MySQL leg against the Docker stack:

```bash
docker compose exec php vendor/bin/phpunit
```

Frontend (from `frontend/`):

```bash
npm run check        # ESLint + Prettier + Stylelint + Jest
```

Style expectations beyond the linters — intention-revealing names, short
single-purpose functions, guard clauses, typed exceptions — are spelled out
in [CLAUDE.md](CLAUDE.md); the linters enforce most of it mechanically.

## Opening the pull request

- Target `develop`.
- Put `Closes #NN` in the PR body so the issue closes on merge.
- Describe what changed and why; link any design doc you followed.

## Code of conduct

Participation is covered by the [code of conduct](CODE_OF_CONDUCT.md).
Security problems go through [private reporting](SECURITY.md), not public
issues.
````

- [ ] **Step 2: Verify links**

Run: `ls docs/local-docker.md docs/releasing.md CLAUDE.md README.md`
Expected: all four exist. (`CODE_OF_CONDUCT.md` and `SECURITY.md` arrive in Tasks 3–4; that forward reference is fine within this PR.)

- [ ] **Step 3: Commit**

```bash
git add CONTRIBUTING.md
git commit -m "docs(#67): add contributing guide"
```

---

### Task 3: CODE_OF_CONDUCT.md

**Files:**
- Create: `CODE_OF_CONDUCT.md`

**Interfaces:**
- Produces: `CODE_OF_CONDUCT.md` at the repo root; Task 2 already links to it.

- [ ] **Step 1: Fetch the canonical Contributor Covenant 2.1 text**

```bash
curl -fsSL https://www.contributor-covenant.org/version/2/1/code_of_conduct/code_of_conduct.md -o CODE_OF_CONDUCT.md
```

(The Contributor Covenant is CC BY 4.0; the file's own attribution section satisfies the licence.)

- [ ] **Step 2: Insert the contact**

Replace the literal placeholder `[INSERT CONTACT METHOD]` with
`lars.pohlmann@googlemail.com`:

```bash
sed -i '' 's/\[INSERT CONTACT METHOD\]/lars.pohlmann@googlemail.com/' CODE_OF_CONDUCT.md
```

- [ ] **Step 3: Verify**

Run: `grep -c 'INSERT' CODE_OF_CONDUCT.md; grep -c 'lars.pohlmann@googlemail.com' CODE_OF_CONDUCT.md; head -1 CODE_OF_CONDUCT.md`
Expected: `0`, `1`, and a Contributor Covenant heading. If the download failed or the file contains HTML instead of markdown, stop and report it.

- [ ] **Step 4: Commit**

```bash
git add CODE_OF_CONDUCT.md
git commit -m "docs(#67): add Contributor Covenant 2.1 code of conduct"
```

---

### Task 4: SECURITY.md

**Files:**
- Create: `SECURITY.md`

**Interfaces:**
- Produces: `SECURITY.md` at the repo root; Task 2 already links to it; Task 7 enables the GitHub feature it points at.

- [ ] **Step 1: Write the file**

Create `SECURITY.md` with exactly this content:

````markdown
# Security Policy

## Reporting a vulnerability

Please report vulnerabilities **privately** through GitHub:
[Report a vulnerability](https://github.com/larspohlmann/simple-feed-reader/security/advisories/new)
(Security tab → "Report a vulnerability").

**Do not open a public issue for a security problem.** Public issues are
visible immediately, including to anyone who would exploit the report.

You can expect an initial response within a few days. Please include steps to
reproduce, the affected endpoint or component, and the impact you see.

## Scope

Reports are especially welcome for the security-sensitive surfaces:

- Authentication: JWT bearer tokens, login rate limiting, the ALTCHA
  proof-of-work challenge, password reset mails.
- OAuth/OIDC sign-in (Google and Apple): state binding, token validation,
  account linking.
- Outbound feed fetching and scraping: SSRF protections (redirect
  re-validation, IP pinning, response caps) in the fetch pipeline.
- Multi-user isolation: one user reaching another user's subscriptions,
  entries, or settings.

## Supported versions

Only the latest release (the highest `vX.Y.Z` tag) and the current `develop`
branch receive security fixes.
````

- [ ] **Step 2: Verify**

Run: `head -1 SECURITY.md`
Expected: `# Security Policy`.

- [ ] **Step 3: Commit**

```bash
git add SECURITY.md
git commit -m "docs(#67): add security policy with private reporting"
```

---

### Task 5: CHANGELOG.md and the release-step addition

**Files:**
- Create: `CHANGELOG.md`
- Modify: `docs/releasing.md` (the `## Steps` section, currently lines 32–52)

**Interfaces:**
- Consumes: the release process described in `docs/releasing.md`.
- Produces: `CHANGELOG.md` at the repo root; Task 6 links to it from the README.

- [ ] **Step 1: Write CHANGELOG.md**

Create `CHANGELOG.md` with exactly this content:

````markdown
# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). History before
August 2026 lives in the git log and the merged pull requests.

## [Unreleased]

### Added

- Standard public-repo meta files: MIT license, contributing guide, code of
  conduct, security policy, this changelog. ([#67])

[Unreleased]: https://github.com/larspohlmann/simple-feed-reader/compare/main...develop
[#67]: https://github.com/larspohlmann/simple-feed-reader/issues/67
````

- [ ] **Step 2: Add the changelog step to docs/releasing.md**

In `docs/releasing.md`, the `## Steps` section starts with the sentence
"From an up-to-date checkout, with CI green on the `develop` commit you want
to ship:" followed by a bash block whose step comments are numbered `# 1.`
(merge), `# 2.` (tag), `# 3.` (publish). Insert a new step **before** that
bash block, and renumber the comments in the block to `# 2.`–`# 4.`:

```markdown
Before merging, update [CHANGELOG.md](../CHANGELOG.md) on `develop`: rename
the `## [Unreleased]` section to the new version with the date
(`## [vX.Y.Z] - YYYY-MM-DD`), add its compare link at the bottom, and start a
fresh empty `## [Unreleased]` section above it.
```

- [ ] **Step 3: Verify**

Run: `head -1 CHANGELOG.md && grep -n 'CHANGELOG' docs/releasing.md`
Expected: `# Changelog`, and at least one hit inside the Steps section of `docs/releasing.md`.

- [ ] **Step 4: Commit**

```bash
git add CHANGELOG.md docs/releasing.md
git commit -m "docs(#67): add changelog, wire it into the release steps"
```

---

### Task 6: README — badge, screenshots, links

**Files:**
- Modify: `README.md`
- Use (already committed): `docs/screenshots/desktop-reader.png`, `docs/screenshots/desktop-cards.png`, `docs/screenshots/mobile.png`

**Interfaces:**
- Consumes: `LICENSE` (Task 1), `CONTRIBUTING.md` (Task 2), `CHANGELOG.md` (Task 5), the three screenshots.

- [ ] **Step 1: Add the CI badge**

In `README.md`, directly under the `# simple-feed-reader` heading, insert a blank line and then:

```markdown
[![CI](https://github.com/larspohlmann/simple-feed-reader/actions/workflows/ci.yml/badge.svg?branch=develop)](https://github.com/larspohlmann/simple-feed-reader/actions/workflows/ci.yml)
```

- [ ] **Step 2: Add the screenshots**

After the intro paragraph ("A multi-user RSS/Atom feed reader. … in `frontend/`.") and before `## Quick start (Docker)`, insert:

```markdown
![The reader: entry list and reader pane side by side](docs/screenshots/desktop-reader.png)

<p>
  <img src="docs/screenshots/desktop-cards.png" alt="Card view on desktop" width="66%">
  <img src="docs/screenshots/mobile.png" alt="Mobile view" width="29%">
</p>
```

(66 % and 29 % give both images the same rendered height: the card view is 1578×1319 px, the mobile shot 495×955 px, so equal heights need widths in the ratio 0.836:1.929 — 66×0.836 ≈ 55.2 vs 29×1.929 ≈ 55.9, within 1.5 %.)

- [ ] **Step 3: Add the meta-file links to the Documentation section**

At the end of the `## Documentation` bullet list in `README.md`, append:

```markdown
- [Contributing](CONTRIBUTING.md) — issue-first workflow, branch conventions,
  and the quality gate. Licensed under the [MIT license](LICENSE); notable
  changes land in the [changelog](CHANGELOG.md).
```

- [ ] **Step 4: Verify**

Run: `ls docs/screenshots/desktop-reader.png docs/screenshots/desktop-cards.png docs/screenshots/mobile.png LICENSE CONTRIBUTING.md CHANGELOG.md`
Expected: all six paths exist. Then render-check `README.md` in the GitHub PR view (or a local previewer) — badge at top, hero image, two-image gallery row.

- [ ] **Step 5: Commit**

```bash
git add README.md
git commit -m "docs(#67): README badge, screenshots, meta-file links"
```

---

### Task 7: Enable private vulnerability reporting and open the PR

**Files:** none (repo settings + PR).

**Interfaces:**
- Consumes: `SECURITY.md` (Task 4) — the advisory URL it points at only works once the feature is on.

- [ ] **Step 1: Enable private vulnerability reporting (repo setting — confirmed by the approved spec)**

```bash
gh api -X PUT /repos/larspohlmann/simple-feed-reader/private-vulnerability-reporting
```

- [ ] **Step 2: Verify the setting**

```bash
gh api /repos/larspohlmann/simple-feed-reader/private-vulnerability-reporting --jq .enabled
```

Expected: `true`.

- [ ] **Step 3: Push and open the PR**

```bash
git push -u origin feature/67-repo-meta-docs
gh pr create --base develop --title "docs(#67): standard public-repo meta files" --body "$(cat <<'EOF'
Adds the standard meta files for a public repository, per the approved design
(docs/superpowers/specs/2026-08-01-repo-meta-docs-design.md):

- MIT LICENSE
- CONTRIBUTING.md (issue-first workflow, git-flow conventions, quality gate)
- CODE_OF_CONDUCT.md (Contributor Covenant 2.1)
- SECURITY.md (GitHub private vulnerability reporting; enabled on the repo)
- CHANGELOG.md (Keep a Changelog, starts empty)
- README: CI badge, UI screenshots, meta-file links
- Release automation: .github/workflows/release.yml turns a vX.Y.Z tag on
  main into a published GitHub Release and (after the first release) an
  auto-committed CHANGELOG.md section, backed by a tested helper script;
  docs/releasing.md rewritten to match.

Skipped by decision: issue templates, PR template, license badge.

Closes #67
EOF
)"
```

- [ ] **Step 4: Verify the PR renders correctly**

Open the PR's "Files changed" view: the README badge resolves (may show "no runs" until CI runs on the branch), both screenshot images render, and GitHub shows "MIT license" in the repo sidebar once merged.

---

> **Execution order note (added 2026-08-01):** Tasks 8 and 9 below were added
> after the original plan, to add release automation. Run them **before**
> Task 7, so the PR that Task 7 opens already contains the release workflow.

---

### Task 8: `changelog-insert-release.sh` and its golden-file test

**Files:**
- Create: `scripts/changelog-insert-release.sh`
- Create: `scripts/test/changelog-insert-release.test.sh`
- Create: `scripts/test/fixtures/changelog-before.md`
- Create: `scripts/test/fixtures/changelog-expected.md`
- Create: `scripts/test/fixtures/notes.md`

**Interfaces:**
- Produces: an executable `scripts/changelog-insert-release.sh` that Task 9's workflow calls. Contract: `changelog-insert-release.sh <version-tag> <iso-date>`, release notes on stdin, `CHANGELOG.md` path overridable with the `CHANGELOG_FILE` env var (defaults to the repo-root `CHANGELOG.md`). It inserts a `## [<tag>] - <date>` section, then the notes, immediately after the `## [Unreleased]` line. Exits non-zero if the file lacks an `## [Unreleased]` line, or already has a section for `<tag>`.

This is TDD: write the test and fixtures first, watch it fail, then write the script.

- [ ] **Step 1: Write the fixtures**

`scripts/test/fixtures/changelog-before.md`:

```markdown
# Changelog

Intro line.

## [Unreleased]
```

`scripts/test/fixtures/notes.md`:

```markdown
## What's Changed
* Add a thing by @octocat in #1

**Full Changelog**: https://example.test/compare/v0.0.0...v1.0.0
```

`scripts/test/fixtures/changelog-expected.md` (the result of inserting `v1.0.0` / `2026-01-02`):

```markdown
# Changelog

Intro line.

## [Unreleased]

## [v1.0.0] - 2026-01-02

## What's Changed
* Add a thing by @octocat in #1

**Full Changelog**: https://example.test/compare/v0.0.0...v1.0.0
```

- [ ] **Step 2: Write the failing test**

`scripts/test/changelog-insert-release.test.sh`:

```bash
#!/usr/bin/env bash
set -euo pipefail

# Golden-file test for changelog-insert-release.sh. The script's output is
# auto-committed to main by the release workflow, so its exact shape is a
# contract, not an implementation detail.

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
script="${_dir}/../changelog-insert-release.sh"
fixtures="${_dir}/fixtures"

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }

# Case 1: a normal insert matches the golden file exactly.
work=$(mktemp)
trap 'rm -f "${work}"' EXIT
cp "${fixtures}/changelog-before.md" "${work}"
CHANGELOG_FILE="${work}" "${script}" v1.0.0 2026-01-02 < "${fixtures}/notes.md"
diff -u "${fixtures}/changelog-expected.md" "${work}" || fail 'output does not match the golden file'

# Case 2: inserting the same version twice is refused (idempotence guard).
if CHANGELOG_FILE="${work}" "${script}" v1.0.0 2026-01-02 < "${fixtures}/notes.md" 2>/dev/null; then
  fail 'a duplicate version insert should exit non-zero'
fi

# Case 3: a changelog with no Unreleased anchor is refused.
no_anchor=$(mktemp)
printf '# Changelog\n\nNothing here.\n' > "${no_anchor}"
if CHANGELOG_FILE="${no_anchor}" "${script}" v1.0.0 2026-01-02 < "${fixtures}/notes.md" 2>/dev/null; then
  rm -f "${no_anchor}"; fail 'a missing Unreleased anchor should exit non-zero'
fi
rm -f "${no_anchor}"

printf 'ok: changelog-insert-release.sh\n'
```

- [ ] **Step 3: Run it, watch it fail**

Run: `chmod +x scripts/test/changelog-insert-release.test.sh && scripts/test/changelog-insert-release.test.sh`
Expected: fails — the script does not exist yet.

- [ ] **Step 4: Write the script**

`scripts/changelog-insert-release.sh`:

```bash
#!/usr/bin/env bash
set -euo pipefail

# Insert a released version section into CHANGELOG.md.
#
#   scripts/changelog-insert-release.sh <version-tag> <iso-date> < notes.md
#
# The release notes body is read from stdin and placed under a new
#
#   ## [<tag>] - <date>
#
# heading, inserted immediately after the "## [Unreleased]" line. The release
# workflow runs this and commits CHANGELOG.md to main, so the output shape is a
# tested contract (see scripts/test/changelog-insert-release.test.sh).

usage() { printf 'usage: %s <version-tag> <iso-date> < notes\n' "$0" >&2; exit 2; }

version=${1:-}
date=${2:-}
[ -n "${version}" ] && [ -n "${date}" ] || usage

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
changelog="${CHANGELOG_FILE:-${_dir}/../CHANGELOG.md}"

[ -f "${changelog}" ] || { printf 'error: no changelog at %s\n' "${changelog}" >&2; exit 1; }

anchor='## [Unreleased]'
grep -qF "${anchor}" "${changelog}" \
  || { printf "error: no '%s' line in %s\n" "${anchor}" "${changelog}" >&2; exit 1; }

# Idempotence: never insert the same version twice.
if grep -qF "## [${version}]" "${changelog}"; then
  printf 'error: %s already has a section for %s\n' "${changelog}" "${version}" >&2
  exit 1
fi

section=$(mktemp)
trap 'rm -f "${section}"' EXIT
{
  printf '\n## [%s] - %s\n\n' "${version}" "${date}"
  cat
} > "${section}"

# Print every line; right after the Unreleased anchor, splice in the section.
awk -v anchor="${anchor}" -v section="${section}" '
  { print }
  $0 == anchor && !spliced {
    while ((getline line < section) > 0) print line
    close(section)
    spliced = 1
  }
' "${changelog}" > "${changelog}.tmp"
mv "${changelog}.tmp" "${changelog}"
```

- [ ] **Step 5: Run the test, watch it pass**

Run: `scripts/test/changelog-insert-release.test.sh`
Expected: `ok: changelog-insert-release.sh`. Also run `shellcheck scripts/changelog-insert-release.sh scripts/test/changelog-insert-release.test.sh` if shellcheck is installed; fix any warnings.

- [ ] **Step 6: Commit**

```bash
chmod +x scripts/changelog-insert-release.sh scripts/test/changelog-insert-release.test.sh
git add scripts/changelog-insert-release.sh scripts/test/
git commit -m "feat(#67): tested changelog-insert-release helper for release automation"
```

---

### Task 9: release workflow, releasing.md rewrite, changelog reset

**Files:**
- Create: `.github/workflows/release.yml`
- Rewrite: `docs/releasing.md`
- Modify: `CHANGELOG.md` (reset the Unreleased section, drop the manual-curation content)
- Modify: `CODE_OF_CONDUCT.md` (drop the leading blank line — cosmetic, from the final review)

**Interfaces:**
- Consumes: `scripts/changelog-insert-release.sh` (Task 8) and `scripts/test/changelog-insert-release.test.sh`.

- [ ] **Step 1: Write `.github/workflows/release.yml`**

```yaml
# Publishes a GitHub Release when a plain vX.Y.Z tag is pushed on main, and
# writes the release notes back into CHANGELOG.md.
#
# Like the Strato deploy, a tag push proves nothing on its own: it can name any
# commit, and CI does not run on tags. Two guard steps supply what the trigger
# cannot -- the commit is on main, and CI went green on that exact SHA -- before
# anything is published or committed back to main.
#
# A push event on a tag runs THIS FILE AS IT EXISTS AT THAT TAG, so the helper
# script and its test are the versions shipped with the release.
name: Release

on:
  push:
    # GitHub filter patterns, not regex: '.' is literal and '+' means "one or
    # more of the preceding character". This matches vX.Y.Z and nothing else;
    # the vX.Y.Z-dev.N deploy tags are deliberately unmatched, so the two lanes
    # stay separate.
    tags: ['v[0-9]+.[0-9]+.[0-9]+']

# One release at a time.
concurrency:
  group: release
  cancel-in-progress: false

# Create the release, and push the changelog commit to main.
permissions:
  contents: write

jobs:
  release:
    name: Publish release
    runs-on: ubuntu-latest
    timeout-minutes: 15
    if: github.repository == 'larspohlmann/simple-feed-reader'

    steps:
      # fetch-depth: 0 populates origin/main (guard 1) and all tags (previous-tag
      # lookup). Credentials are persisted on purpose here: the changelog step
      # pushes back to main.
      - uses: actions/checkout@v5
        with:
          fetch-depth: 0

      # The changelog helper's output is auto-committed to main below, so verify
      # it before trusting it -- at the tagged commit's version of the script.
      - name: Verify the changelog helper
        run: scripts/test/changelog-insert-release.test.sh

      # GUARD 1 of 2: the tagged commit must be on main.
      - name: Check the tagged commit is on main
        run: |
          set -euo pipefail
          if ! git rev-parse --verify --quiet refs/remotes/origin/main >/dev/null; then
            echo "!!! origin/main is not in this checkout; fix fetch-depth, do not drop the check." >&2
            exit 1
          fi
          if ! git merge-base --is-ancestor "${GITHUB_SHA}" refs/remotes/origin/main; then
            echo "!!! ${GITHUB_REF_NAME} points at ${GITHUB_SHA}, which is not on main. Refusing to release." >&2
            echo "!!!   git tag -d ${GITHUB_REF_NAME} && git push origin :${GITHUB_REF_NAME}" >&2
            exit 1
          fi
          echo "==> ${GITHUB_SHA} is on main"

      # GUARD 2 of 2: CI must be green for this commit. Absence is failure.
      - name: Check CI passed for this commit
        env:
          GH_TOKEN: ${{ github.token }}
        run: |
          set -euo pipefail
          conclusions="$(gh api \
            "repos/${GITHUB_REPOSITORY}/actions/workflows/ci.yml/runs?head_sha=${GITHUB_SHA}&status=completed" \
            --jq '.workflow_runs[] | select(.event == "push") | .conclusion')"
          if [ -z "${conclusions}" ]; then
            echo "!!! no completed CI run found for ${GITHUB_SHA}. Refusing to release." >&2
            exit 1
          fi
          failed=0
          while IFS= read -r conclusion; do
            echo "    ${conclusion}"
            [ "${conclusion}" = "success" ] || failed=1
          done <<< "${conclusions}"
          [ "${failed}" -eq 0 ] || { echo "!!! CI is not green for ${GITHUB_SHA}." >&2; exit 1; }
          echo "==> CI is green for ${GITHUB_SHA}"

      # The previous release is the highest plain-semver tag below this one. The
      # grep is the same load-bearing filter scripts/lib.sh uses: it keeps the
      # vX.Y.Z-dev.N deploy tags out of the release line. Empty means this is the
      # first release.
      - name: Determine the previous release
        id: previous
        run: |
          set -euo pipefail
          previous="$(git tag --list 'v*' \
            | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' \
            | grep -vFx "${GITHUB_REF_NAME}" \
            | sort -V | tail -n 1)"
          echo "previous=${previous}" >> "${GITHUB_OUTPUT}"
          if [ -n "${previous}" ]; then
            echo "==> previous release: ${previous}"
          else
            echo "==> no previous release; this is the first"
          fi

      # First release: a plain body, no generated dump of every past PR.
      # Later releases: notes from the PRs merged since the previous tag.
      - name: Build the release notes
        id: notes
        env:
          GH_TOKEN: ${{ github.token }}
        run: |
          set -euo pipefail
          previous='${{ steps.previous.outputs.previous }}'
          if [ -z "${previous}" ]; then
            printf 'Initial release.\n' > "${RUNNER_TEMP}/notes.md"
          else
            gh api -X POST "repos/${GITHUB_REPOSITORY}/releases/generate-notes" \
              -f tag_name="${GITHUB_REF_NAME}" \
              -f previous_tag_name="${previous}" \
              --jq .body > "${RUNNER_TEMP}/notes.md"
          fi

      - name: Publish the GitHub Release
        env:
          GH_TOKEN: ${{ github.token }}
        run: |
          set -euo pipefail
          gh release create "${GITHUB_REF_NAME}" \
            --title "${GITHUB_REF_NAME}" \
            --notes-file "${RUNNER_TEMP}/notes.md" \
            --latest \
            --verify-tag

      # Only for later releases: write the notes into CHANGELOG.md and push to
      # main as the actions bot. Skipped for the first release (empty previous).
      - name: Update CHANGELOG.md on main
        if: steps.previous.outputs.previous != ''
        run: |
          set -euo pipefail
          git checkout main
          git merge --ff-only "${GITHUB_SHA}"
          scripts/changelog-insert-release.sh "${GITHUB_REF_NAME}" "$(date -u +%Y-%m-%d)" \
            < "${RUNNER_TEMP}/notes.md"
          if git diff --quiet -- CHANGELOG.md; then
            echo "==> CHANGELOG.md unchanged; nothing to commit"
            exit 0
          fi
          git config user.name  'github-actions[bot]'
          git config user.email '41898282+github-actions[bot]@users.noreply.github.com'
          git add CHANGELOG.md
          git commit -m "docs: changelog for ${GITHUB_REF_NAME} [skip ci]"
          git push origin main
```

- [ ] **Step 2: Reset `CHANGELOG.md`**

Replace the entire current content of `CHANGELOG.md` with this. It drops the hand-curated Unreleased entry and the reference-style compare links (version sections now come from the workflow, and the generated notes carry their own compare links):

```markdown
# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Version sections below are generated automatically when a release tag is pushed;
see [docs/releasing.md](docs/releasing.md). History before the first release
lives in the git log and the merged pull requests.

## [Unreleased]
```

- [ ] **Step 3: Rewrite `docs/releasing.md`**

Read `docs/releasing.md` in full first. It currently opens by asserting a
release is "a plain version tag `vX.Y.Z` on `main` — nothing more. There is
no GitHub Release object to create and no separate artifact to upload." That
is now false. Rewrite the document so it states:

- A `vX.Y.Z` tag on `main` is still the single release action.
- Pushing that tag now also triggers `.github/workflows/release.yml`, which
  (a) guards that the commit is on `main` and CI is green, (b) publishes a
  GitHub Release marked latest, and (c) for every release after the first,
  writes the notes into `CHANGELOG.md` and commits that to `main` as the
  actions bot.
- The first release publishes a GitHub Release with an `Initial release.`
  body and does not write a changelog section.
- Keep the existing, still-correct material: the `vX.Y.Z` vs `vX.Y.Z-dev.N`
  tag-family table, the load-bearing plain-semver rule (a release tag must
  never carry a suffix), the version-number guidance, and the "what a
  release must contain" note.
- Remove any instruction telling the releaser to edit `CHANGELOG.md` by hand
  before tagging — the workflow owns the changelog now. (The one-line manual
  step added earlier in this branch must go.)
- Under "Not in scope", drop the line that says automating releases /
  generated changelogs is a possible later addition — it now exists.

Keep the document's voice and structure; this is a content correction, not a
new document.

- [ ] **Step 4: Drop the leading blank line in `CODE_OF_CONDUCT.md`**

The canonical download left a blank first line before the `# Contributor
Covenant Code of Conduct` heading. Remove that single leading blank line so
the H1 is line 1. Change nothing else in the file.

- [ ] **Step 5: Verify**

Run: `scripts/test/changelog-insert-release.test.sh && head -1 CODE_OF_CONDUCT.md && grep -c 'no GitHub Release object' docs/releasing.md`
Expected: the test prints `ok:`, the code-of-conduct first line is the `# Contributor Covenant...` heading, and the grep count is `0` (the stale sentence is gone). If a YAML linter (`actionlint`) is available, run it on `.github/workflows/release.yml` and fix any finding.

- [ ] **Step 6: Commit**

```bash
git add .github/workflows/release.yml docs/releasing.md CHANGELOG.md CODE_OF_CONDUCT.md
git commit -m "feat(#67): release workflow publishes a GitHub Release and updates the changelog"
```
