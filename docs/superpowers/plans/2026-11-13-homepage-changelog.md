# Homepage changelog page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The homepage gets `changelog.html`, generated from `CHANGELOG.md` when the site is staged, and every `vX.Y.Z` release redeploys the homepage from the `develop` commit that carries its changelog.

**Architecture:** A dependency-free Node script renders the narrow Markdown dialect `CHANGELOG.md` uses into a static page built on a template next to it. The shared look (tokens, base, nav, theme switch, footer) moves out of `index.html` into `base.css` and `theme.js`, which both pages link. `deploy-homepage.yml` becomes callable with a `ref`; `release.yml` calls it with the SHA its develop-sync step pushed.

**Tech Stack:** Node 22 (ES modules, `node:test`, no npm deps), GitHub Actions reusable workflows, static HTML/CSS.

**Spec:** GitHub issue #2207 (body quoted in the PR) plus the design agreed in session: render at stage time with a Node script; highlights open, the rest of a release collapsed; `[Unreleased]` hidden; each version links to its GitHub Release.

## Global Constraints

- `CHANGELOG.md` is the only source. No generated `changelog.html` is committed; `docs/homepage/changelog.html` is gitignored (local previews write there).
- No npm dependencies and no build step for the site beyond the stage step.
- The release deploys exactly `needs.release.outputs.develop_sha`, never `github.sha` or the tip of `develop` at deploy time.
- A homepage-only push to `develop` keeps deploying as today.
- Release links: `https://github.com/larspohlmann/simple-feed-reader/releases/tag/<version>`.
- The page keeps `index.html`'s tokens, typography and light/dark/theme-switch behaviour (`localStorage` key `sfr.homepage.theme`), and works at 375 px with no horizontal scroll.
- Comments: one line, only where a reader would otherwise get it wrong (CLAUDE.md "Default to no comment").
- Commits: `type(#2207): …`, no attribution lines.

## Review Focus

1. A PR title or highlight containing `<`, `>`, `&` or `"` (e.g. "use `<details>` for …") must render as text, never as markup → Task 2 test `escapes HTML in titles and paragraphs`.
2. snake_case and `*` inside words (`user_preferences`, `array_first()`) must not turn into emphasis → Task 2 test `leaves underscores and lone asterisks alone`.
3. Old hand-written releases (v0.5.x) have `### Changed` / `### Fixed` with `- ` bullets wrapped over continuation lines, and no "What's Changed" → Task 2 test `joins continuation lines into one list item`, Task 3 test on the real file.
4. `[Unreleased]` with content (between release and tag) must stay hidden → Task 3 test `skips Unreleased even when it has content`.
5. Long bare URLs and PR titles at phone width must wrap, not scroll → Task 4 visual check at 375 px (base.css keeps `overflow-wrap:anywhere` on `body`).

---

### Task 1: Shared homepage base (`base.css`, `theme.js`)

Pure extraction; `index.html` must look and behave identically afterwards.

**Files:**
- Create: `docs/homepage/base.css`, `docs/homepage/theme.js`
- Modify: `docs/homepage/index.html` (the `<style>` block from `:root{` through the `.theme-switch` rules, the footer rules, and the theme-switch IIFE in the closing `<script>`)

- [ ] **Step 1:** Screenshot `docs/homepage/index.html` (file URL) at 1280 and 375 px wide, light and dark, top of page and footer. Keep them in the scratchpad.
- [ ] **Step 2:** Move into `base.css`, verbatim: the three token blocks (`:root`, the dark media query, `:root[data-theme="dark"]`), `*`, `html`, `body`, `a`, `img`, `.wrap`, `h1,h2,h3`, `h2`, `p`, `.muted`, `.eyebrow`, the `/* nav */` rules, `.btn*`, `.theme-switch*`, and the `footer` rules. Everything page-specific stays inline. Link it with `<link rel="stylesheet" href="base.css">` before the remaining inline `<style>`.
- [ ] **Step 3:** Move the theme-switch IIFE (the one using `systemDark`, `isDark`, `label`) into `theme.js`, loaded with `<script src="theme.js" defer></script>`. The pre-paint snippet in `<head>` stays inline in each page (it must run before first paint).
- [ ] **Step 4:** Re-take the four screenshots and compare with Step 1; toggle the theme switch and reload to confirm the choice persists. Expected: no visible difference.
- [ ] **Step 5:** Commit: `refactor(#2207): homepage base styles and theme switch in shared files`.

### Task 2: Changelog Markdown renderer

**Files:**
- Create: `scripts/homepage-changelog/markdown.mjs`
- Test: `scripts/test/homepage-changelog/markdown.test.mjs`
- Modify: `.github/workflows/ci.yml` (the "Announce script tests" step)

**Interfaces:**
- Produces: `escapeHtml(text: string): string`, `renderInline(text: string): string`, `renderBlocks(markdown: string): string`.

