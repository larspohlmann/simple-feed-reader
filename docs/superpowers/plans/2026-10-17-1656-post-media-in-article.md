# Post media in the article view (#1656) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A social post opened in the article view shows its own attached photos, videos and GIFs through `app-post-media`, for Mastodon (API and RSS) and Bluesky alike.

**Architecture:** The article view renders `entry.media` (the post's own media, already sent on every entry) through the shared `app-post-media` component right under the body, after removing what is already on screen: a video's poster, a link card's picture, and any medium the body HTML itself shows (Bluesky entries stored before this change). Bluesky ingest stops writing `<figure class="post-images">` / `<figure class="post-video">` + "Watch on Bluesky" into `contentHtml`, so new Bluesky posts take the same component path (GIFs lazy-start and pause on tap, videos get HLS + the article's hand-played coordination).

**Tech Stack:** Symfony 7.4 / PHP 8.4 (backend), Angular 20 standalone + signals, Jest (frontend).

**Spec:** GitHub issue larspohlmann/simple-feed-reader#1656 (no separate spec; design decided in session — see "Design decisions" below).

## Design decisions (autonomous, record in the PR body)

- **Which entries:** only posts (`isPost(entry)`, i.e. `titleDerived`). Posts open original-only (`ArticleSource.open` → `load.idle()`), so they never have a hero image and `displayHtml()` is always the feed body (or the summary while it loads). Articles keep today's behaviour; their `media[0]` is the lead image, which they show as hero / inside the body.
- **What is removed from `entry.media`:** (1) images whose URL is some medium's `previewImageUrl` (a video's/GIF's poster or still — same rule as `collectSharedMedia` in `list/magazine/magazine-slot-fit.ts`); (2) images whose URL equals a link card's `imageUrl` (a Bluesky link share stores the card thumbnail as `media[0]`); (3) media whose URL is the `src` of an `<img>`, `<video>` or `<source>` in the body HTML (Bluesky entries ingested before this change still carry their figures). No cap of four — the article shows every attachment.
- **When:** only once the feed body has settled (`ok` or `error`). While it is loading `displayHtml()` is the summary, and rule (3) would flash old Bluesky media in and out.
- **Where:** directly after `<app-article-content>`, before the quoted post / link cards — the order the comments panel already uses (body, media, quote, link card).
- **Autoplay:** unchanged. `AUTOPLAY_REGION` stays the comments section; the post's own videos are hand-played (as body videos are today), GIFs play near the viewport on their own (`app-gif`).
- **Backend:** `PostEmbedRenderer` keeps building `media` and `leadImage` for images / GIF / video but returns empty `html` for them. The fallback paragraph ("View embedded content on Bluesky") for unknown embeds and quotes stays. Stored entries are not rewritten (no migration) — rule (3) covers them.
- The `.post-images` / `.post-video` rules in `article-content.component.scss` stay: stored entries still use them.

## Global Constraints

- Follow `CLAUDE.md` (Clean Code PHP rules, no new comments unless they clear the bar, PHPMD-clean touched files, `HTTP_*` constants, frontend Prettier 100 col, no hex colours / px in scss).
- Commit format: `type(#1656): lower-case summary`. No Claude attribution anywhere.
- Work on branch `fix/1656-post-media-in-article` in the main checkout (no worktree).
- Frontend tests run inside Docker: `docker compose exec -T frontend npx jest <path>`; the gate is `docker compose exec -T frontend npm run check`.
- Backend tests natively from `backend/`: `php bin/phpunit <path>`.

---

### Task 1: Bluesky ingest stops writing media into the body

