# Article page groundwork (#1982) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the generic article code out of `reader-view/` and close three entry leaks, with no behaviour change, so #1983 can build one `ArticlePage` shell on top.

**Architecture:** Pure moves at the same folder depth (relative imports outside the moved set do not change), then three narrowings: embed audio takes an `EmbedTrackOrigin` instead of an `EntryDto`, the corner keep/favourite discs become an entry component apart from the share disc, and article scroll positions key by a string page key.

**Tech Stack:** Angular 20 (standalone, signals), Jest (jsdom) in the Docker `frontend` container, Sass.

**Spec:** `docs/superpowers/specs/2026-11-02-article-page-shell-design.md` (section "PR 1 — #1982").

## Global Constraints

- No behaviour change: every existing reader-view, link-reader and article-layer spec stays green without editing its assertions (only imports and fixtures change).
- Branch `refactor/1982-article-page-groundwork` (exists, holds the spec commit). Commit format `refactor(#1982): …`, `test(#1982): …`.
- Frontend tests run inside Docker: `docker compose exec -T frontend npx jest <paths>` from the repo root. Never two Jest runs at once (the container OOMs).
- `npm run check` misses template errors: after any template change also run `docker compose exec -T frontend npx ng build`.
- Comments: default to none; keep one line. No section banners, no narration.
- No hex colours or ad-hoc `px` in `.scss`.

## File map

| File | Change |
|---|---|
| `src/app/reader/article/reader-view/article-{body.service,chapters,embed-audio,embed-details,islands,narration,player-bars,slideshows,unplayable-videos}.ts` (+ `.spec.ts`) | → `src/app/reader/article/article-content/` |
| `src/app/reader/article/reader-view/article-gestures.service.ts` | → `src/app/reader/article/article-layer/` |
| `src/app/reader/article/reader-view/_article-{page,toolbar}.scss` | → new `src/app/reader/article/article-page/` |
| `src/app/reader/audio/embed-track.ts` | gains `EmbedTrackOrigin`, `embedTrackOrigin()`; `toEmbedTrack` takes an origin |
| `src/app/reader/article/corner-actions/` | deleted |
| `src/app/reader/entry/entry-corner-flags/entry-corner-flags.component.{ts,html,scss}` | new: keep and favourite discs |
| `src/app/reader/article/reading/article-page-key.ts` (+ spec) | new: `entryPageKey(id)` |
| `src/app/reader/scroll/list-scroll-memory.ts` | `saveEntry/readEntry/entryScrollKey` → `saveArticle/readArticle/articleScrollKey` on a page key |
| `src/app/reader/article/reading/article-scroll-restore.service.ts` | page key in place of entry id |
| `src/app/reader/article/reader-view/reader-view.component.{ts,html}` | consumes all of the above |

All paths below are relative to `frontend/` unless they start with `docs/`.

---

### Task 1: Move the generic article code out of `reader-view/`

**Files:**
- Move: the nine body files and their specs → `src/app/reader/article/article-content/`
- Move: `src/app/reader/article/reader-view/article-gestures.service.ts` → `src/app/reader/article/article-layer/`
- Move: `src/app/reader/article/reader-view/_article-page.scss`, `_article-toolbar.scss` → `src/app/reader/article/article-page/`
- Modify: `src/app/reader/article/article-content/article-content.component.ts:2`
- Modify: `src/app/reader/article/article-layer/article-layer.component.ts:25`
- Modify: `src/app/reader/article/reader-view/reader-view.component.spec.ts:10`
- Modify: `src/app/reader/article/reader-view/reader-view.component.scss:1-2`
- Modify: `src/app/reader/article/link-reader/link-reader.component.scss:1-2`
- Modify: `docs/design-language.md:2111`

**Interfaces:**
- Consumes: nothing.
- Produces: `ArticleBody` at `article/article-content/article-body.service.ts`; `ArticleEmbedAudio`, `EmbedListenContext` at `article/article-content/article-embed-audio.ts`; `ArticleGestures` at `article/article-layer/article-gestures.service.ts`; Sass partials `article/article-page/_article-page.scss` and `_article-toolbar.scss`.

The moved files stay at the same depth, so their own `../../…` imports and their `./article-…` imports among each other stay valid. Only the importers listed below change.

