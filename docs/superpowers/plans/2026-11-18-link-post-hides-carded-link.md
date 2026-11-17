# Link post hides its carded link Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** In the magazine `link` block, a post's text no longer ends in the link its link card already shows (#2323).

**Architecture:** A pure text helper in `reader/entry/entry-text.ts` drops the trailing run of link tokens from a string. `EntryLinkComponent` runs its headline and dek through it only when it renders a card (`entry().linkCards[0]`); the pending placeholder and the no-card case keep the text as it is.

**Tech Stack:** Angular 20 (signals, standalone), Jest.

**Spec:** issue #2323.

## Global Constraints

- Only the trailing link run goes; a link inside the sentence stays.
- Scope is the magazine `link` block (`blocks/entry-link`) only. Thread, quoting, list rows and Trending mini cards are untouched.
- A link token is `https?://…` (any rest, including an excerpt cut-off like `https://www.`) or a scheme-less `host.tld/…` with a path (`politi.co/4kVc0CX`, `slate.trib.al/PYBaii7`). A bare word with a dot and no slash (`Node.js`) is not a link.
- Frontend unit tests run with `./node_modules/.bin/jest` from `frontend/` in the scratchpad worktree (symlinked `node_modules`); `npm run check` before the PR.

## Review Focus

- Post that is only a URL → headline empty, the `<p class="title">` is not rendered, the card heads the block.
- Two trailing links (`… https://a.test/x politi.co/y`) → both go.
- Hashtags after the link (`… https://heise.de/x #Apple`) → nothing goes (the run must be trailing); pinned so a later "smarter" rule is a conscious choice.
- Trailing whitespace/newline after the link → still stripped, result trimmed at its end.
- Pending entry without card → text unchanged.

---

### Task 1: `withoutTrailingLinks`

**Files:**
- Modify: `frontend/src/app/reader/entry/entry-text.ts`
- Test: `frontend/src/app/reader/entry/entry-text.spec.ts`

**Interfaces:**
- Produces: `export function withoutTrailingLinks(text: string): string`

- [ ] **Step 1: Write the failing tests** in a `describe('withoutTrailingLinks')`:
  - `'Texas issues air permit politi.co/3WPZZF1'` → `'Texas issues air permit'`
  - `'could do exactly that. https://www.'` → `'could do exactly that.'`
  - `'Read this https://a.test/x slate.trib.al/PYBaii7\n'` → `'Read this'`
  - `'https://a.test/only'` → `''`
  - `'Read https://a.test/x and comment'` → unchanged
  - `'Outages hit Apple https://heise.de/x #Apple'` → unchanged
  - `'Built with Node.js'` → unchanged
- [ ] **Step 2:** `./node_modules/.bin/jest src/app/reader/entry/entry-text.spec.ts` — FAIL, `withoutTrailingLinks` is not exported.
- [ ] **Step 3: Implement** — repeatedly split off the last whitespace-separated token while it matches `/^(?:https?:\/\/\S*|(?:[\w-]+\.)+[a-z]{2,}\/\S*)$/i`; return the remainder `trimEnd()`ed.
- [ ] **Step 4:** same command — PASS.
- [ ] **Step 5: Commit** `feat(#2323): a text helper drops its trailing links`

### Task 2: the link block uses it when a card renders

**Files:**
- Modify: `frontend/src/app/reader/list/magazine/blocks/entry-link/entry-link.component.ts`, `.html`
- Test: `frontend/src/app/reader/list/magazine/blocks/entry-link/entry-link.component.spec.ts`

**Interfaces:**
- Consumes: `withoutTrailingLinks` (Task 1).

- [ ] **Step 1: Write the failing tests** with the spec's `entry()`/`mount()`:
  - `excerpt: 'Worth a read https://news.test/story'`, cards present → `.title` text is `'Worth a read'`.
  - `excerpt: 'https://news.test/story'`, cards present → no `.title` element.
  - `titleDerived: false, title: 'A headline', excerpt: 'Summary politi.co/x'`, cards present → `.dek` is `'Summary'`.
  - `excerpt: 'Worth a read https://news.test/story', linkCards: [], pending: true` → `.title` keeps the URL.
- [ ] **Step 2:** `./node_modules/.bin/jest src/app/reader/list/magazine/blocks/entry-link` — the new tests FAIL.
- [ ] **Step 3: Implement** — in `EntryLinkComponent` add `protected readonly title = computed(...)` and `protected readonly dek = computed(...)` returning `withoutTrailingLinks(this.headline())` / `withoutTrailingLinks(this.snippet())` when `this.entry().linkCards.length > 0`, else the unchanged value; the template's `@if`s and interpolations read `title()` / `dek()` instead of `headline()` / `snippet()`.
- [ ] **Step 4:** same command — PASS; then `npm run check` green.
- [ ] **Step 5: Commit** `feat(#2323): a magazine link post hides the link its card shows`
- [ ] **Step 6: Live check** at `localhost:4200` magazine view once the checkout is free (another session holds it): a Politico post (`… politi.co/…`) shows no trailing link above its card.