**Files:**
- Modify: `backend/src/Service/Bluesky/PostEmbedRenderer.php` (`images()`, `external()` GIF branch, `video()`; delete `figure()`, `imageTag()`, `dimensions()`, `WATCH_LABEL` once unused)
- Modify tests that assert the old HTML: `backend/tests/Service/Bluesky/PostEmbedRendererTest.php`, `backend/tests/Service/Bluesky/EntryEmbedWriter/EntryEmbedWriterTest.php`, `backend/tests/Service/Bluesky/PostEnricherTest.php`, `backend/tests/Service/Refresh/FeedOutcomePersisterBlueskyTest.php`, `backend/tests/Http/EntryJsonTest.php` (grep each for `post-images`, `post-video`, `Watch on Bluesky`)

**Interfaces:**
- Produces: `PostEmbedRenderer::render()` returns a `RenderedEmbedModel` whose `html` is `''` for an images / GIF / video embed; `media` and `leadImage` unchanged. `recordWithMedia` → quote html (normally `''`) followed by `''`.

- [ ] **Step 1: Change the tests first.** In `PostEmbedRendererTest`, for every images/GIF/video case replace the expected HTML with `''` and keep (or add) assertions on `->media` (URLs, kinds, `animated`, `previewImageUrl`, width/height) and `->leadImage`. In the other four test files, replace expectations that contain `<figure class="post-images">…</figure>`, `<figure class="post-video">…</figure>` or the `Watch on Bluesky` paragraph with the body text alone, and where a test asserted the image via the HTML, assert it on `$entry->getMedia()` instead.
- [ ] **Step 2: Run them, expect FAIL** — `cd backend && php bin/phpunit tests/Service/Bluesky tests/Service/Refresh/FeedOutcomePersisterBlueskyTest.php tests/Http/EntryJsonTest.php`.
- [ ] **Step 3: Implement.** In `images()` drop `$tags` and return `new RenderedEmbedModel('', $lead, $media)`. In `external()` the GIF branch returns `new RenderedEmbedModel('', <still lead or null>, [$gif])`. In `video()` return `new RenderedEmbedModel('', <thumbnail lead or null>, [$medium])` — the `$postUrl` parameter becomes unused; remove it from `video()` and its call in `media()`. Delete the now-unused private helpers / constant (`figure`, `imageTag`, `dimensions`, `WATCH_LABEL`, the `HtmlEscape` import if no longer used — `linkParagraph` still uses it). `images()` still returns `null` when no image parsed, so the fallback paragraph still covers a broken embed.
- [ ] **Step 4: Run the tests, expect PASS**, then `composer cs`, `composer stan`, `composer md` (PHPMD-clean on `PostEmbedRenderer.php`) and `composer tramp`.
- [ ] **Step 5: Commit** — `git commit -m "fix(#1656): bluesky ingest keeps a post's media out of its body"`.

### Task 2: `articlePostMedia` — which of a post's media the article still has to show

**Files:**
- Create: `frontend/src/app/reader/article/post-media/article-post-media.ts`
- Create: `frontend/src/app/reader/article/post-media/article-post-media.spec.ts`

**Interfaces:**
- Consumes: `EntryDto` (`frontend/src/app/reader/models.ts`: `titleDerived`, `media: EntryMediumDto[]`, `linkCards: PlacedLinkCardDto[]`), `isPost` (`reader/entry/entry-text.ts`), `EntryMediumDto` (`reader/entry-medium.ts`).
- Produces: `export function articlePostMedia(entry: EntryDto, bodyHtml: string): EntryMediumDto[]`

- [ ] **Step 1: Write the failing spec** (build entries with the existing test builder — find it with `grep -rn "export function anEntry" frontend/src`; use it rather than a hand-written literal):

