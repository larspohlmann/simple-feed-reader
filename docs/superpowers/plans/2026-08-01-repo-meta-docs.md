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
- CHANGELOG.md (Keep a Changelog, starts empty) + release-step wiring
- README: CI badge, UI screenshots, meta-file links

Skipped by decision: issue templates, PR template, license badge.

Closes #67
EOF
)"
```

- [ ] **Step 4: Verify the PR renders correctly**

Open the PR's "Files changed" view: the README badge resolves (may show "no runs" until CI runs on the branch), both screenshot images render, and GitHub shows "MIT license" in the repo sidebar once merged.