- [ ] **Step 1: Move the files with git**

```bash
cd frontend/src/app/reader/article
for name in article-body.service article-chapters article-embed-audio article-embed-details article-islands article-narration article-player-bars article-slideshows article-unplayable-videos; do
  git mv reader-view/$name.ts article-content/$name.ts
  git mv reader-view/$name.spec.ts article-content/$name.spec.ts
done
git mv reader-view/article-gestures.service.ts article-layer/article-gestures.service.ts
mkdir -p article-page
git mv reader-view/_article-page.scss article-page/_article-page.scss
git mv reader-view/_article-toolbar.scss article-page/_article-toolbar.scss
ls reader-view
```

Expected `ls`: only `reader-view.component.{html,scss,spec.ts,ts}`.

- [ ] **Step 2: Repoint the importers**

`src/app/reader/article/article-content/article-content.component.ts`, line 2:

```ts
import { ArticleBody } from './article-body.service';
```

`src/app/reader/article/article-layer/article-layer.component.ts`, line 25:

```ts
import { ArticleGestures } from './article-gestures.service';
```

`src/app/reader/article/reader-view/reader-view.component.spec.ts`, line 10:

```ts
import { ArticleGestures } from '../article-layer/article-gestures.service';
```

`src/app/reader/article/reader-view/reader-view.component.scss`, lines 1–2:

```scss
@use '../article-page/article-page';
@use '../article-page/article-toolbar';
```

`src/app/reader/article/link-reader/link-reader.component.scss`, lines 1–2:

```scss
@use '../article-page/article-page';
@use '../article-page/article-toolbar';
```

In `docs/design-language.md` line 2111, replace `` `reader-view/article-islands.ts` `` with `` `article-content/article-islands.ts` ``.

- [ ] **Step 3: Prove nothing else points at the old paths**

```bash
grep -rn "reader-view/article-\|reader-view/_article\|reader-view/article" frontend/src docs --include='*.ts' --include='*.scss' --include='*.md' | grep -v "docs/superpowers/"
```

Expected: no output. If a hit appears, repoint it the same way.

- [ ] **Step 4: Run the affected specs**

```bash
docker compose exec -T frontend npx jest src/app/reader/article
```

Expected: every suite PASS. The moved specs run from their new folder; the `Tests:` total equals the one this command printed before Step 1 (run it once there first and note the number).

- [ ] **Step 5: Build and lint styles**

```bash
docker compose exec -T frontend npx ng build
docker compose exec -T frontend npm run stylelint
```

Expected: build succeeds; stylelint exits 0.

- [ ] **Step 6: Commit**

```bash
git add -A frontend/src/app/reader/article docs/design-language.md
git commit -m "refactor(#1982): the article body, gestures and page styles leave reader-view"
```

---

### Task 2: Embed audio takes an `EmbedTrackOrigin`, not an entry

**Files:**
- Modify: `src/app/reader/audio/embed-track.ts`
- Test: `src/app/reader/audio/embed-track.spec.ts`
- Modify: `src/app/reader/article/article-content/article-embed-audio.ts`
- Test: `src/app/reader/article/article-content/article-embed-audio.spec.ts`
- Modify: `src/app/reader/article/article-content/article-body.service.ts:45,141`
- Test: `src/app/reader/article/article-content/article-body.service.spec.ts`
- Modify: `src/app/reader/article/article-content/article-content.component.ts`
- Modify: `src/app/reader/article/reader-view/reader-view.component.ts`, `.html`

**Interfaces:**
- Consumes: Task 1's paths.
- Produces (in `src/app/reader/audio/embed-track.ts`):

```ts
export interface EmbedTrackOrigin {
  readonly title: string;
  readonly faviconUrl: string | null;
  readonly imageUrl: string | null;
  readonly entry: AudioTrackEntry;
}
export function embedTrackOrigin(entry: EntryDto): EmbedTrackOrigin;
export function toEmbedTrack(origin: EmbedTrackOrigin, media: EmbedMedia, details?: TrackDetails | null): AudioTrack;
```

- `EmbedListenContext.origin: EmbedTrackOrigin | null` (replaces `entry`)
- `ArticleBodyParts.origin: () => EmbedTrackOrigin | null` (replaces `entry`)
- `ArticleContentComponent.origin = input<EmbedTrackOrigin | null>(null)` (replaces `entry`)

