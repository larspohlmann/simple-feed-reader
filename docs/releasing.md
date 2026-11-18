# Cutting a release

A **release is a plain version tag `vX.Y.Z` on `main`** — that tag push is
the single release action; nothing else needs preparing by hand. Pushing
it also triggers [`.github/workflows/release.yml`](../.github/workflows/release.yml),
which:

- guards that the tagged commit is on `main` and that CI went green on that
  exact SHA (a tag push proves neither on its own — the workflow's comments
  explain why),
- publishes a GitHub Release for the tag, marked latest,
- builds the production images and pushes them to GHCR as
  `ghcr.io/larspohlmann/simple-feed-reader-php:vX.Y.Z` and
  `…/simple-feed-reader-web:vX.Y.Z`, each for `linux/amd64` and `linux/arm64`
  (see [Release images](#release-images)),
- once the images are tagged, posts the release's highlights as a thread to
  Bluesky and Mastodon (see [Announcements](#announcements)),
- for every release after the first, writes the generated release notes into
  [CHANGELOG.md](../CHANGELOG.md) and commits that to `main` as
  `github-actions[bot]`,
- merges that commit onto `develop` as well, and
- redeploys the [project homepage](homepage/README.md) from that `develop`
  commit, so its changelog page shows the release.

The merge onto `develop` is what keeps step 1 below working. The changelog
commit is made on `main`, so without carrying it over, `main` would end every
release one commit ahead of `develop` and the next release's `git merge --ff-only develop`
would refuse. v0.5.2 hit exactly that and needed a manual back-merge
([#266](https://github.com/larspohlmann/simple-feed-reader/issues/266)).

The first release — the one with no earlier `vX.Y.Z` tag to diff against —
publishes a GitHub Release with an `Initial release.` body and does not touch
`CHANGELOG.md`; there is nothing to generate notes from yet.

The `scripts/install.sh` and `scripts/update.sh` helpers do not depend on the
GitHub Release object or on `CHANGELOG.md`. They resolve "the latest release"
straight from git: the highest `vX.Y.Z` tag reachable from `main`.

The project has two tag families, deliberately kept apart:

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

## Announcements

When the `### Highlights` block under `## [Unreleased]` is non-empty at tag time,
[`announce-release.yml`](../.github/workflows/announce-release.yml) posts a thread
to each configured network:
- a root post written for searches and followed tags: it names the product as a
  "self-hosted, open-source RSS reader", carries the block's
  [lead sentence](#the-lead-sentence), links the
  [homepage](https://lars-pohlmann.de/simplefeedreader/) and ends with
  `#RSS #SelfHosted #OpenSource`. Without a lead, or when it does not fit the
  network, the root lists the highlight titles instead, as many as fit followed by
  `… and N more below`. On Bluesky it shows a card with the homepage's share image.
- each titled highlight as a reply, split at sentence ends when it is longer than
  Bluesky's 300 or Mastodon's 500 characters. The lead is not repeated.
- a closing reply that links the GitHub Release.

A release without highlights posts nothing.

Repository secrets (a network whose secrets are missing is skipped with a warning):

| Secret | Value |
|---|---|
| `MASTODON_INSTANCE` | e.g. `https://mastodon.social` |
| `MASTODON_TOKEN` | Preferences → Development → New application, scope `write:statuses` only |
| `BLUESKY_HANDLE` | e.g. `lars.bsky.social` |
| `BLUESKY_APP_PASSWORD` | Settings → Privacy and security → App passwords |
| `BLUESKY_SERVICE` | optional; your PDS if not `https://bsky.social` |

```bash
gh secret set MASTODON_TOKEN
```

Preview a thread locally without posting:

```bash
gh release view v1.9.0 --json body --jq .body \
  | node scripts/announce-release.mjs v1.9.0 https://github.com/larspohlmann/simple-feed-reader/releases/tag/v1.9.0 --dry-run
```

To announce a release by hand, go to Actions → Announce release → Run workflow.
Leave the tag empty for the latest release, or enter an older one. From the
terminal:

```bash
gh workflow run announce-release.yml
```

Re-running within an hour is safe on Mastodon, which returns the posts it
already has. Bluesky always posts again, so delete the duplicates
there.

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

## Release images

Production installs on a release pull these images instead of building them
(`prepare_prod_images` in `scripts/lib.sh`). The image jobs run after the
GitHub Release is published; an install that starts before they finish, or
finds no images, builds on the host and warns, so a failed image job never
breaks an install.

The packages are public, as the repository is, so installs pull them without
a login. GitHub creates a new package private, so after the first release that
publishes one, open it under the repository's Packages and set its visibility
to public; until then every install builds it on the host.

## Highlights

The generated release notes are a flat "What's Changed" list of merged pull
requests. To lead a version's changelog section with a short, hand-written
summary of the headline features — as v0.6.0 does — write a `### Highlights`
block under `## [Unreleased]` in [CHANGELOG.md](../CHANGELOG.md) before you tag,
and merge it to `develop` like any other change:

```markdown
## [Unreleased]

### Highlights

This release adds saved searches and WordPress REST API support.

**Saved searches.** Name a search and keep it as a saved view in the sidebar…

**WordPress REST API support.** When a site exposes the WordPress REST API…
```

At tag time `scripts/changelog-insert-release.sh` lifts that block out of
`[Unreleased]` and places it at the top of the new version section, above the
generated notes, and `scripts/changelog-highlights.sh` leads the GitHub Release
body with the same block. No block means both are just the generated notes.

### The lead sentence

The block's first paragraph, when it has no bold title, is the lead: an intro line
in the changelog, the Release and the homepage, and the per-release part of the
announcement's root post. That post is found through searches and followed tags,
so word the lead and the titles for that, without sounding like an advert:

- One sentence of at most 150 characters, e.g. "This release adds …".
  `scripts/test/announce/changelog-lead.test.mjs` fails CI on a longer one.
- Name each feature by the noun someone would search for, in the lead and in
  the titles: "French and Spanish", "Feed directory", not "Choose what shows
  where". Titles are the root's fallback list.
- No praise adjectives, emoji, exclamation marks or hashtags; the root post
  adds its own tags.

Preview the thread before you merge the highlights:

```bash
awk '/^## \[Unreleased\]/{f=1;next} /^## \[/{f=0} f' CHANGELOG.md \
  | node scripts/announce-release.mjs vX.Y.Z https://example.org --dry-run
```

## Choosing the version number

Follow [semantic versioning](https://semver.org/):

- **patch** (`v0.5.1`) — bug fixes only,
- **minor** (`v0.6.0`) — new features, backward compatible,
- **major** (`v1.0.0`) — breaking changes.

## What a release must contain

The install one-liner is fetched from `main`
(`raw.githubusercontent.com/.../main/scripts/install.sh`) but then checks out the
**latest release tag** and runs that tag's `scripts/lib.sh`, so every release
tag must contain the `scripts/` directory.

The same applies to the Docker production path: every release tag must
include `docker-compose.prod.yml`, `.env.prod.example`, and the `scripts/prod-*.sh`
scripts — `scripts/install.sh` checks for `.env.prod.example` right after
checkout and refuses to continue against a release tag that predates them.
