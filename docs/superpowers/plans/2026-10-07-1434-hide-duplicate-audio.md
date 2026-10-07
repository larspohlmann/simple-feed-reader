# Hide the in-body duplicate of the enclosure player — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** In Reader mode, an article-body `<audio>` that plays the entry's audio enclosure no longer shows next to Listen (#1434).

**Architecture:** One more article decorator, `removeEnclosurePlayers(host, enclosureUrl)` in `reader/article/decorators/enclosure-players.ts`, run by `decorateArticle` before the narration and media passes. The reader view passes the url of `EntryAudio.attachment()`, the same enclosure its Listen row plays. The API is untouched, so a client without Listen keeps the page's player.

**Tech Stack:** Angular 20, Jest (jsdom).

**Spec:** GitHub issue #1434.

## Rulings

- **Remove, not hide.** `[hidden]` loses to an author `audio { display: block }`, and a hidden `preload` player can still fetch. Removing the `<audio>` also drops its fallback link. Only the `<audio>` goes: on the real page (Lex Fridman #502) its parent `<div>` holds the whole article.
- **Same file:** the `<audio src>` or any child `<source src>` whose scheme, host and path match the enclosure's. Query string and fragment are ignored (WordPress appends `?_=N`). `URL` parsing lowercases the scheme and host. An unparseable url never matches.
- **No enclosure:** pass `null`; the decorator does nothing.

## Global Constraints

- Prettier 100 columns; `npm run check:static && npx jest --maxWorkers=3` green inside the Docker frontend container.
- Comments only where a reader would get the code wrong without them.
- Commit format `feat(#1434): lower-case summary`, no attribution lines.

---

### Task 1: `removeEnclosurePlayers`, wired into the reader view

**Files:** Create `frontend/src/app/reader/article/decorators/enclosure-players.ts` (+ spec). Modify `decorate-article.ts` (new `enclosureUrl: string | null` parameter) and `article/reader-view/reader-view.component.ts` (passes `this.audio.attachment()?.url ?? null`).

**Tests (spec):** same file via `<source>` → removed; same file with `?_=1` → removed; same file via `<audio src>` with an upper-case scheme → removed; a different file → kept; no enclosure → kept; the parent and its other content survive.

**Real render:** Lex Fridman #502 (`?subscription=1704&entry=569743-…`) in Reader mode shows only Listen and no in-body player.
