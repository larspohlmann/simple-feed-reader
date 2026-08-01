# Cutting a release

A **release is a plain version tag `vX.Y.Z` on `main`** — that tag push is
still the single release action; nothing else needs preparing by hand. Pushing
it also triggers [`.github/workflows/release.yml`](../.github/workflows/release.yml),
which:

- guards that the tagged commit is on `main` and that CI went green on that
  exact SHA (a tag push proves neither on its own — the workflow's comments
  explain why),
- publishes a GitHub Release for the tag, marked latest, and
- for every release after the first, writes the generated release notes into
  [CHANGELOG.md](../CHANGELOG.md) and commits that to `main` as
  `github-actions[bot]`.

The first release — the one with no earlier `vX.Y.Z` tag to diff against —
publishes a GitHub Release with an `Initial release.` body and does not touch
`CHANGELOG.md`; there is nothing to generate notes from yet.

The `scripts/install.sh` and `scripts/update.sh` helpers do not depend on the
GitHub Release object or on `CHANGELOG.md`. They resolve "the latest release"
straight from git: the highest `vX.Y.Z` tag reachable from `main`.

This is deliberately separate from the two tag families the project already has:

| Tag shape | Lives on | Purpose |
|---|---|---|
| `vX.Y.Z-dev.N` | a `develop` commit | triggers the Strato deploy workflow |
| `vX.Y.Z` | a `main` commit | a release users install and update to |

## Why the exact `vX.Y.Z` shape matters

The scripts pick the release with this filter:

```bash
git tag --merged origin/main --list 'v*' \
  | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' \
  | sort -V | tail -n 1
```

The `grep` is load-bearing. When `develop` is merged into `main`, every
`vX.Y.Z-dev.N` deploy tag on those commits becomes reachable from `main` too. A
"highest tag reachable from main" rule with no shape filter would then pick a
`-dev.N` tag — possibly a newer one than the real release. The plain-semver
pattern is what keeps deploy tags out of the release line, so **a release tag
must never carry a `-dev.N` (or any other) suffix.**

## Steps

From an up-to-date checkout, with CI green on the `develop` commit you want to
ship:

```bash
# 1. Merge develop into main (git-flow: main only ever fast-forwards from develop).
git checkout main
git pull --ff-only
git merge --ff-only develop

# 2. Tag the release. Pick the next version (see below). Annotated tag.
git tag -a v0.5.0 -m 'Release v0.5.0'

# 3. Publish.
git push origin main
git push origin v0.5.0
```

The tag push takes it from there: `.github/workflows/release.yml` guards the
commit, publishes the GitHub Release, and (after the first release) commits
the changelog section back to `main`. Within a minute the one-line installer
also resolves to the new tag, and every existing install moves to it with
`./scripts/update.sh`.

## Choosing the version number

The deploy tags so far are `v0.5.0-dev.N`, so the natural first public release is
`v0.5.0`. If you would rather signal "first stable public version", start at
`v1.0.0` instead. This is a one-time decision; afterwards follow
[semantic versioning](https://semver.org/):

- **patch** (`v0.5.1`) — bug fixes only,
- **minor** (`v0.6.0`) — new features, backward compatible,
- **major** (`v1.0.0`) — breaking changes.

## What a release must contain

The install one-liner is fetched from `main`
(`raw.githubusercontent.com/.../main/scripts/install.sh`) but then checks out the
**latest release tag**. So the very first release must already contain the
`scripts/` directory and this document — otherwise the one-liner would fetch a
script that immediately fails to find a release to install. After that first
release the chicken-and-egg is gone.

The same applies to the Docker production path: every release tag must
include `docker-compose.prod.yml`, `.env.prod.example`, and the `scripts/prod-*.sh`
scripts — `scripts/install.sh` checks for `.env.prod.example` right after
checkout and refuses to continue against a release tag that predates them.