```ts
import { articlePostMedia } from './article-post-media';
// + the repo's entry builder and EntryMediumDto import

const photo = { url: 'https://files.example/a.jpg', kind: 'image' as const, width: 800, height: 600 };
const clip = { url: 'https://video.example/v.mp4', kind: 'video' as const, previewImageUrl: 'https://video.example/v.jpg' };
const poster = { url: 'https://video.example/v.jpg', kind: 'image' as const };

describe('articlePostMedia', () => {
  it('shows a post its own attachments', () => {
    expect(articlePostMedia(post({ media: [photo] }), '<p>Hi</p>')).toEqual([photo]);
  });

  it('shows an article none: its media lead the page as hero and body', () => {
    expect(articlePostMedia(article({ media: [photo] }), '<p>Hi</p>')).toEqual([]);
  });

  it('does not repeat a video poster as a picture', () => {
    expect(articlePostMedia(post({ media: [poster, clip] }), '')).toEqual([clip]);
  });

  it("leaves a link card's picture to the card", () => {
    const card = { ...aLinkCard(), imageUrl: photo.url };
    expect(articlePostMedia(post({ media: [photo], linkCards: [card] }), '')).toEqual([]);
  });

  it('skips what the body already shows, as a Bluesky post stored before #1656', () => {
    const html = `<figure class="post-images"><img src="${photo.url}"></figure>`;
    expect(articlePostMedia(post({ media: [photo] }), html)).toEqual([]);
  });

  it('matches a body source after entity decoding', () => {
    const gif = { url: 'https://static.example/a.gif?hh=280&ww=498', kind: 'image' as const, animated: true };
    const html = '<img src="https://static.example/a.gif?hh&#61;280&amp;ww&#61;498">';
    expect(articlePostMedia(post({ media: [gif] }), html)).toEqual([]);
  });

  it('skips a video the body plays itself', () => {
    expect(articlePostMedia(post({ media: [clip] }), `<video src="${clip.url}"></video>`)).toEqual([]);
  });
});
```

(`post()` / `article()` are local helpers over the repo builder with `titleDerived: true` / `false`; `aLinkCard()` is whatever builder exists for `PlacedLinkCardDto`, else a small local one.)

- [ ] **Step 2: Run, expect FAIL** — `docker compose exec -T frontend npx jest src/app/reader/article/post-media`.
- [ ] **Step 3: Implement:**

