# Media Post Block Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give image and video posts a bsky-style magazine block: source line, optional caption, uncropped media, inline video (#1522).

**Architecture:**
- **Backend:** one fix, so that an item with no feed title and no text is an untitled *post*.
- **Frontend, planner:** a new pinned kind `media`, which takes a post's slot as `link` does, chosen through one `postKind(entry)`.
- **Frontend, component:** a new `entry-media` block lays the post's media out by count. Video plays inline through the reader's existing lazy `hls.js` decorator.

**Tech Stack:** Symfony 7.4 / PHP 8.4 (PHPUnit 12), Angular 20 standalone + signals, Jest (jsdom), SCSS with theme tokens.

**Spec:** `docs/superpowers/specs/2026-10-11-1522-media-post-block-design.md`

## Global Constraints

- Branch `feature/1522-media-post-block`. Commits are `type(#1522): <what>`, with no attribution lines.
- **Backend gates**, run from `backend/`:
  - `composer check`
  - `composer md`
  - `composer test:parallel`
  - `docker compose exec -T php composer test`
- **Frontend tests:**
  - Always run them inside the container: `docker compose exec -T frontend npx jest <path>`.
  - The full gate is `docker compose exec -T frontend npm run check`.
  - Never run two Jest processes at once; concurrent runs OOM the container.
- **Styles:**
  - Component styles go in a sibling `.scss`.
  - No hex colours, no ad-hoc `px` spacing and no media-query literals outside `src/app/theme/` and `src/styles*`.
  - A new size is a token in `src/app/theme/tokens.scss`.
- **Comments:**
  - Default to none. Write one only where a future reader would otherwise get the code wrong.
  - One line, three at most.
- **Prettier** wraps at 100 columns. Run `npx prettier --write` on touched frontend files before the gate.

## File map

**Backend:**
- Modify `backend/src/Service/Parser/Support/EntryTitle.php`: no title and no text gives `untitledPost()`.
- Modify `backend/src/Service/Parser/Model/ParsedTitleModel.php`: delete the now-unused `untitled()`.
- Modify `backend/src/Service/Ingest/PlatformEntryRule/BlueskyEntryRule.php`: `postTitle()` is `EntryTitle::of(null, $text)`.
- Tests (already written in the working tree, uncommitted):
  - `backend/tests/Service/Parser/Support/EntryTitleTest.php`
  - `backend/tests/Service/Parser/FeedFormatParser/Rss2ParserTest.php`

**Frontend**, under `frontend/src/app/reader/list/magazine/`:
- `magazine-block.ts`: the `media` kind, its height and its demotion.
- `magazine-slot-fit.ts`: `postMedia`, `isMediaPost`, `postKind`, `POST_KINDS`; `settle` and `FITS`.
- `magazine-planner.ts`: pin through `postKind` and `POST_KINDS`.
- Create `blocks/entry-media/entry-media.component.{ts,html,scss,spec.ts}`.
- `magazine-block.component.{ts,html}`: render `media`.
- `magazine-slot-fit.spec.ts` and `magazine-planner.spec.ts`: new cases, and one fixture update.

**Elsewhere:**
- `frontend/src/app/theme/tokens.scss`: `--media-post-max-h`.
- `docs/design-language.md` §5: a catalog row for the block.

---

### Task 1: A text-less item is an untitled post (backend)

**Files:**
- Modify: `backend/src/Service/Parser/Support/EntryTitle.php:22`
- Modify: `backend/src/Service/Parser/Model/ParsedTitleModel.php:27-30`
- Modify: `backend/src/Service/Ingest/PlatformEntryRule/BlueskyEntryRule.php:33-38`
- Test: `backend/tests/Service/Parser/Support/EntryTitleTest.php`, `backend/tests/Service/Parser/FeedFormatParser/Rss2ParserTest.php`

**Interfaces:**
- Produces: a parsed item with no feed title and no text has `title === '(untitled)'` and `titleDerived === true`.

- [ ] **Step 1: Confirm the failing tests are in place.** Both already exist uncommitted:
  - `EntryTitleTest::testAnItemWithNeitherIsAnUntitledPost` asserts `EntryTitle::of(null, null)` gives text `(untitled)` with `derived` true.
  - `Rss2ParserTest::testAnItemWithNeitherTitleNorTextIsAnUntitledPost` parses `<item><link>https://bsky.app/profile/a/post/1</link><guid>at://did:plc:a/app.bsky.feed.post/1</guid></item>` and asserts title `(untitled)` with `titleDerived` true.

Run: `cd backend && php bin/phpunit --filter UntitledPost tests/Service/Parser`
Expected: 2 failures, each on `assertTrue` of derived.

- [ ] **Step 2: Implement.** In `EntryTitle::of`, replace the last line:

```php
        return $derived === null ? ParsedTitleModel::untitledPost() : ParsedTitleModel::derived($derived);
```

In `ParsedTitleModel`, delete `untitled()`:

```php
    public static function untitled(): self
    {
        return new self(self::UNTITLED, false);
    }
```

In `BlueskyEntryRule`, `postTitle` no longer needs its own fallback:

```php
    private static function postTitle(?string $text): ParsedTitleModel
    {
        return EntryTitle::of(null, $text);
    }
```

- [ ] **Step 3: Run the targeted tests.**

Run: `cd backend && php bin/phpunit tests/Service/Parser tests/Service/Ingest`
Expected: PASS. If any other test pinned an untitled non-post, it fails here. Change its expectation to a derived untitled post and rename its method to say so.

- [ ] **Step 4: Run the backend gates.**

Run:

```bash
cd backend && composer check && composer md && composer test:parallel && docker compose exec -T php composer test
```