- [ ] **Step 1: Retarget the embed-track spec at the origin**

In `src/app/reader/audio/embed-track.spec.ts`, change the import on line 1 to:

```ts
import { embedTrackOrigin, toEmbedTrack } from './embed-track';
```

Replace every `toEmbedTrack(entry(` with `toEmbedTrack(embedTrackOrigin(entry(` and close the extra parenthesis after the `entry(…)` call, e.g.:

```ts
expect(toEmbedTrack(embedTrackOrigin(entry()), video())).toEqual({
const track = toEmbedTrack(embedTrackOrigin(entry()), video({ kind: 'soundcloud', posterUrl: null }));
expect(toEmbedTrack(embedTrackOrigin(entry({ title: '' })), video()).title).toBe('Fixture channel');
```

Apply the same to the multi-line calls at lines ~92 and ~102 and to both sides of line ~114. Run Prettier on the file afterwards (`docker compose exec -T frontend npx prettier --write src/app/reader/audio/embed-track.spec.ts`), since some lines pass 100 columns.

Add, inside `describe('toEmbedTrack', …)`:

```ts
  it('names, pictures and links the track from the page it plays in', () => {
    expect(embedTrackOrigin(entry())).toEqual({
      title: 'The entry headline',
      faviconUrl: 'https://x.test/favicon.ico',
      imageUrl: 'https://x.test/cover.jpg',
      entry: expect.objectContaining({ id: 42, title: 'The entry headline' }),
    });
  });
```

- [ ] **Step 2: Run it to see it fail**

```bash
docker compose exec -T frontend npx jest src/app/reader/audio/embed-track.spec.ts
```

Expected: FAIL — `embedTrackOrigin` is not exported.

- [ ] **Step 3: Implement the origin**

Replace the body of `src/app/reader/audio/embed-track.ts` from the imports down:

```ts
import { AudioTrack } from '../audio-player.service';
import { EntryDto } from '../models';
import { describedTrack, type AudioTrackEntry, type TrackDetails } from './audio-track';
import { entryDisplayTitle } from '../entry/entry-text';
import { audioTrackEntry } from './audio-attachment';
import { EmbedMedia } from './embed-media';

/** What an embed's track borrows from the page it plays in. */
export interface EmbedTrackOrigin {
  readonly title: string;
  readonly faviconUrl: string | null;
  readonly imageUrl: string | null;
  readonly entry: AudioTrackEntry;
}

export function embedTrackOrigin(entry: EntryDto): EmbedTrackOrigin {
  return {
    title: entryDisplayTitle(entry),
    faviconUrl: entry.faviconUrl,
    imageUrl: entry.imageUrl,
    entry: audioTrackEntry(entry),
  };
}

/** The track an embedded video or sound becomes in the player, mirroring `toAudioTrack`; named and
 *  timed by the embed's own details when they arrived, else by the post until it plays. */
export function toEmbedTrack(
  origin: EmbedTrackOrigin,
  media: EmbedMedia,
  details: TrackDetails | null = null,
): AudioTrack {
  const track: AudioTrack = {
    url: media.url,
    title: origin.title,
    faviconUrl: origin.faviconUrl,
    imageUrl: media.posterUrl ?? origin.imageUrl,
    durationInSeconds: null,
    entry: origin.entry,
  };
  return details ? describedTrack(track, details) : track;
}
```

If `AudioTrackEntry` is not exported from `./audio-track` under that name, check `src/app/reader/audio/audio-track.ts:5` (it is `export interface AudioTrackEntry`).

- [ ] **Step 4: Run it to see it pass**

```bash
docker compose exec -T frontend npx jest src/app/reader/audio/embed-track.spec.ts
```

Expected: PASS.

- [ ] **Step 5: Narrow `ArticleEmbedAudio`**

In `src/app/reader/article/article-content/article-embed-audio.ts`: drop the `EntryDto` import, import `EmbedTrackOrigin` alongside `toEmbedTrack`, and change the context and the guard:

```ts
import { EmbedTrackOrigin, toEmbedTrack } from '../../audio/embed-track';
```

```ts
export interface EmbedListenContext {
  readonly origin: EmbedTrackOrigin | null;
  readonly detailsOf: (media: EmbedMedia) => TrackDetails | null;
  readonly onListened: (track: AudioTrack) => void;
}
```