```ts
import { isPost } from '../../entry/entry-text';
import { EntryMediumDto } from '../../entry-medium';
import { EntryDto } from '../../models';

/** A post's own media the article has not shown yet: not a poster, a link card's picture, or in the body. */
export function articlePostMedia(entry: EntryDto, bodyHtml: string): EntryMediumDto[] {
  if (!isPost(entry)) return [];
  const shown = new Set([
    ...entry.media.map((medium) => medium.previewImageUrl),
    ...entry.linkCards.map((card) => card.imageUrl),
    ...bodySources(bodyHtml),
  ]);
  return entry.media.filter((medium) => !shown.has(medium.url));
}

function bodySources(html: string): string[] {
  const template = document.createElement('template');
  template.innerHTML = html;
  return Array.from(template.content.querySelectorAll('img[src], video[src], source[src]'), (node) =>
    node.getAttribute('src') ?? '',
  );
}
```

  (A `<template>` parses without loading images or running scripts. Note a video's own `url` is never a `previewImageUrl`, so videos survive rule 1.)
- [ ] **Step 4: Run, expect PASS.**
- [ ] **Step 5: Commit** — `fix(#1656): pick the post media an article has not shown yet`.

### Task 3: The article view renders the post's media

**Files:**
- Modify: `frontend/src/app/reader/article/content/article-source.service.ts` (add `postMedia`)
- Modify: `frontend/src/app/reader/article/reader-view/reader-view.component.ts` (import `PostMediaComponent`, `entryMediumRatio`; viewChild + `trailingParts` entry)
- Modify: `frontend/src/app/reader/article/reader-view/reader-view.component.html` (render after `<app-article-content>`)
- Modify (only if spacing needs it, tokens only): `frontend/src/app/reader/article/reader-view/reader-view.component.scss`
- Test: `frontend/src/app/reader/article/reader-view/reader-view.component.spec.ts` (and `article-source` coverage there or in a new `article-source.service.spec.ts` if the reader-view spec has no seam for it)

**Interfaces:**
- Consumes: `articlePostMedia(entry, bodyHtml)` from Task 2; `PostMediaComponent` (`reader/media/post-media/post-media.component.ts`, inputs `media`, `ratio`, `videoLabel`); `entryMediumRatio(entry, medium)` (`reader/entry-medium.ts`); translation key `reader.mediaVideo`.
- Produces: `ArticleSource.postMedia: Signal<EntryMediumDto[]>`.

- [ ] **Step 1: Failing tests** in `reader-view.component.spec.ts`, following how that spec already seeds an entry and its body (look for `EntryBodyService` / `seed(` usage there):
  1. A post (`titleDerived: true`) with `media: [photo]` and a seeded body `<p>Hi</p>` renders one `app-post-media` placed after `app-article-content` (assert DOM order via `compareDocumentPosition` or sibling order) containing an `img` whose `src` resolves to the photo URL (it may be proxied — assert with `toContain` on the encoded URL, or on the `PostMediaComponent` instance's `media()` input via `By.directive`).
  2. The same post whose body is still loading (no seed) renders no `app-post-media`.
  3. A post whose body already contains `<img src="{photo.url}">` renders no `app-post-media`.
  4. An article (`titleDerived: false`) with media renders no `app-post-media`.
- [ ] **Step 2: Run, expect FAIL** — `docker compose exec -T frontend npx jest src/app/reader/article/reader-view`.
- [ ] **Step 3: Implement `ArticleSource.postMedia`:**

```ts
  /** The post's own media still to show under its body, once the body settled (#1656). */
  readonly postMedia = computed(() => {
    const entry = this.entry();
    const body = this.feedBody();
    if (!entry || !body || body.status === 'loading') return [];
    return articlePostMedia(entry, body.status === 'ok' ? (body.html ?? '') : '');
  });
```

  Template, right after `<app-article-content … />`:

```html
        @if (source.postMedia(); as media) {
          @if (media.length) {
            <app-post-media
              [media]="media"
              [ratio]="ratio(e, media[0])"
              [videoLabel]="'reader.mediaVideo' | transloco"
            />
          }
        }
```

  (Or a single `@let media = source.postMedia();` + `@if (media.length)` if the template already uses `@let`.) In the component: `protected readonly ratio = entryMediumRatio;`, add `PostMediaComponent` to `imports`, `private readonly postMedia = viewChild(PostMediaComponent, { read: ElementRef<HTMLElement> });` and put `{ query: this.postMedia, joinsFocus: false }` first in `trailingParts` (it now sits before the quoted post). Give it the same vertical rhythm as `app-quoted-post` / `app-link-cards` in `reader-view.component.scss` (reuse their selector list; tokens only).
- [ ] **Step 4: Run, expect PASS;** then `docker compose exec -T frontend npm run check`.
- [ ] **Step 5: Commit** — `fix(#1656): the article view shows a post's own photos, videos and gifs`.

### Task 4: Verify on the real render

- [ ] Make sure the dev stack runs this branch's code (frontend container rebuilds on change; restart `php`/`worker` for Task 1 per the stale-container memories), then in the browser pane at `https://localhost:8443` (mobile viewport, the built-in browser UA is bot-blocked otherwise) open:
  1. Mastodon post 615944 (link share + attached PNG): the PNG shows under the text via `app-post-media`, the link card still shows, no duplicate.
  2. Mastodon post 615575 (media share): its picture shows once.
  3. Bluesky post 615938 / 615936 (stored with figures): picture / GIF shows exactly once (from the body).
  4. Refresh a Bluesky feed so a new image/GIF/video post is ingested, open it: media come through `app-post-media` once, the GIF starts near the viewport and pauses on tap.
- [ ] Scan today's dev log (`ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 100 | jq .`) for errors.
- [ ] Report what was seen; fix and commit anything found.