Expected: all green. `composer check` reports any `ParsedTitleModel::untitled` reference that is left.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Parser backend/src/Service/Ingest/PlatformEntryRule/BlueskyEntryRule.php backend/tests/Service/Parser
git commit -m "fix(#1522): an item with neither title nor text is an untitled post"
```

---

### Task 2: The `media` kind in the planner (frontend)

**Files:**
- Modify: `frontend/src/app/reader/list/magazine/magazine-block.ts`
- Modify: `frontend/src/app/reader/list/magazine/magazine-slot-fit.ts`
- Modify: `frontend/src/app/reader/list/magazine/magazine-planner.ts:4,301,379-390`
- Test: `frontend/src/app/reader/list/magazine/magazine-slot-fit.spec.ts`, `frontend/src/app/reader/list/magazine/magazine-planner.spec.ts`

**Interfaces:**
- Produces, from `magazine-slot-fit.ts`:
  - `postMedia(entry: EntryDto): EntryMediumDto[]`: at most 4 items, with any poster duplicate dropped. The lead image is the fallback.
  - `isMediaPost(entry: EntryDto): boolean`
  - `postKind(entry: EntryDto): PostKind | null`
  - `type PostKind = Extract<EntryKind, 'link' | 'media'>`
  - `POST_KINDS: readonly PostKind[]`
- Produces, from `magazine-block.ts`: `EntryKind` now includes `'media'`, and `MagazineBlock` has `{ kind: 'media'; entry: EntryDto }`.

- [ ] **Step 1: Write the failing slot-fit specs.** In `magazine-slot-fit.spec.ts`, replace the import line with:

```ts
import { isMediaPost, postMedia, settle } from './magazine-slot-fit';
import { EntryDto, EntryMediumDto } from '../../models';
```

Then append these after the existing `describe('settle', …)`:

```ts
const image = (url: string): EntryMediumDto => ({ url, kind: 'image', width: 800, height: 600 });
const video = (url: string, poster: string): EntryMediumDto => ({
  url,
  kind: 'video',
  width: 1080,
  height: 1920,
  previewImageUrl: poster,
});

describe('postMedia', () => {
  it('drops the image that is only a video’s poster', () => {
    const clip = video('https://v/playlist.m3u8', 'https://v/thumb.jpg');
    const post = entry({ titleDerived: true, media: [image('https://v/thumb.jpg'), clip] });

    expect(postMedia(post)).toEqual([clip]);
  });

  it('keeps at most four, in feed order', () => {
    const media = [1, 2, 3, 4, 5].map((index) => image(`https://i/${index}.jpg`));

    expect(postMedia(entry({ media })).map((medium) => medium.url)).toEqual([
      'https://i/1.jpg',
      'https://i/2.jpg',
      'https://i/3.jpg',
      'https://i/4.jpg',
    ]);
  });

  it('stands the lead image in when the feed declared no media', () => {
    expect(postMedia(entry())).toEqual([
      { url: 'https://i/1.jpg', kind: 'image', width: 900, height: 600 },
    ]);
  });

  it('is empty without media or a lead image', () => {
    expect(postMedia(entry({ imageUrl: null }))).toEqual([]);
  });
});

describe('isMediaPost', () => {
  it('is a post with a picture', () => {
    expect(isMediaPost(entry({ titleDerived: true }))).toBe(true);
  });

  it('is not an article, a link post, a pending post or a post without media', () => {
    const card = { url: 'https://n/1', title: 'T', description: null, imageUrl: null, siteName: 'n' };

    expect(isMediaPost(entry())).toBe(false);
    expect(isMediaPost(entry({ titleDerived: true, linkCards: [card] }))).toBe(false);
    expect(isMediaPost(entry({ titleDerived: true, pending: true }))).toBe(false);
    expect(isMediaPost(entry({ titleDerived: true, imageUrl: null }))).toBe(false);
  });
});

describe('settle for a media post', () => {
  it('is a media block from any slot', () => {
    const post = entry({ titleDerived: true });

    expect(settle('thumb', post, 'thumb')).toBe('media');
    expect(settle('compact', post, 'split')).toBe('media');
    expect(settle('hero', post, 'thumb')).toBe('media');
  });
});
```

- [ ] **Step 2: Run it and watch it fail.**

Run: `docker compose exec -T frontend npx jest src/app/reader/list/magazine/magazine-slot-fit.spec.ts`
Expected: FAIL, because `isMediaPost` and `postMedia` are not exported.

- [ ] **Step 3: Add the kind.** In `magazine-block.ts`:

`ENTRY_KINDS` gains `'media'` right after `'link'`:

```ts
export const ENTRY_KINDS = [
  'hero',
  'link',
  'media',
  'wide',
  'quote',
  'split',
  'kicker',
  'thumb',
  'compact',
] as const;
```

The union gains a member after `link`:

```ts
  | { kind: 'link'; entry: EntryDto }
  | { kind: 'media'; entry: EntryDto }