Dialect, and nothing more: blank-line-separated blocks; `### Heading` → `<h4>`; `## Heading` (non-version, e.g. "What's Changed") → `<h4>`; lines starting `* ` or `- ` → `<ul><li>`, an indented line continuing the previous item; anything else → `<p>` with its lines joined by a space. Inline, applied after escaping: `` `code` ``, `**bold**`, `[text](url)`, bare `https://…` URLs autolinked (not inside an already-built link). A PR bullet `* TITLE by @USER in https://github.com/…/pull/N` renders as `<li>TITLE <a href="…/pull/N">#N</a></li>` (author dropped: it is always the maintainer and repeats on every line).

- [ ] **Step 1: Write the failing tests** (`node:test`, `assert/strict`), named:
  - `escapes HTML in titles and paragraphs` — `renderInline('use <details> & "x"')` equals `'use &lt;details&gt; &amp; &quot;x&quot;'`.
  - `renders code, bold and links` — `renderInline('**A.** See [docs](https://e.org/a) and `npm ci`.')` equals `'<strong>A.</strong> See <a href="https://e.org/a">docs</a> and <code>npm ci</code>.'`.
  - `leaves underscores and lone asterisks alone` — `renderInline('split user_preferences, a*b')` is unchanged apart from escaping.
  - `autolinks a bare URL once` — `renderInline('see https://e.org/x.')` equals `'see <a href="https://e.org/x">https://e.org/x</a>.'`, and a `[t](https://e.org)` link is not double-wrapped.
  - `renders a PR bullet as title and number` — `renderBlocks('* fix(#1): a <b> by @larspohlmann in https://github.com/o/r/pull/12')` equals `'<ul><li>fix(#1): a &lt;b&gt; <a href="https://github.com/o/r/pull/12">#12</a></li></ul>'`.
  - `joins continuation lines into one list item` — two `- ` items, the first wrapped over an indented second line, give a `<ul>` with exactly two `<li>`, the first containing both lines' text joined by one space.
  - `wraps paragraph lines into one paragraph` — two consecutive lines → one `<p>`; a blank line → two.
  - `renders headings as h4` — `'### Fixed'` and `"## What's Changed"` each give `<h4>…</h4>` (apostrophe escaped as `&#39;`).
- [ ] **Step 2:** Run `node --test "scripts/test/homepage-changelog/*.test.mjs"`. Expected: FAIL (module not found).
- [ ] **Step 3:** Implement the three exports in `markdown.mjs`.
- [ ] **Step 4:** Run the same command. Expected: all pass.
- [ ] **Step 5:** In `ci.yml`, rename the step to "Node script tests" and run `node --test "scripts/test/announce/*.test.mjs" "scripts/test/homepage-changelog/*.test.mjs"` (quoted globs: the directory form fails on Node 22).
- [ ] **Step 6:** Commit: `feat(#2207): render the changelog's Markdown dialect to HTML`.

### Task 3: Changelog page generator

**Files:**
- Create: `scripts/homepage-changelog/changelog.mjs`, `scripts/homepage-changelog/page.html`, `scripts/homepage-changelog.mjs` (CLI)
- Test: `scripts/test/homepage-changelog/changelog.test.mjs`
- Modify: `.gitignore` (add `/docs/homepage/changelog.html`)

**Interfaces:**
- Consumes: `renderBlocks`, `escapeHtml` from Task 2.
- Produces: `parseReleases(markdown: string): Array<{version: string, date: string, highlights: string, changes: string}>` (raw Markdown per part; `highlights` is the body of `### Highlights` up to the next heading, `changes` everything else in the section; newest first, file order). `renderReleasesHtml(releases): string`. `renderChangelogPage(markdown: string, template: string): string` (replaces the single `{{releases}}` placeholder; throws if the template lacks it or the file yields no release). CLI: `node scripts/homepage-changelog.mjs <CHANGELOG.md> <out.html>`, reads `page.html` beside the module.

Per release, `renderReleasesHtml` emits `<section class="release" id="vX.Y.Z">` with an `<h2>` linking the version to its GitHub Release, a `<time datetime>` with the date, the rendered highlights (if any) in `<div class="highlights">`, and the rendered changes inside `<details><summary>All changes</summary>…</details>` (omitted when empty).

`page.html`: same `<head>` basics as `index.html` (charset, viewport, favicons, theme-color metas, the inline pre-paint theme snippet, `base.css`), title `Changelog — simple feed reader`, canonical `https://lars-pohlmann.de/simplefeedreader/changelog.html`, the nav with the brand linking `./` and the theme switch, `<main class="wrap">` with an `<h1>Changelog</h1>`, a one-line intro linking GitHub Releases, `{{releases}}`, the footer, `theme.js`. Page-specific CSS inline: readable measure (~720 px) for releases, release spacing, `<details>` summary styled as a muted link, `code` on `--surface-sunken`.