```ts
  actions(context: EmbedListenContext): ListenActions | undefined {
    const origin = context.origin;
    if (!origin) return undefined;
    const trackOf = (media: EmbedMedia): AudioTrack =>
      toEmbedTrack(origin, media, context.detailsOf(media));
```

In `src/app/reader/article/article-content/article-embed-audio.spec.ts`:
- import line 8 becomes `import { embedTrackOrigin, toEmbedTrack } from '../../audio/embed-track';`
- after `const ENTRY = anEntry({ id: 42, title: 'The video' });` add `const ORIGIN = embedTrackOrigin(ENTRY);`
- every `entry: ENTRY,` becomes `origin: ORIGIN,`
- `setUp({ entry: null })` becomes `setUp({ origin: null })`, and that test's name becomes `'offers no actions without an origin to make a track of'`
- every `toEmbedTrack(ENTRY,` becomes `toEmbedTrack(ORIGIN,`

- [ ] **Step 6: Narrow `ArticleBody` and `ArticleContentComponent`**

`src/app/reader/article/article-content/article-body.service.ts`: in `ArticleBodyParts` replace `readonly entry: () => EntryDto | null;` with `readonly origin: () => EmbedTrackOrigin | null;`; in `render`, replace `entry: parts.entry(),` with `origin: parts.origin(),`. Replace the `EntryDto` import with `import { EmbedTrackOrigin } from '../../audio/embed-track';` (keep `ChapterDto` if it shares the `../../models` import line).

`src/app/reader/article/article-content/article-content.component.ts`:

```ts
import { Component, ElementRef, inject, input, output, signal } from '@angular/core';
import { ArticleBody } from './article-body.service';
import { AudioTrack } from '../../audio-player.service';
import { EmbedTrackOrigin } from '../../audio/embed-track';
import { ChapterDto } from '../../models';
```

and replace `readonly entry = input<EntryDto | null>(null);` with `readonly origin = input<EmbedTrackOrigin | null>(null);`, and `entry: this.entry,` with `origin: this.origin,`.

`src/app/reader/article/article-content/article-body.service.spec.ts`:
- replace `const entry = signal<EntryDto | null>(null);` with `const origin = signal<EmbedTrackOrigin | null>(null);`
- in `connectBody`, replace `entry,` with `origin,`
- replace `entry.set(null);` with `origin.set(null);`
- replace each `entry.set(anEntry(…));` with `origin.set(embedTrackOrigin(anEntry(…)));`
- rename `'offers Listen under a video of the open entry'` → `'offers Listen under a video once the page gives a track origin'` and `'offers no Listen before the entry is known'` → `'offers no Listen before the page gives a track origin'`
- import `embedTrackOrigin, EmbedTrackOrigin` from `'../../audio/embed-track'`; drop `EntryDto` from imports if now unused.

- [ ] **Step 7: Feed the origin from the reader view**

`src/app/reader/article/reader-view/reader-view.component.ts`: add `import { embedTrackOrigin } from '../../audio/embed-track';` and, next to the `audio` field:

```ts
  protected readonly embedOrigin = computed(() => {
    const entry = this.entry();
    return entry ? embedTrackOrigin(entry) : null;
  });
```

`src/app/reader/article/reader-view/reader-view.component.html`, in `<app-article-content>`: replace `[entry]="e"` with `[origin]="embedOrigin()"`.

`ArticleBody` reads `parts.origin()` inside the render microtask, outside the effect's tracking, so an entry reference churn does not re-render the body — as with `entry` before.

- [ ] **Step 8: Run the affected specs and build**

```bash
docker compose exec -T frontend npx jest src/app/reader/audio src/app/reader/article
docker compose exec -T frontend npx ng build
```

Expected: all PASS; build succeeds.

- [ ] **Step 9: Prove no generic body code names an entry any more**

```bash
grep -n "EntryDto" frontend/src/app/reader/article/article-content/*.ts | grep -v "\.spec\.ts"
```

Expected: no output.

- [ ] **Step 10: Commit**

```bash
git add -A frontend/src/app/reader
git commit -m "refactor(#1982): embed audio takes the page's track origin, not its entry"
```

---