```

`BLOCK_HEIGHT` gains `media: 463,` after `link: 463,`, and `DEMOTION` gains `media: 'media',` after `link: 'link',`.

- [ ] **Step 4: Implement the selection.** In `magazine-slot-fit.ts`:

Change the import to:

```ts
import { EntryDto, EntryMediumDto } from '../../models';
```

Keep the `preview-image` import, which already brings `entryImage`.

Replace `settle`'s first two lines:

```ts
export function settle(kind: EntryKind, entry: EntryDto, imageBar: ImageBar): EntryKind {
  const pinned = postKind(entry);
  if (pinned) return pinned;
  const slot = POST_KINDS.includes(kind as PostKind) ? 'hero' : kind;
```

After `isLinkPost`, add:

```ts
const MAX_POST_MEDIA = 4;

/** The blocks a post claims whatever slot it was given (#1502, #1522). */
export type PostKind = Extract<EntryKind, 'link' | 'media'>;
export const POST_KINDS: readonly PostKind[] = ['link', 'media'];

/** The pictures and videos a post shows, at most four; a video's poster is not shown twice. */
export function postMedia(entry: EntryDto): EntryMediumDto[] {
  const posters = new Set(entry.media.map((medium) => medium.previewImageUrl));
  const shown = entry.media.filter(
    (medium) => medium.kind === 'video' || !posters.has(medium.url),
  );
  return (shown.length > 0 ? shown : leadImage(entry)).slice(0, MAX_POST_MEDIA);
}

function leadImage(entry: EntryDto): EntryMediumDto[] {
  const image = entryImage(entry);
  if (!image) return [];
  return [
    { url: image.url, kind: 'image', width: image.width ?? undefined, height: image.height ?? undefined },
  ];
}

export function isMediaPost(entry: EntryDto): boolean {
  return entry.titleDerived && !isLinkPost(entry) && postMedia(entry).length > 0;
}

export function postKind(entry: EntryDto): PostKind | null {
  if (isLinkPost(entry)) return 'link';
  return isMediaPost(entry) ? 'media' : null;
}
```

In `FITS`, add after `link: isLinkPost,`:

```ts
  media: isMediaPost,
```

- [ ] **Step 5: Run the slot-fit spec.**

Run: `docker compose exec -T frontend npx jest src/app/reader/list/magazine/magazine-slot-fit.spec.ts`
Expected: PASS.

- [ ] **Step 6: Write the failing planner specs.** In `magazine-planner.spec.ts`, add a fixture after `linkPost`:

```ts
const mediaPost = (id: number, over: Partial<EntryDto> = {}): EntryDto =>
  big(id, { titleDerived: true, ...over });
```

In `describe('a post sharing a link')`, the test `keeps the kinds of a post without cards and of an article with them` used `big(index, { titleDerived: true })`, which is now a media post. Make that post picture-less:

```ts
        index % 2 === 0 ? entryAt(index, { titleDerived: true }) : big(index, { linkCards: [CARD] }),
```

Append a new `describe` inside `describe('planMagazine')`, after the link-post one:

```ts
  describe('a post showing media', () => {
    it('is a media block in whatever slot it lands', () => {
      const entries = many(60, (index) =>
        index % 3 === 0 ? mediaPost(index, { subscriptionId: 2 }) : big(index),
      );
      const blocks = planMagazine({ entries, grouping: false, complete: true });

      const postKinds = blocks
        .filter((block) => block.kind !== 'group' && block.entry.titleDerived)
        .map((block) => block.kind);
      expect(postKinds).toHaveLength(20);
      expect(postKinds.every((kind) => kind === 'media')).toBe(true);
    });

    it('never reaches past a media post, which would hand its block to an article', () => {
      const entries = many(
        60,
        (index) => [entryAt(index, { summary: null }), mediaPost(index), big(index)][index % 3],
      );
      const blocks = planMagazine({ entries, grouping: false, complete: true });

      expect(
        blocks
          .filter((block) => block.kind === 'media')
          .every((block) => block.kind === 'media' && block.entry.titleDerived),
      ).toBe(true);
    });
  });
```

- [ ] **Step 7: Run it and watch it fail.**

Run: `docker compose exec -T frontend npx jest src/app/reader/list/magazine/magazine-planner.spec.ts`
Expected: FAIL in `a post showing media`. `settle` already pins the post, but the planner's wanted kinds and look-ahead still treat it as an ordinary entry.

- [ ] **Step 8: Pin through `postKind` in the planner.** In `magazine-planner.ts`:

Change the import:

```ts
import { fits, ImageBar, POST_KINDS, postKind, PostKind, QUOTE_MIN_TEXT, settle } from './magazine-slot-fit';
```

Change `layOutPage`'s wanted kinds:

```ts
    .map((slot, position) => postKind(slice[position]) ?? resolveSlot(slot, page, position));
```

Change `assign`'s doc line `stops at a link post, so every pinned \`link\` slot keeps the post it was pinned for.` to:

```ts
 * stops at a post that claims its own block, so every pinned slot keeps the post it was pinned for.
```

Change its body:

```ts
  const tallest = tallestWhere(kinds, (kind) => !POST_KINDS.includes(kind as PostKind));
```

and:

```ts
      if (postKind(order[index]) !== null) break;
```

- [ ] **Step 9: Run both specs.**

Run: `docker compose exec -T frontend npx jest src/app/reader/list/magazine/magazine-slot-fit.spec.ts src/app/reader/list/magazine/magazine-planner.spec.ts`
Expected: PASS, including every existing page-height-cap test.

- [ ] **Step 10: Typecheck.** `magazine-block.component.html` does not handle `media` yet, but its `@switch` has no exhaustiveness check, so this should compile.

Run: `docker compose exec -T frontend npx tsc -p tsconfig.app.json --noEmit`
Expected: no errors.

- [ ] **Step 11: Commit**

```bash
git add frontend/src/app/reader/list/magazine/magazine-block.ts frontend/src/app/reader/list/magazine/magazine-slot-fit.ts frontend/src/app/reader/list/magazine/magazine-slot-fit.spec.ts frontend/src/app/reader/list/magazine/magazine-planner.ts frontend/src/app/reader/list/magazine/magazine-planner.spec.ts
git commit -m "feat(#1522): a post showing media claims a media block, as a link post claims its link block"
```

---

### Task 3: The `entry-media` block (frontend)

**Files:**
- Create: `frontend/src/app/reader/list/magazine/blocks/entry-media/entry-media.component.ts`
- Create: `frontend/src/app/reader/list/magazine/blocks/entry-media/entry-media.component.html`
- Create: `frontend/src/app/reader/list/magazine/blocks/entry-media/entry-media.component.scss`
- Create: `frontend/src/app/reader/list/magazine/blocks/entry-media/entry-media.component.spec.ts`
- Modify: `frontend/src/app/reader/list/magazine/magazine-block.component.ts` and `.html`
- Modify: `frontend/src/app/theme/tokens.scss:103` (next to `--magazine-measure`)

**Interfaces:**
- Consumes:
  - `postMedia(entry)` from Task 2.
  - `attachHlsStreams(host: HTMLElement)` and `isHlsManaged(video)` from `reader/article/decorators/hls-streams.ts`.
  - `ProxiedImageDirective` (`img[appProxiedImage]`) from `shared/proxied-image/`.
- Produces: `<app-entry-media [entry] [tags]>`.

- [ ] **Step 1: Write the failing component spec.** Create `entry-media.component.spec.ts`:

```ts
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../../../../testing/transloco-testing';
import { EntryMediaComponent } from './entry-media.component';
import { EntryDto, EntryMediumDto } from '../../../../models';
import { EntryActionHandler } from '../../../../entry/entry-actions/entry-action-handler';
import { isHlsManaged } from '../../../../article/decorators/hls-streams';

const entryActions = {
  favorite: jest.fn(),
  keep: jest.fn(),
  toggleRead: jest.fn(),
  open: jest.fn(),
};

beforeEach(() => {
  Object.values(entryActions).forEach((spy) => spy.mockReset());
});

const image = (index: number, width = 800, height = 600): EntryMediumDto => ({
  url: `https://cdn.test/${index}.jpg`,
  kind: 'image',
  width,
  height,
});
const clip: EntryMediumDto = {
  url: 'https://video.test/playlist.m3u8',
  kind: 'video',
  width: 1080,
  height: 1920,
  previewImageUrl: 'https://video.test/thumb.jpg',
};

