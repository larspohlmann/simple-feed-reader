# #1508 — Unplayable video fallback Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When the browser cannot play a body video (AV1 on an older iPhone, ProRes anywhere), the reader replaces the dead player with a link card to the original page.

**Architecture:** The reader view already has one capturing `error` listener on the rendered body, which sends broken images to the image proxy. Media errors do not bubble either, so the same listener also hands a failed `<video>` (or the last `<source>` of one) to a new `replaceUnplayableVideo()` in `reader/article/decorators/unplayable-videos.ts`. That function swaps the video for the `.link-card` markup #1499 already styles: the poster, a title, a line of text and the host. The link goes to the entry's original page, or to the video file when the entry has none. A listener, not a post-render decorator, because a video can fail while loading metadata, before `decorateArticle`'s microtask runs.

**Tech Stack:** Angular 20 signals, Transloco, Jest (jsdom) in the Docker frontend container.

**Spec:** GitHub issue #1508 (no separate spec; bounded change, direction agreed in the issue).

## Global Constraints

- One rule for every source and codec: no host or codec list.
- HLS playlists are hls-streams' business: hls.js swaps a `.m3u8` src for a MediaSource on first play, and the native attempt before that may raise `error`. A video hls-streams manages (`isHlsManaged`) is never replaced.
- No `bypassSecurityTrustHtml`; the fallback is built with DOM APIs, never with an HTML string.
- `reader-view.component.ts` sits near ESLint's 300-line cap: the listener grows by one call, and the logic lives in the decorators folder.
- No new SCSS: the fallback reuses `.link-card` (reader-view.component.content.scss).
- Frontend tests run only in the Docker container: `docker compose exec -T frontend npm run check`.

---

### Task 1: `replaceUnplayableVideo` and its wiring

**Files:**
- Create: `frontend/src/app/reader/article/decorators/unplayable-videos.ts`
- Create: `frontend/src/app/reader/article/decorators/unplayable-videos.spec.ts`
- Modify: `frontend/src/app/reader/article/decorators/hls-streams.ts` (export `isHlsManaged`)
- Modify: `frontend/src/app/reader/article/reader-view/reader-view.component.ts` (the capturing error listener)
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json` (`reader.unplayableVideo.title`, `reader.unplayableVideo.action`)
- Test: `frontend/src/app/reader/article/reader-view/reader-view.component.spec.ts`

**Interfaces:**
- Produces: `replaceUnplayableVideo(target: EventTarget | null, fallback: () => UnplayableVideoFallback): void` (lazy, so an image error builds nothing), with `interface UnplayableVideoFallback { pageUrl: string | null; title: string; action: string }`.
- Produces: `isHlsManaged(video: HTMLVideoElement): boolean` exported from `hls-streams.ts`.

- [ ] **Step 1: Write the failing unit tests** (`unplayable-videos.spec.ts`)
  - A `<video src=a.mp4 poster=p.jpg>` error → replaced by `figure.link-card > a[href=pageUrl][target=_blank][rel="noopener noreferrer"]`, holding `img[src=p.jpg]`, `strong` = title, `span` = action, `small` = the page's host.
  - No pageUrl → the link goes to the video's src.
  - No poster → no `img`.
  - A `<source>` error replaces its video only when it is the last source; an earlier source's error leaves the video in place.
  - A video `attachHlsStreams` armed keeps its place on error.
  - A target that is neither a video nor a source (an `img`) is ignored.
- [ ] **Step 2: Run them, expect FAIL** (module missing): `docker compose exec -T frontend npx jest src/app/reader/article/decorators/unplayable-videos.spec.ts`
- [ ] **Step 3: Implement** `unplayable-videos.ts` with DOM APIs (`createElement`, `textContent`, `replaceWith`). Export `isHlsManaged` from `hls-streams.ts`.
- [ ] **Step 4: Run, expect PASS.**
- [ ] **Step 5: Wire it.** In the reader view's capturing `error` listener, call `replaceUnplayableVideo(event.target, …)` beside the image recovery. `pageUrl` is `this.entry()?.url ?? null`; the labels come from `this.i18n.translate`. Add the en/de keys.
- [ ] **Step 6: Component test** (`reader-view.component.spec.ts`): mount an entry whose body holds `<video src="https://x.test/clip.mp4">`, dispatch `new Event('error')` on it, and expect a `.content figure.link-card a[href=<entry url>]` and no `video`. Break-test it: remove the wiring call and confirm the test fails, then restore.
- [ ] **Step 7: Gate:** `docker compose exec -T frontend npm run check`.
- [ ] **Step 8: Commit** `feat(#1508): replace a video the browser cannot play with a link to the original page`.

### Task 2: Real render

- [ ] Open entry 577254 (Futurism, AV1) in the built-in browser at phone width on :4200. If Chromium plays AV1, there is no error to see; in that case, check the card on a video whose src 404s (stub the route or edit a fixture in the page through devtools), and confirm the link-card look in light and dark.
