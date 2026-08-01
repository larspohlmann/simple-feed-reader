# Repo meta files for a public GitHub repository (issue #67)

**Date:** 2026-08-01
**Issue:** [#67](https://github.com/larspohlmann/simple-feed-reader/issues/67)
**Branch:** `feature/67-repo-meta-docs`

## Goal

Add the standard meta files that a public open-source repository needs. Keep
every new file consistent with the workflow that `CLAUDE.md`,
`docs/local-docker.md`, and `docs/releasing.md` already document.

## Decisions

| Question | Decision |
|---|---|
| License | MIT, "Copyright (c) 2026 Lars Pohlmann" |
| Security reports | GitHub private vulnerability reporting (no public email) |
| Code of conduct | Contributor Covenant 2.1, contact `lars.pohlmann@googlemail.com` |
| Issue templates | Skipped |
| PR template | Skipped |
| README extras | CI badge, screenshot, CHANGELOG link (no license badge) |
| Changelog | "Keep a Changelog" format, starts empty from now |
| Contribution stance | Open or claim an issue first, then send a PR |

## Files to add

### LICENSE

Plain MIT text with the copyright line above. No markdown extension, so GitHub
detects the license.

### CONTRIBUTING.md

Documents the real workflow; links to existing docs instead of duplicating
them. Sections:

- **Before you start** — open or claim a GitHub issue first. PRs without an
  issue can be declined.
- **Setup** — pointer to `docs/local-docker.md` for the Docker stack and to
  `README.md` for the one-line installer.
- **Branch and commit conventions** — git-flow: feature branches off
  `develop`, never `main`. Branch names embed the issue number
  (`feature/67-repo-meta-docs`). Commit style `type(#issue): summary`, as in
  the git history.
- **Quality gate** — backend: `composer check` (cs + stan), `composer md`,
  `php bin/phpunit` natively (SQLite) and
  `docker compose exec php vendor/bin/phpunit` (MySQL). Frontend:
  `npm run check`. Every touched `src` file must be PHPMD-clean.
- **PRs** — target `develop`; "Closes #NN" in the body.

### CODE_OF_CONDUCT.md

Contributor Covenant 2.1, unmodified apart from the contact:
`lars.pohlmann@googlemail.com`.

### SECURITY.md

- Report vulnerabilities through GitHub's private "Report a vulnerability"
  form; do not open public issues.
- Names the sensitive surface: authentication (JWT, ALTCHA), OAuth/OIDC
  sign-in, outbound feed fetching (SSRF boundary).
- Supported version: the latest release and `develop`.
- **Manual step:** enable "Private vulnerability reporting" in the repository
  settings (one `gh api` call, needs Lars's OK, or one checkbox in the web
  UI).

### CHANGELOG.md

"Keep a Changelog 1.1" format. Starts with only an `Unreleased` section;
history before today stays in git. `docs/releasing.md` gets one extra step:
move `Unreleased` items into a new version section when a release is cut.

## README changes

- CI status badge for `.github/workflows/ci.yml` on `develop`, at the top.
- A screenshot of the reader UI, stored at `docs/images/reader.png`,
  referenced near the top of the README. Capture process: start the Docker
  stack, sign in with seeded demo data, capture with Playwright. Lars approves
  the image before it lands.
- Links to CONTRIBUTING.md, LICENSE, and CHANGELOG.md in the documentation
  section.

## Out of scope

- Issue templates and a PR template (explicitly skipped).
- A license badge.
- Backfilled changelog history.
- Any workflow or CI changes.

## Acceptance criteria (from #67, adjusted to decisions)

- [ ] LICENSE (MIT) added
- [ ] CONTRIBUTING.md, CODE_OF_CONDUCT.md, SECURITY.md added
- [ ] CHANGELOG.md added; releasing docs updated
- [ ] README: CI badge, screenshot, meta-file links
- [ ] Private vulnerability reporting enabled on the repo
- [ ] New docs consistent with `docs/local-docker.md` and the real workflow