const entry = (over: Partial<EntryDto> = {}): EntryDto => ({
  id: 1,
  title: 'A sunrise with Nutmeg the donkey',
  url: 'https://bsky.app/profile/someone/post/1',
  author: null,
  summary: null,
  excerpt: 'A sunrise with Nutmeg the donkey',
  imageUrl: null,
  imageWidth: null,
  imageHeight: null,
  imageRenditions: [],
  media: [image(1)],
  attachments: [],
  categories: [],
  publishedAt: null,
  createdAt: 'x',
  subscriptionId: 1,
  source: 'Src',
  faviconUrl: null,
  isHidden: false,
  isFavorite: false,
  isKept: false,
  isViewed: false,
  isShort: false,
  titleDerived: true,
  linkCards: [],
  pending: false,
  imageAspectRatio: null,
  discussionUrl: null,
  comments: null,
  ...over,
});

async function mount(testEntry: EntryDto) {
  TestBed.configureTestingModule({
    imports: [EntryMediaComponent, provideTranslocoTesting()],
    providers: [{ provide: EntryActionHandler, useValue: entryActions }, provideRouter([])],
  });
  const fixture = TestBed.createComponent(EntryMediaComponent);
  fixture.componentRef.setInput('entry', testEntry);
  fixture.detectChanges();
  await fixture.whenStable();
  return fixture.nativeElement as HTMLElement;
}

describe('EntryMediaComponent', () => {
  it('shows the post text as a caption above the media', async () => {
    const element = await mount(entry());

    expect(element.querySelector('.caption')!.textContent).toContain('A sunrise with Nutmeg');
  });

  it('shows no caption for a post without text', async () => {
    const element = await mount(entry({ title: '(untitled)', excerpt: 'Watch on Bluesky' }));

    expect(element.querySelector('.caption')).toBeNull();
  });

  it('keeps a single picture at its own aspect ratio', async () => {
    const element = await mount(entry({ media: [image(1, 1020, 1180)] }));
    const frame = element.querySelector<HTMLElement>('.media')!;

    expect(frame.dataset['count']).toBe('1');
    expect(frame.style.getPropertyValue('--ratio')).toBe(String(1020 / 1180));
    expect(frame.querySelector('img')!.getAttribute('src')).toBe('https://cdn.test/1.jpg');
  });

  it('lays out several pictures as a grid of their count', async () => {
    const element = await mount(entry({ media: [image(1), image(2), image(3)] }));
    const frame = element.querySelector<HTMLElement>('.media')!;

    expect(frame.dataset['count']).toBe('3');
    expect(frame.querySelectorAll('img')).toHaveLength(3);
  });

  it('plays a video inline from its poster, streaming it through hls.js', async () => {
    const element = await mount(entry({ media: [clip] }));
    const video = element.querySelector('video')!;

    expect(video.getAttribute('src')).toBe('https://video.test/playlist.m3u8');
    expect(video.getAttribute('poster')).toBe('https://video.test/thumb.jpg');
    expect(video.getAttribute('preload')).toBe('none');
    expect(video.hasAttribute('controls')).toBe(true);
    expect(video.hasAttribute('playsinline')).toBe(true);
    expect(isHlsManaged(video)).toBe(true);
  });

  it('keeps a click on the video for its controls, and opens the post from a picture', async () => {
    const element = await mount(entry({ media: [clip, image(2)] }));

    element.querySelector('video')!.click();
    expect(entryActions.open).not.toHaveBeenCalled();

    element.querySelector<HTMLElement>('img')!.click();
    expect(entryActions.open).toHaveBeenCalledTimes(1);
  });
});
```

- [ ] **Step 2: Run it and watch it fail.**

Run: `docker compose exec -T frontend npx jest src/app/reader/list/magazine/blocks/entry-media`
Expected: FAIL. `Cannot find module './entry-media.component'`.

- [ ] **Step 3: Add the height token.** In `frontend/src/app/theme/tokens.scss`, after `--magazine-measure: 680px;`:

```scss
  --media-post-max-h: 560px;
```

- [ ] **Step 4: Write the component.** Create `entry-media.component.ts`:

```ts
import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  afterRenderEffect,
  computed,
  inject,
} from '@angular/core';
import { EntryKickerLineComponent } from '../../entry-kicker-line.component';
import { EntryMetaComponent } from '../../../entry-meta/entry-meta.component';
import { EntryDuplicatesComponent } from '../../entry-duplicates.component';
import { EntryBlockBase } from '../../entry-block-base';
import { postMedia } from '../../magazine-slot-fit';
import { EntryMediumDto } from '../../../../models';
import { ProxiedImageDirective } from '../../../../../shared/proxied-image/proxied-image.directive';
import { attachHlsStreams } from '../../../../article/decorators/hls-streams';

/** The backend's title for a post with no text of its own (`ParsedTitleModel::UNTITLED`). */
const UNTITLED = '(untitled)';
const UNKNOWN_RATIO = 16 / 9;

@Component({
  selector: 'app-entry-media',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    EntryKickerLineComponent,
    EntryMetaComponent,
    EntryDuplicatesComponent,
    ProxiedImageDirective,
  ],
  templateUrl: './entry-media.component.html',
  styleUrl: './entry-media.component.scss',
})
export class EntryMediaComponent extends EntryBlockBase {
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;

  readonly media = computed(() => postMedia(this.entry()));
  readonly caption = computed(() => (this.entry().title === UNTITLED ? '' : this.entry().excerpt));

  constructor() {
    super();
    afterRenderEffect(() => {
      this.media();
      attachHlsStreams(this.host);
    });
  }

  protected ratio(medium: EntryMediumDto): number {
    return medium.width && medium.height ? medium.width / medium.height : UNKNOWN_RATIO;
  }
}
```

Create `entry-media.component.html`:

```html
<article
  class="media-post"
  role="button"
  tabindex="0"
  [class.read]="entry().isHidden"
  (click)="actions.open(entry())"
  (keydown.enter)="actions.open(entry())"
  (keydown.space)="$event.preventDefault(); actions.open(entry())"