### Task 3: Keep and favourite discs become an entry component

**Files:**
- Create: `src/app/reader/entry/entry-corner-flags/entry-corner-flags.component.ts`, `.html`, `.scss`
- Delete: `src/app/reader/article/corner-actions/` (three files, no spec)
- Modify: `src/app/reader/article/reader-view/reader-view.component.ts` (imports), `.html:574`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `EntryCornerFlagsComponent`, selector `app-entry-corner-flags`, `entry = input.required<EntryDto>()`.

The test for this task is the existing `describe('the keep and favourite corner discs (#1914)')` block in `reader-view.component.spec.ts`, including `'puts the share disc left of Keep'`; it must stay green unedited.

- [ ] **Step 1: Create the component**

`src/app/reader/entry/entry-corner-flags/entry-corner-flags.component.ts`:

```ts
import { ChangeDetectionStrategy, Component, inject, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { FlagToggleDirective } from '../../../shared/flag-toggle/flag-toggle.directive';
import { IconComponent } from '../../../shared/icon/icon.component';
import { EntryActionHandler } from '../entry-actions/entry-action-handler';
import { EntryDto } from '../../models';

/** The keep and favourite discs in an article's bottom-left corner. */
@Component({
  selector: 'app-entry-corner-flags',
  imports: [TranslocoPipe, FlagToggleDirective, IconComponent],
  templateUrl: './entry-corner-flags.component.html',
  styleUrl: './entry-corner-flags.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class EntryCornerFlagsComponent {
  readonly entry = input.required<EntryDto>();

  protected readonly actions = inject(EntryActionHandler);
}
```

`entry-corner-flags.component.html` — the two buttons from the old template, unchanged:

```html
<button
  type="button"
  class="corner-flag"
  [appFlagToggle]="entry().isKept"
  [attr.aria-label]="'reader.keep' | transloco"
  [attr.title]="'reader.keep' | transloco"
  (click)="actions.keep(entry())"
>
  <app-icon name="bookmark" size="dense" [fill]="entry().isKept" />
</button>
<button
  type="button"
  class="corner-flag"
  [appFlagToggle]="entry().isFavorite"
  [attr.aria-label]="'reader.favorite' | transloco"
  [attr.title]="'reader.favorite' | transloco"
  (click)="actions.favorite(entry())"
>
  <app-icon name="star" size="dense" [fill]="entry().isFavorite" />
</button>
```

`entry-corner-flags.component.scss` (same depth as the old folder, so the theme path holds):

```scss
@use '../../../theme/corner-circle-button' as circle;

:host {
  display: contents;
}

.corner-flag {
  @include circle.corner-circle-toggle;
}
```

- [ ] **Step 2: Swap it into the reader view and delete the old component**

`reader-view.component.html`, replace `<app-article-corner-actions articleCorner [entry]="e" />` with:

```html
    @if (e.url; as url) {
      <app-share-button articleCorner appearance="corner" [url]="url" [title]="e.title" />
    }
    <app-entry-corner-flags articleCorner [entry]="e" />
```

`reader-view.component.ts`: replace the `ArticleCornerActionsComponent` import and its `imports` entry with `EntryCornerFlagsComponent` from `'../../entry/entry-corner-flags/entry-corner-flags.component'`.

```bash
git rm -r frontend/src/app/reader/article/corner-actions
```

- [ ] **Step 3: Run the specs and build**

```bash
docker compose exec -T frontend npx jest src/app/reader/article
docker compose exec -T frontend npx ng build
```

Expected: PASS, including the five `#1914` corner tests; build succeeds with no NG8011 warning.

- [ ] **Step 4: Commit**

```bash
git add -A frontend/src/app/reader
git commit -m "refactor(#1982): the corner's keep and favourite discs become an entry component beside the share disc"
```

---

### Task 4: Article scroll positions key by page

**Files:**
- Create: `src/app/reader/article/reading/article-page-key.ts`, `article-page-key.spec.ts`
- Modify: `src/app/reader/scroll/list-scroll-memory.ts:12-45`
- Test: `src/app/reader/scroll/list-scroll-memory.spec.ts:111-122`
- Modify: `src/app/reader/article/reading/article-scroll-restore.service.ts`
- Test: `src/app/reader/article/reading/article-scroll-restore.service.spec.ts`
- Modify: `src/app/reader/article/reader-view/reader-view.component.ts:204-264`
- Test: `src/app/reader/article/reader-view/reader-view.component.spec.ts:14,678`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces:

