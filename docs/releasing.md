# Cutting a release

A **release is a plain version tag `vX.Y.Z` on `main`** — nothing more. There is
no GitHub Release object to create and no separate artifact to upload. The
`scripts/install.sh` and `scripts/update.sh` helpers resolve "the latest
release" straight from git: the highest `vX.Y.Z` tag reachable from `main`.

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

Before merging, update [CHANGELOG.md](../CHANGELOG.md) on `develop`: rename
the `## [Unreleased]` section to the new version with the date
(`## [vX.Y.Z] - YYYY-MM-DD`), add its compare link at the bottom, and start a
fresh empty `## [Unreleased]` section above it.

```bash
# 2. Merge develop into main (git-flow: main only ever fast-forwards from develop).
git checkout main
git pull --ff-only
git merge --ff-only develop

# 3. Tag the release. Pick the next version (see below). Annotated tag.
git tag -a v0.5.0 -m 'Release v0.5.0'

# 4. Publish.
git push origin main
git push origin v0.5.0
```

Within a minute the one-line installer resolves to the new tag, and every
existing install moves to it with `./scripts/update.sh`.

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

## Not in scope

Automating releases (a workflow that tags on merge, generated changelogs) is a
possible later addition. For now the steps above are done by hand, which keeps
the release moment an explicit, reviewed decision.