>
  <app-entry-kicker-line [entry]="entry()" />
  @if (caption()) {
    <p class="caption">{{ caption() }}</p>
  }
  @let items = media();
  <div class="media" [attr.data-count]="items.length" [style.--ratio]="ratio(items[0])">
    @for (medium of items; track medium.url) {
      @if (medium.kind === 'video') {
        <video
          class="item"
          controls
          preload="none"
          playsinline
          [src]="medium.url"
          [attr.poster]="medium.previewImageUrl ?? null"
          (click)="$event.stopPropagation()"
          (keydown)="$event.stopPropagation()"
        ></video>
      } @else {
        <img
          class="item"
          appProxiedImage
          [src]="medium.url"
          [attr.width]="medium.width ?? null"
          [attr.height]="medium.height ?? null"
          alt=""
          loading="lazy"
          decoding="async"
          referrerpolicy="no-referrer"
        />
      }
    }
  </div>
  <app-entry-meta [entry]="entry()" [tags]="tags()" />
  <app-entry-duplicates [entry]="entry()" />
</article>
```

Create `entry-media.component.scss`:

```scss
@use '../../../post-text';
@use '../../magazine-card';

:host {
  display: block;
}

.media-post {
  @include magazine-card.chrome;

  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  padding: var(--card-pad);
  cursor: pointer;
}

/* Pointer-only: a touch tap fires :hover, which must not highlight a card
   during a drag-scroll (#704). */
@media (hover: hover) {
  .media-post:hover {
    --rim-base: var(--border-strong);
  }
}

.caption {
  @include post-text.post-text(6);

  margin: 0;
  color: var(--text-primary);
}

.media-post.read .caption {
  color: var(--text-secondary);
}

.media {
  display: grid;
  gap: var(--space-1);
  overflow: hidden;
  border-radius: var(--radius);
}

/* One item keeps its shape: a portrait narrows under the height cap rather than being cropped. */
.media[data-count='1'] {
  width: min(100%, calc(var(--media-post-max-h) * var(--ratio)));
  aspect-ratio: var(--ratio);
}

.media:not([data-count='1']) {
  aspect-ratio: 16 / 9;
  grid-template-columns: 1fr 1fr;
}

.media[data-count='3'],
.media[data-count='4'] {
  grid-template-rows: 1fr 1fr;
}

.media[data-count='3'] > :first-child {
  grid-row: span 2;
}

.item {
  display: block;
  width: 100%;
  height: 100%;
  min-height: 0;
  object-fit: cover;
}
```

- [ ] **Step 5: Run the component spec.**

Run: `docker compose exec -T frontend npx jest src/app/reader/list/magazine/blocks/entry-media`
Expected: PASS.

If only `isHlsManaged` fails, `afterRenderEffect` has not flushed under jsdom. Call `TestBed.tick()` after `fixture.detectChanges()` in `mount`, and do not change the component.

- [ ] **Step 6: Render the kind.** In `magazine-block.component.ts`:
- Import it: `import { EntryMediaComponent } from './blocks/entry-media/entry-media.component';`.
- Add `EntryMediaComponent` to `imports` after `EntryLinkComponent`.

In `magazine-block.component.html`, add after the `link` case:

```html
  @case ('media') {
    <app-entry-media [entry]="entryOf(current)" [tags]="tagsFor(entryOf(current).subscriptionId)" />
  }
```

- [ ] **Step 7: Run the frontend gate.**

Run:

```bash
docker compose exec -T frontend npx prettier --write src/app/reader/list/magazine src/app/theme/tokens.scss
docker compose exec -T frontend npm run check
```

Expected: ESLint, Prettier, Stylelint, tsc and Jest are all green.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/app/reader/list/magazine frontend/src/app/theme/tokens.scss
git commit -m "feat(#1522): the media block shows a post's pictures and video as bsky.app does"
```

---

### Task 4: Verify on the real render, document, PR

**Files:**
- Modify: `docs/design-language.md` §5. Add a table row after **Link**, or after **Hero** if there is no Link row.

- [ ] **Step 1: Mark dev 1739's stored text-less posts as posts.** They were ingested before Task 1. Stop the worker first, so no request races the write, run the write alone, then confirm:

```bash
docker compose stop worker
docker compose exec -T php bin/console dbal:run-sql "UPDATE entry SET title_derived = 1 WHERE title = '(untitled)' AND title_derived = 0 AND guid LIKE 'at://%'"
docker compose exec -T php bin/console dbal:run-sql "SELECT COUNT(*) FROM entry WHERE title = '(untitled)' AND title_derived = 0 AND guid LIKE 'at://%'"
docker compose start worker
```

Expected: the count is 0.

- [ ] **Step 2: Check the real render.** In the browser pane, view the two subscriptions at 1440×1000 and 390×844:
  - `http://localhost:4200/?subscription=1739`: every post is a `media` block, with no caption, no "(untitled)", and pictures uncropped and height-capped.
  - `http://localhost:4200/?subscription=1740`: a caption above each video. Clicking play plays it inline: the network shows `playlist.m3u8`, and the reader does not open. Clicking the caption opens the reader.

Fix what is wrong at its source and re-run the gate.

- [ ] **Step 3: Document the block.** In `docs/design-language.md` §5's table, add:

```markdown
| **Media** (#1522) | 463px | the post's media uncropped: one item at its own aspect ratio, height-capped by `--media-post-max-h`; 2–4 in a 16:9 grid; a video plays inline through hls.js | a post with an image or video and no link card — pinned like Link, whatever its slot |
```

Change the section's "eight block types" sentence to count the real number.

- [ ] **Step 4: Commit, push and open the PR.**

```bash
git add docs/design-language.md docs/superpowers
git commit -m "docs(#1522): the media block in the magazine catalog, with its spec and plan"
git push -u origin feature/1522-media-post-block
gh pr create --base develop --title "feat(#1522): a bsky-style magazine block for image and video posts" --body "Closes #1522"
```

Then write the PR body. It covers:
- the backend fix
- the pinned `media` kind
- the block layout
- inline video through hls.js
- the dev data note

---

## Addendum: YouTube Shorts in the media card (spec §Addendum)