```ts
// article/reading/article-page-key.ts
export function entryPageKey(entryId: number): string; // 'entry:<id>'
// scroll/list-scroll-memory.ts
export function articleScrollKey(pageKey: string): string; // 'feed-reader:article-scroll:<pageKey>'
ListScrollMemory.saveArticle(pageKey: string, top: number): void;
ListScrollMemory.readArticle(pageKey: string): number;
// article/reading/article-scroll-restore.service.ts
ArticleScrollRestore.arm(pageKey: string | null): void;
ArticleScrollRestore.reseat(currentKey: () => string | undefined): void;
ArticleScrollRestore.remember(pageKey: string | undefined, top: number): void;
```

`linkPageKey` is #1984's; it is not added here.

- [ ] **Step 1: Write the failing specs**

`src/app/reader/article/reading/article-page-key.spec.ts`:

```ts
import { entryPageKey } from './article-page-key';

describe('entryPageKey', () => {
  it('keys an entry page by its id', () => {
    expect(entryPageKey(514)).toBe('entry:514');
  });
});
```

In `src/app/reader/scroll/list-scroll-memory.spec.ts`, replace the two article tests (lines ~111–122):

```ts
  it('round-trips an article scroll offset per page key, surviving a fresh instance', () => {
    mem.saveArticle('entry:514', 1200);
    expect(new ListScrollMemory().readArticle('entry:514')).toBe(1200);
    expect(mem.readArticle('entry:999')).toBe(0);
  });

  it('keeps article and list offsets in separate keys', () => {
    mem.save(sel({ kind: 'subscription', id: 5 }), 300);
    mem.saveArticle('entry:5', 900);
    expect(mem.read(sel({ kind: 'subscription', id: 5 }))).toBe(300);
    expect(mem.readArticle('entry:5')).toBe(900);
  });
```

Replace `src/app/reader/article/reading/article-scroll-restore.service.spec.ts` with:

```ts
import { ElementRef } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ListScrollMemory } from '../../scroll/list-scroll-memory';
import { ArticleScrollRestore } from './article-scroll-restore.service';

describe('ArticleScrollRestore', () => {
  let host: HTMLElement;
  let saveArticle: jest.Mock;
  let restore: ArticleScrollRestore;

  beforeEach(() => {
    host = document.createElement('div');
    saveArticle = jest.fn();
    TestBed.configureTestingModule({
      providers: [
        ArticleScrollRestore,
        { provide: ElementRef, useValue: new ElementRef(host) },
        { provide: ListScrollMemory, useValue: { readArticle: () => 400, saveArticle } },
      ],
    });
    restore = TestBed.inject(ArticleScrollRestore);
    restore.arm('entry:7');
  });

  it('keeps the remembered position while a restore is pending', () => {
    restore.remember('entry:7', 0);
    expect(saveArticle).not.toHaveBeenCalled();
  });

  it.each(['wheel', 'touchstart'])('hands scrolling back to the user on %s', (type) => {
    host.dispatchEvent(new Event(type));
    restore.remember('entry:7', 120);
    expect(saveArticle).toHaveBeenCalledWith('entry:7', 120);
  });
});
```

In `reader-view.component.spec.ts`: line 14 becomes

```ts
import { articleScrollKey } from '../../scroll/list-scroll-memory';
import { entryPageKey } from '../reading/article-page-key';
```

and line ~678 becomes `sessionStorage.setItem(articleScrollKey(entryPageKey(1)), String(top));`.

- [ ] **Step 2: Run them to see them fail**

```bash
docker compose exec -T frontend npx jest src/app/reader/article/reading src/app/reader/scroll
```

Expected: FAIL — `article-page-key` missing, `saveArticle`/`readArticle` not functions.

- [ ] **Step 3: Implement the key and the storage**

`src/app/reader/article/reading/article-page-key.ts`:

```ts
/** The key an article page remembers its scroll position under. */
export function entryPageKey(entryId: number): string {
  return `entry:${entryId}`;
}
```

`src/app/reader/scroll/list-scroll-memory.ts`: replace `entryScrollKey` and the two entry methods:

```ts
/** Storage key for an open article's own scroll offset. */
export function articleScrollKey(pageKey: string): string {
  return `feed-reader:article-scroll:${pageKey}`;
}
```

```ts
  /** Remember the scroll offset within an open article. */
  saveArticle(pageKey: string, top: number): void {
    this.write(articleScrollKey(pageKey), top);
  }

  readArticle(pageKey: string): number {
    return this.readNum(articleScrollKey(pageKey));
  }
```

- [ ] **Step 4: Key the restore by page**

`src/app/reader/article/reading/article-scroll-restore.service.ts`:

```ts
  private pendingRestore: { key: string; top: number } | null = null;
```

```ts
  /** Arm a restore for a newly opened page if a position is remembered for it. */
  arm(pageKey: string | null): void {
    this.cancelRestore();
    if (pageKey === null) {
      this.pendingRestore = null;
      return;
    }
    const savedTop = this.scroll.readArticle(pageKey);
    this.pendingRestore = savedTop > 0 ? { key: pageKey, top: savedTop } : null;
  }

  /** Content just (re-)rendered — re-seat a pending restore for the open page. */
  reseat(currentKey: () => string | undefined): void {
    if (this.pendingRestore?.key === currentKey()) this.startRestore(currentKey);
  }
```

```ts
  remember(pageKey: string | undefined, top: number): void {
    if (pageKey !== undefined && !this.pendingRestore) this.scroll.saveArticle(pageKey, top);
  }
```

In `startRestore`, the parameter becomes `currentKey: () => string | undefined` and the guard `if (!pending || pending.key !== currentKey()) return; // aborted or page changed`.

- [ ] **Step 5: Hand the reader view's key to the restore**

`src/app/reader/article/reader-view/reader-view.component.ts`: import `entryPageKey` from `'../reading/article-page-key'` and add, next to `loadedId`:

```ts
  private readonly pageKey = computed(() => {
    const entry = this.entry();
    return entry ? entryPageKey(entry.id) : undefined;
  });
```

Then:
- in the entry effect, `this.restore.arm(id);` → `this.restore.arm(id === null ? null : entryPageKey(id));`
- in `onDecorated`, `this.restore.reseat(() => this.entry()?.id);` → `this.restore.reseat(this.pageKey);`
- in `onScroll`, `this.restore.remember(this.entry()?.id, scrollTop);` → `this.restore.remember(this.pageKey(), scrollTop);`

- [ ] **Step 6: Run the specs**

```bash
docker compose exec -T frontend npx jest src/app/reader/article src/app/reader/scroll
```

Expected: PASS, including `describe('article scroll restore')` in the reader-view spec.

- [ ] **Step 7: Prove the reader view's restore test covers the new key wiring**

Temporarily change the `onDecorated` line to `this.restore.reseat(() => undefined);` and rerun:

```bash
docker compose exec -T frontend npx jest src/app/reader/article/reader-view/reader-view.component.spec.ts -t "article scroll restore"
```

Expected: FAIL in `'restores the remembered offset when extraction fails'` (scroll top `0`, expected `900`). Put the line back by editing it (no `git checkout --`), rerun, expect PASS.

- [ ] **Step 8: Commit**

```bash
git add -A frontend/src/app/reader
git commit -m "refactor(#1982): an article's scroll position keys by its page, not its entry id"
```

---

### Task 5: Gate, simplify, PR

- [ ] **Step 1: Full frontend gate, alone in the container**

```bash
docker compose exec -T frontend npm run check; echo "EXIT=$?"
docker compose exec -T frontend npx ng build; echo "EXIT=$?"
```

Expected: both `EXIT=0`; grep the `Test Suites:` line for `0 failed`.

- [ ] **Step 2: Live smoke on :4200**

Open an entry with an embedded YouTube video and a long body: Listen under the video works, the corner shows share, keep, favourite in that order, scroll halfway, reload the page — it lands where it was. Open a link card: the link reader renders and its back button works.

- [ ] **Step 3: Run `/simplify` over the branch diff**, apply what it finds, rerun Step 1 if anything changed.

- [ ] **Step 4: Push and open the PR** into `develop`, title `Article views: generic article code leaves reader-view, entry leaks removed`, body ending `Closes #1982` and `Refs #1978`.