- [ ] **Step 1: Write the failing tests**, named:
  - `parses versions and dates in file order` — fixture with `[Unreleased]`, `[v1.1.0] - 2026-02-01`, `[v1.0.0] - 2026-01-01` → versions `['v1.1.0','v1.0.0']`, dates match.
  - `skips Unreleased even when it has content` — `[Unreleased]` holding a `### Highlights` block yields no release for it.
  - `splits highlights from the rest` — a release with `### Highlights` then `## What's Changed` → `highlights` holds only the highlight paragraphs, `changes` starts with `## What's Changed`.
  - `links each version to its GitHub Release` — output contains `href="https://github.com/larspohlmann/simple-feed-reader/releases/tag/v1.1.0"`.
  - `omits the details element for an empty release` — a version header with no body renders no `<details>`.
  - `renders the real CHANGELOG.md` — reading `../../../CHANGELOG.md`: release count equals the number of `## [v` lines, the first is the newest version in the file, the page contains no `Unreleased`, the count of `class="highlights"` equals the number of releases with a `### Highlights` heading, and `{{releases}}` is gone.
- [ ] **Step 2:** Run the tests. Expected: FAIL.
- [ ] **Step 3:** Implement `changelog.mjs`, `page.html` and the CLI; add the `.gitignore` line.
- [ ] **Step 4:** Run the tests. Expected: all pass.
- [ ] **Step 5:** Commit: `feat(#2207): generate the homepage changelog page from CHANGELOG.md`.

### Task 4: Footer link, visual check

**Files:**
- Modify: `docs/homepage/index.html` (footer "Changelog" link → `changelog.html`)
- Test: `scripts/test/homepage-changelog/changelog.test.mjs`

- [ ] **Step 1:** Add test `the homepage footer links the changelog page` — `docs/homepage/index.html` contains `href="changelog.html"` and no `blob/develop/CHANGELOG.md`. Run it. Expected: FAIL.
- [ ] **Step 1b:** Change the link. Run the node tests. Expected: all pass.
- [ ] **Step 2:** `node scripts/homepage-changelog.mjs CHANGELOG.md docs/homepage/changelog.html`, open it via file URL at 1280 and 375 px, light and dark, plus the theme switch: no horizontal scroll (`document.documentElement.scrollWidth <= innerWidth`), highlights readable, a `<details>` opens, the v0.5.x sections render their lists. Send the screenshots to the user.
- [ ] **Step 3:** Commit: `feat(#2207): the homepage footer links the changelog page`.

### Task 5: Deploy wiring and docs

**Files:**
- Modify: `.github/workflows/deploy-homepage.yml`, `.github/workflows/release.yml`, `docs/homepage/README.md`, `docs/releasing.md`

- [ ] **Step 1: `deploy-homepage.yml`.** Add `workflow_call` with input `ref` (string, required). Push `paths` gain `CHANGELOG.md` and `scripts/homepage-changelog/**`, `scripts/homepage-changelog.mjs`. Checkout gets `ref: ${{ inputs.ref || github.sha }}`. Add `actions/setup-node@v5` (node 22) before staging; the stage step runs `node scripts/homepage-changelog.mjs CHANGELOG.md site/changelog.html` and `test -f site/changelog.html`. Update the header comment: the release calls this workflow because its `GITHUB_TOKEN` push to `develop` starts no workflow.
- [ ] **Step 2: `release.yml`.** Give the carry step `id: develop` and, after its push, `echo "sha=$(git rev-parse HEAD)" >> "${GITHUB_OUTPUT}"`; the `release` job declares `outputs: develop_sha: ${{ steps.develop.outputs.sha }}`. Add job `homepage` (`needs: release`, `if: needs.release.outputs.develop_sha != ''`, `uses: ./.github/workflows/deploy-homepage.yml`, `with: ref: ${{ needs.release.outputs.develop_sha }}`, `secrets: inherit`, `permissions: contents: read`). Update the file's header comment line to mention the homepage.
- [ ] **Step 3:** Run `actionlint` on both files if installed (otherwise say in the PR that it went unchecked), and parse both as YAML (`ruby -ryaml -e 'YAML.load_file(ARGV[0])'`). Expected: no errors.
- [ ] **Step 4: Docs.** `docs/homepage/README.md`: a "Changelog page" section (generated from `CHANGELOG.md` at stage time by `scripts/homepage-changelog.mjs`, preview command, gitignored output) and the Deployment paragraph names the release as a third trigger. `docs/releasing.md`: a bullet after the develop-merge bullet — "then redeploys the project homepage from that `develop` commit, so its changelog page shows the release".
- [ ] **Step 5:** Commit: `ci(#2207): the release redeploys the homepage with its changelog`.

### Task 6: Gates

- [ ] Run `node --test "scripts/test/announce/*.test.mjs" "scripts/test/homepage-changelog/*.test.mjs"` and `scripts/test/changelog-insert-release.test.sh`, `scripts/test/changelog-highlights.test.sh`. Expected: all pass.
- [ ] `/simplify`, then open the PR (`Closes #2207`), noting that the `workflow_call` path is only exercised by the next real release and the push path by the merge itself.