### Task 5: An untitled post sends no excerpt; a Short's player may start on load (backend)

**Files:**
- Modify: `backend/src/Entity/EntryHeadline.php`, `backend/src/Entity/Entry.php`, `backend/src/Service/Parser/Model/ParsedTitleModel.php`, `backend/src/Http/EntryJson.php:44`, `backend/src/Service/Reader/Media/EmbedProvider/YouTubeEmbedProvider.php` (`frames()`)
- Regenerate: `frontend/src/app/reader/embed-frame-allowlist.generated.json`
- Test: `backend/tests/Http/EntryJsonTest.php`, `backend/tests/Service/Reader/Media/EmbedProvider/YouTubeEmbedProviderTest.php`

**Interfaces:**
- Produces:
  - `EntryHeadline::UNTITLED` (`'(untitled)'`), `EntryHeadline::isUntitledPost(): bool` and `Entry::isUntitledPost(): bool`.
  - An untitled post's list JSON carries `excerpt: ''`.
  - The allow-list accepts `https://www.youtube-nocookie.com/embed/<id>?autoplay=1#shorts`.

- [ ] **Step 1: Write the failing tests.** Append to `EntryJsonTest`, before the private helpers. It already has `entryTitled(string $title): Entry` and `row(Entry $entry)`:

```php
    public function testAnUntitledPostHasNoExcerpt(): void
    {
        $entry = $this->entryTitled('(untitled)');
        $entry->markTitleDerived();
        $entry->setContentHtml(
            '<figure class="post-video"><video src="https://v.test/p.m3u8"></video></figure>'
            . '<p><a href="https://bsky.app/profile/a/post/1">Watch on Bluesky</a></p>',
        );

        self::assertSame('', EntryJson::listRow($this->row($entry))['excerpt']);
    }

    public function testAnUntitledArticleKeepsItsExcerpt(): void
    {
        $entry = $this->entryTitled('(untitled)');
        $entry->setContentHtml('<p>Some body text.</p>');

        self::assertSame('Some body text.', EntryJson::listRow($this->row($entry))['excerpt']);
    }
```

In `YouTubeEmbedProviderTest::framesByShape()`, add:

```php
        yield 'short starting on load' => [
            'https://www.youtube-nocookie.com/embed/GhUuOxrCato?autoplay=1#shorts',
            EmbedShape::Portrait,
        ];
```

Then add this test after `testNoFrameAcceptsAnotherFragment`:

```php
    public function testNoFrameAcceptsAnotherQuery(): void
    {
        self::assertSame(
            [],
            self::framesMatching($this->provider->frames(), 'https://www.youtube-nocookie.com/embed/GhUuOxrCato?mute=1#shorts'),
        );
    }
```

Run: `cd backend && php bin/phpunit tests/Http/EntryJsonTest.php tests/Service/Reader/Media/EmbedProvider/YouTubeEmbedProviderTest.php`
Expected: two failures. `testAnUntitledPostHasNoExcerpt` gets the "Watch on Bluesky" text, and the `short starting on load` frame is not found.

- [ ] **Step 2: Implement.** In `EntryHeadline`, add the constant above the properties:

```php
    public const string UNTITLED = '(untitled)';
```

Add the method after `markDerived()`:

```php
    public function isUntitledPost(): bool
    {
        return $this->derived && $this->title === self::UNTITLED;
    }
```

In `Entry`, after `markTitleDerived()`:

```php
    public function isUntitledPost(): bool
    {
        return $this->headline->isUntitledPost();
    }
```

In `ParsedTitleModel`:
- Delete `private const string UNTITLED = '(untitled)';`.
- Add `use App\Entity\EntryHeadline;`.
- `untitledPost()` returns `new self(EntryHeadline::UNTITLED, true)`.

In `EntryJson`, change the `excerpt` element to:

```php
            'excerpt' => $row->entry->isUntitledPost()
                ? ''
                : EntryExcerpt::of($row->entry->getSummary(), $row->entry->getContentHtml()),
```

In `YouTubeEmbedProvider::frames()`, change the portrait line to:

```php
            new EmbedFrameModel(
                $video . '(?:\?autoplay=1)?' . YouTubeShortUrl::FRAGMENT . '$',
                EmbedKind::Video,
                EmbedShape::Portrait,
            ),
```

- [ ] **Step 3: Regenerate the allow-list.** Run it natively, so the frontend path resolves:

Run: `cd backend && php bin/console app:embed:dump-frame-allowlist`
Expected: `Wrote the reader embed allow-list to …/frontend/src/app/reader/embed-frame-allowlist.generated.json`. The diff changes only the portrait pattern.

- [ ] **Step 4: Run the gates.**

Run:

```bash
cd backend && php bin/phpunit tests/Http tests/Service/Reader/Media && composer check && composer md && composer test:parallel && docker compose exec -T php composer test
```

Expected: all green. `EmbedFrameAllowlistTest` passes against the regenerated file.

- [ ] **Step 5: Commit**

```bash
git add backend/src backend/tests frontend/src/app/reader/embed-frame-allowlist.generated.json
git commit -m "feat(#1522): an untitled post sends no excerpt, and a Short's player may start on load"
```

---

### Task 6: A YouTube Short uses the media card and plays inline (frontend)

**Files:**
- Modify: `frontend/src/app/reader/media-embeds.ts`: export `EMBED_SANDBOX` and `isAllowedEmbed`.
- Create: `frontend/src/app/reader/list/magazine/blocks/entry-media/short-embed.ts` and `short-embed.spec.ts`.
- Rename: `blocks/entry-media/pause-videos.ts` becomes `pause-media.ts`, with `pauseMedia` and `MEDIA_PAUSE`. Update `reader/list/entry-list/entry-list.component.ts` (import and effect) and any spec that imports it.
- Modify: `magazine-slot-fit.ts` (`isMediaPost`) and `magazine-slot-fit.spec.ts`.
- Modify: `blocks/entry-media/entry-media.component.{ts,html,scss,spec.ts}`.
- Modify: `public/i18n/en.json` and `public/i18n/de.json`: key `reader.playShort`, next to `reader.mediaVideo`.

**Interfaces:**
- Consumes, from Task 5:
  - An untitled post's `excerpt` is `''`.
  - The allow-list accepts `…/embed/<id>?autoplay=1#shorts`.
- Produces:
  - `shortPlayerUrl(entryUrl: string | null): string | null`
  - `isAllowedEmbed(url: string): boolean`
  - `EMBED_SANDBOX`
  - `pauseMedia(root: ParentNode): void`
  - `MEDIA_PAUSE = 'media-pause'`

- [ ] **Step 1: Write the failing specs.**

Create `short-embed.spec.ts`:

```ts
import { shortPlayerUrl } from './short-embed';

const PLAYER = 'https://www.youtube-nocookie.com/embed/87Ov3cS-xMs?autoplay=1#shorts';

describe('shortPlayerUrl', () => {
  it('is the allow-listed nocookie player of a Short, starting on load', () => {
    expect(shortPlayerUrl('https://www.youtube.com/shorts/87Ov3cS-xMs')).toBe(PLAYER);
    expect(shortPlayerUrl('https://m.youtube.com/shorts/87Ov3cS-xMs?feature=share')).toBe(PLAYER);
  });

  it('is null for any other URL', () => {
    expect(shortPlayerUrl('https://www.youtube.com/watch?v=87Ov3cS-xMs')).toBeNull();
    expect(shortPlayerUrl('https://evil.test/shorts/87Ov3cS-xMs')).toBeNull();
    expect(shortPlayerUrl(null)).toBeNull();
  });
});
```

In `magazine-slot-fit.spec.ts`, in `describe('isMediaPost')`, add:

```ts
  it('is a YouTube Short with a cover, though it is no post', () => {
    expect(isMediaPost(entry({ isShort: true }))).toBe(true);
    expect(isMediaPost(entry({ isShort: true, imageUrl: null }))).toBe(false);
  });
```

In `entry-media.component.spec.ts`:
- Make `mount` reuse a fixture-returning helper:

```ts
async function mountFixture(testEntry: EntryDto) {
  TestBed.configureTestingModule({
    imports: [EntryMediaComponent, provideTranslocoTesting()],
    providers: [{ provide: EntryActionHandler, useValue: entryActions }, provideRouter([])],
  });
  const fixture = TestBed.createComponent(EntryMediaComponent);
  fixture.componentRef.setInput('entry', testEntry);
  fixture.detectChanges();
  await fixture.whenStable();
  return fixture;
}

async function mount(testEntry: EntryDto) {
  return (await mountFixture(testEntry)).nativeElement as HTMLElement;
}
```

  If the existing `mount` already differs, for example in the IntersectionObserver stubs the fix wave added, keep those additions in `mountFixture`.
- Change the existing "no caption for a post without text" test's entry to `entry({ title: '(untitled)', excerpt: '' })`. The API now sends an empty excerpt.
- Add a fixture and these tests:

```ts
const COVER = 'https://i1.ytimg.com/vi/87Ov3cS-xMs/hqdefault.jpg';
const short = (over: Partial<EntryDto> = {}): EntryDto =>
  entry({
    title: 'Should Robert Pattinson join the DCU?',
    titleDerived: false,
    isShort: true,
    excerpt: '',
    url: 'https://www.youtube.com/shorts/87Ov3cS-xMs',
    imageUrl: COVER,
    imageWidth: 480,
    imageHeight: 360,
    imageAspectRatio: 0.5625,
    media: [{ url: COVER, kind: 'image', width: 480, height: 360 }],
    ...over,
  });

  it('captions a Short with its title and frames its cover at the picture’s own shape', async () => {
    const element = await mount(short());

    expect(element.querySelector('.caption')!.textContent).toContain('Should Robert Pattinson');
    expect(element.querySelector<HTMLElement>('.media')!.style.getPropertyValue('--ratio')).toBe(
      '0.5625',
    );
    expect(element.querySelector('iframe')).toBeNull();
  });

  it('plays a Short inline in the allow-listed player without opening the post', async () => {
    const fixture = await mountFixture(short());
    const element = fixture.nativeElement as HTMLElement;

    element.querySelector<HTMLButtonElement>('.play')!.click();
    fixture.detectChanges();

    const frame = element.querySelector('iframe')!;
    expect(frame.getAttribute('src')).toBe(
      'https://www.youtube-nocookie.com/embed/87Ov3cS-xMs?autoplay=1#shorts',
    );
    expect(frame.getAttribute('sandbox')).toBe(EMBED_SANDBOX);
    expect(entryActions.open).not.toHaveBeenCalled();
  });

  it('puts a Short’s player away when the list pauses its media', async () => {
    const fixture = await mountFixture(short());
    const element = fixture.nativeElement as HTMLElement;
    element.querySelector<HTMLButtonElement>('.play')!.click();
    fixture.detectChanges();

    element.dispatchEvent(new Event(MEDIA_PAUSE));
    fixture.detectChanges();

    expect(element.querySelector('iframe')).toBeNull();
    expect(element.querySelector('img')!.getAttribute('src')).toBe(COVER);
  });
```

  Add to the imports:

```ts
import { EMBED_SANDBOX } from '../../../../media-embeds';
import { MEDIA_PAUSE } from './pause-media';
```

Run: `docker compose exec -T frontend npx jest src/app/reader/list/magazine`
Expected: FAIL. `short-embed` does not exist yet, `isMediaPost` refuses a Short, and the Short tests find no `.play`.

- [ ] **Step 2: Export the sandbox and the allow-list test.** In `media-embeds.ts`:
- Rename `const SANDBOX` to `export const EMBED_SANDBOX`, keeping its comment.
- Use it in `embedFrame`.
- Add:

```ts
export function isAllowedEmbed(url: string): boolean {
  return ALLOWED.some(({ pattern }) => pattern.test(url));
}
```

- [ ] **Step 3: Create `short-embed.ts`.**

```ts
import { isAllowedEmbed } from '../../../../media-embeds';

const SHORT_URL = /^https?:\/\/(?:www\.|m\.)?youtube\.com\/shorts\/([A-Za-z0-9_-]{11})\/?(?:[?#]|$)/i;

/** The allow-listed player of a YouTube Short, starting on load; null for any other URL. */
export function shortPlayerUrl(entryUrl: string | null): string | null {
  const id = entryUrl?.match(SHORT_URL)?.[1];
  const url = id ? `https://www.youtube-nocookie.com/embed/${id}?autoplay=1#shorts` : null;
  return url && isAllowedEmbed(url) ? url : null;
}
```

- [ ] **Step 4: Rename the pause helper.**
- `git mv frontend/src/app/reader/list/magazine/blocks/entry-media/pause-videos.ts frontend/src/app/reader/list/magazine/blocks/entry-media/pause-media.ts`
- Its content becomes:

```ts
export const MEDIA_PAUSE = 'media-pause';

/** Pauses every video under the root and tells each media block to put its player away. */
export function pauseMedia(root: ParentNode): void {
  for (const video of Array.from(root.querySelectorAll('video'))) video.pause();
  for (const block of Array.from(root.querySelectorAll('app-entry-media'))) {
    block.dispatchEvent(new Event(MEDIA_PAUSE));
  }
}
```

- Update `entry-list.component.ts`, `entry-media.component.ts` and any spec importing `pauseVideos` to use `pauseMedia` from `pause-media`. Keep the list's effect name meaningful, e.g. `_pauseMediaOfOpenEntry`.

- [ ] **Step 5: Let a Short claim the media block.** In `magazine-slot-fit.ts`, replace `isMediaPost`:

```ts
/** A post, or a YouTube Short, whose point is its picture or video. */
export function isMediaPost(entry: EntryDto): boolean {
  const claims = entry.isShort || (entry.titleDerived && !isLinkPost(entry));
  return claims && postMedia(entry).length > 0;
}
```

- [ ] **Step 6: Implement the card.**

In `entry-media.component.ts`:
- Remove the `UNTITLED` constant.
- Add imports: `signal` from `@angular/core`, `DomSanitizer` from `@angular/platform-browser`, `shortPlayerUrl` from `./short-embed`, and `pauseMedia` from `./pause-media`.
- Add `host: { '(media-pause)': 'stopShort()' }` to the `@Component` metadata. The spec dispatches `MEDIA_PAUSE`, which guards the literal against drift.
- Members:

```ts
  private readonly sanitizer = inject(DomSanitizer);

  readonly media = computed(() => postMedia(this.entry()));
  readonly caption = computed(() =>
    this.entry().titleDerived ? this.entry().excerpt : this.entry().title,
  );
  // shortPlayerUrl admits only the allow-listed player, so the URL can be trusted as a frame source.
  readonly shortPlayer = computed(() => {
    const url = shortPlayerUrl(this.entry().isShort ? this.entry().url : null);
    return url ? this.sanitizer.bypassSecurityTrustResourceUrl(url) : null;
  });
  protected readonly playingShort = signal(false);

  protected playShort(event: Event): void {
    event.stopPropagation();
    this.playingShort.set(true);
  }

  protected stopShort(): void {
    this.playingShort.set(false);
  }

  protected ratio(medium: EntryMediumDto): number {
    const entry = this.entry();
    if (medium.url === entry.imageUrl && entry.imageAspectRatio) return entry.imageAspectRatio;
    return medium.width && medium.height ? medium.width / medium.height : UNKNOWN_RATIO;
  }
```

- In the scroll-away observer, observe `'video, .short'`. On a non-intersecting change, pause a `HTMLVideoElement` target, and call `this.stopShort()` for the short frame:

```ts
    this.scrollAway ??= new IntersectionObserver((changes) => {
      for (const change of changes) {
        if (change.isIntersecting) continue;
        if (change.target instanceof HTMLVideoElement) change.target.pause();
        else this.stopShort();
      }
    });
    for (const target of Array.from(this.host.querySelectorAll('video, .short'))) {
      this.scrollAway.observe(target);
    }
```

  The render effect must also re-run when `playingShort` changes, so the new elements are observed. Read `this.playingShort()` in it, next to `this.media()`.
- In the destroy hook, call `pauseMedia(this.host)` where `pauseVideos(this.host)` was.

In `entry-media.component.html`, the `.media` div's content becomes:

```html
    @if (shortPlayer(); as player) {
      <div class="item short">
        @if (playingShort()) {
          <iframe
            class="item"
            [src]="player"
            [title]="caption()"
            sandbox="allow-scripts allow-same-origin allow-presentation"
            allow="autoplay; fullscreen"
            allowfullscreen
            referrerpolicy="strict-origin-when-cross-origin"
          ></iframe>
        } @else {
          <img
            class="item"
            appProxiedImage
            [src]="items[0].url"
            alt=""
            loading="lazy"
            decoding="async"
            referrerpolicy="no-referrer"
          />
          <button
            type="button"
            class="play"
            [attr.aria-label]="'reader.playShort' | transloco"
            (click)="playShort($event)"
            (keydown)="$event.stopPropagation()"
          >
            <span class="material-symbols-outlined" aria-hidden="true">play_arrow</span>
          </button>
        }
      </div>
    } @else {
      <!-- the existing @for over items, unchanged -->
    }
```

The `sandbox` literal must stay static: Angular refuses a bound `sandbox` on an iframe. The spec compares it with `EMBED_SANDBOX`, so a drift fails.

In `entry-media.component.scss`, add the following. All are existing tokens; `--fs-xl` comes from `theme/_type-scale.scss`.

```scss
.short {
  position: relative;
}

.play {
  position: absolute;
  inset: 0;
  display: grid;
  place-items: center;
  border: 0;
  background: none;
  cursor: pointer;
}

.play .material-symbols-outlined {
  padding: var(--space-3);
  border-radius: var(--radius-pill);
  background: var(--media-scrim);
  color: var(--on-media-scrim);
  font-size: var(--fs-xl);
}

iframe.item {
  border: 0;
}
```

In `public/i18n/en.json`, add `"playShort": "Play Short",` after `"mediaVideo": "Video",`. In `de.json`, add `"playShort": "Short abspielen",` in the same place.

- [ ] **Step 7: Run the focused specs, then the gate.**

Run: `docker compose exec -T frontend npx jest src/app/reader/list src/app/reader/media-embeds.spec.ts`
Expected: PASS.

Run:

```bash
docker compose exec -T frontend npx prettier --write src/app/reader public/i18n
docker compose exec -T frontend npm run check
```

Expected: green.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/app/reader frontend/public/i18n
git commit -m "feat(#1522): a YouTube Short uses the media card and plays inline"
```
