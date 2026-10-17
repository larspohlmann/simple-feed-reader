# #1662 The progress rail spans the comments — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The article's progress rail measures the whole reading scope — body, link cards, quoted post, counts row and comments — and, while a social post's comments are still on the way, approximates how long they will be.

**Architecture:** `ReadingScope` already measures the scope's bottom for the reading tail; the rail switches from the body's bottom to a new `readingEnd`, which is that bottom plus the growth each trailing part still expects. A part announces its expected height through an optional `expectedHeight` hook on `TrailingPart`; the comments part gets one from a new `CommentsForecast`, which turns the post's reply count into a height. The counts row loads when the article opens instead of on sight, so the reply count is there before the comments are.

**Tech Stack:** Angular 20 (standalone, signals), Jest (jsdom), run inside the Docker `frontend` container.

**Spec:** GitHub issue #1662 (`gh issue view 1662`) plus the measurements below.

## Measurements (2026-10-17, dev stack, real render)

Taken in the built-in browser on `:4200` against the dev database; these numbers are the constants in Task 2.

- **Thread size vs. reply count** — 100 posts across 20 social feeds (Bluesky home, a Bluesky custom feed, Mastodon home, 17 Bluesky/Mastodon profile feeds from NPR/WaPo/WSJ/taz/heise to individual accounts). The post's `replies` counts **direct replies only**; the comments section renders the whole nested thread, capped at 100 (`social_replies_max_count` in `backend/config/services.yaml`). Rendered/replies ran 0.9–5.6×. Estimating `min(100, round(replies × k))`: k = 1.0 → total 36 % short; k = 1.5 → 8 % short; **k = 1.7 → unbiased** (sum est./sum actual 1.01, mean abs. error 6 comments).
- **Comment height** — 12 threads (9–100 comments) at phone width (section 343 px) and 4 of them at desktop width (688 px), reading font 16 px, comment line height 24 px (`--fs-read`, `--lh-normal: 1.5`). Lines per comment: phone 4.4–7.5 (mean 6.25), desktop 4.8–5.8 for the same threads (≈ 0.76× phone). The fit `lines = 3 + 70 / (width in em)` gives 6.3 on a phone and 4.6 on desktop.
- Reddit (`auto`, no count before load) is out of scope by the user's ruling: it keeps the measured length only.

## Global Constraints

- Frontend conventions in `CLAUDE.md`: standalone + signals, styles in sibling `.scss`, Prettier 100 columns, `npm run check` is the gate.
- Comments: default to none; one line; the constants' derivation goes in the commit body, not the code.
- Commit format `type(#1662): lower-case summary`; branch `fix/1662-rail-spans-comments` off `develop`; no Claude attribution.
- Run Jest inside the container, one run at a time (concurrent runs OOM it): `docker compose exec -T frontend npx jest <path>`.
- The loading strategy of the comments (#1140, on sight) stays untouched.

---

### Task 0: Branch

- [ ] **Step 1:** Check no other session is mid-edit (`git status`), then:

```bash
git fetch origin develop && git checkout -b fix/1662-rail-spans-comments origin/develop
```

---

### Task 1: The rail ends where the reading scope ends, plus what is still expected

**Files:**
- Modify: `frontend/src/app/reader/article/reading/reading-scope.service.ts`
- Modify: `frontend/src/app/reader/article/reader-view/reader-view.component.ts` (the `progressRail` field, ~line 151)
- Test: `frontend/src/app/reader/article/reading/reading-scope.service.spec.ts`
- Test: `frontend/src/app/reader/article/reader-view/reader-view.component.spec.ts` (describe `tail space below a long article`)

**Interfaces:**
- Produces: `TrailingPart.expectedHeight?: (part: HTMLElement, readingFontSize: number) => number | null`; `ReadingScope.readingEnd: Signal<number>` (replaces `contentBottom`, which is deleted).

- [ ] **Step 1: Write the failing scope tests.** In `reading-scope.service.spec.ts`, extend `connectScope` with an optional `commentsExpected` hook and replace the test `'measures the progress rail to the body alone'`:

```ts
function connectScope(parts: {
  content: HTMLElement;
  linkCards?: HTMLElement;
  comments?: HTMLElement;
  commentsExpected?: TrailingPart['expectedHeight'];
}): ReadingScope {
  const scope = TestBed.inject(ReadingScope);
  scope.connect({
    scroller: signal(scrollerOfViewport()),
    content: query(parts.content),
    trailing: [
      { query: query(parts.linkCards), joinsFocus: false },
      { query: query(parts.comments), joinsFocus: true, expectedHeight: parts.commentsExpected },
    ],
  });
  scope.observeResizes();
  TestBed.tick();
  scope.refresh();
  return scope;
}

function partSpanning(top: number, bottom: number): HTMLElement {
  const part = articleHost('<p>Comment</p>');
  part.getBoundingClientRect = () => ({ top, bottom, height: bottom - top }) as DOMRect;
  return part;
}
```

(import `type TrailingPart` from `./reading-scope.service`.) Tests:

```ts
  it('ends the progress rail at the end of the scope, past the body', () => {
    const scope = connectScope({ content: partEndingAt(400), comments: partEndingAt(2400) });

    expect(scope.hasTail()).toBe(true);
    expect(scope.readingEnd()).toBe(2400);
  });

  it('runs the progress rail on by what a part still expects to grow', () => {
    const content = partEndingAt(400);
    content.style.fontSize = '16px';
    const expected = signal<number | null>(3000);
    const expectedHeight = jest.fn(() => expected());
    const comments = partSpanning(2000, 2400);
    const scope = connectScope({ content, comments, commentsExpected: expectedHeight });

    expect(expectedHeight).toHaveBeenCalledWith(comments, 16);
    expect(scope.readingEnd()).toBe(5000);

    expected.set(null);
    TestBed.tick();

    expect(scope.readingEnd()).toBe(2400);
  });

  it('adds nothing for a part already as tall as it expects to be', () => {
    const scope = connectScope({
      content: partEndingAt(400),
      comments: partSpanning(400, 2400),
      commentsExpected: () => 1500,
    });

    expect(scope.readingEnd()).toBe(2400);
  });
```

Also change `'ends at the body when nothing follows it'` to assert `scope.readingEnd()` instead of `scope.contentBottom()` (still `2400`).

- [ ] **Step 2: Run, expect FAIL** (`readingEnd` does not exist; `expectedHeight` is not an allowed key).

```bash
docker compose exec -T frontend npx jest src/app/reader/article/reading/reading-scope.service.spec.ts
```

- [ ] **Step 3: Implement in `reading-scope.service.ts`.**

`TrailingPart` gains the hook:

```ts
/** A section below the article body; `joinsFocus` puts its blocks under the reading-focus dimming. */
export interface TrailingPart {
  readonly query: ElementQuery;
  readonly joinsFocus: boolean;
  /** The height the part will reach once its content arrives, or null when it shows its final content. */
  readonly expectedHeight?: (part: HTMLElement, readingFontSize: number) => number | null;
}
```

Replace the measured signals and the public `contentBottom`:

```ts
  // The tail keys on what is on screen; the progress rail also counts what is still on the way.
  private readonly readingBottom = signal(0);
  private readonly measuredReadingEnd = signal(0);
  private readonly viewportHeight = signal(0);

  /** Where the reading scope will end inside the pane, for the progress rail. */
  readonly readingEnd = this.measuredReadingEnd.asReadonly();
```

In `observeResizes()`, after the ResizeObserver effect, add the effect that re-measures when an expectation changes (it tracks the queries so it subscribes to a part's hook once the part mounts):

```ts
    // An expected height follows its part's loading state, which no resize announces.
    effect(
      () => {
        for (const part of this.trailing) part.query();
        this.measureScrollRange();
      },
      { injector: this.injector },
    );
```

`measureScrollRange` and a new helper:

```ts
  private measureScrollRange(): void {
    const scroller = untracked(this.scroller);
    const content = untracked(this.content)?.nativeElement;
    if (!scroller || !content) {
      this.viewportHeight.set(0);
      this.readingBottom.set(0);
      this.measuredReadingEnd.set(0);
      return;
    }
    const lastPart = this.presentElements(this.trailing).at(-1) ?? content;
    const readingBottom = bottomWithin(scroller, lastPart);
    this.viewportHeight.set(scroller.clientHeight);
    this.readingBottom.set(readingBottom);
    this.measuredReadingEnd.set(readingBottom + this.expectedGrowth(content));
  }

  private expectedGrowth(content: HTMLElement): number {
    const readingFontSize = parseFloat(getComputedStyle(content).fontSize);
    return this.trailing.reduce((growth, part) => {
      const element = untracked(part.query)?.nativeElement;
      const expected =
        element && part.expectedHeight ? part.expectedHeight(element, readingFontSize) : null;
      if (!element || expected === null) return growth;
      return growth + Math.max(0, expected - element.getBoundingClientRect().height);
    }, 0);
  }
```

Update the class doc's "where the progress rail ends" wording only if it no longer reads true (it still does). Delete the old `// The tail keys on the scope's bottom; the progress rail keys on the body alone.` comment (replaced above).

- [ ] **Step 4: Rewire the rail** in `reader-view.component.ts`:

```ts
  protected readonly progressRail = new ScrollProgressRail({
    isWide: this.screen.isWide,
    contentBottom: () => this.scope.readingEnd(),
    layout: this.scope.readingEnd,
  });
```

`grep -rn "contentBottom()" frontend/src/app/reader` must show no other caller of the deleted `ReadingScope.contentBottom`.

- [ ] **Step 5: Reader-view tests.** In `reader-view.component.spec.ts`, describe `tail space below a long article`:

The #1150 test `'adds it when the comments carry a short post past the pane (#1150)'` pinned the old behaviour in its last line. Replace `expect(host.querySelector('app-progress-rail:not(.idle)')).toBeNull();` with `expect(host.querySelector('app-progress-rail:not(.idle)')).not.toBeNull();` — the comments now carry the rail too.

Add after `scrollingArticle`'s tests:

```ts
    it('runs the rail on through the comments below the body (#1662)', () => {
      stubComments(signal<CommentsState>({ status: 'idle' }));
      const fixture = mount(entry({ comments: 'manual' }));
      const comments = (fixture.nativeElement as HTMLElement).querySelector(
        'app-entry-comments',
      ) as HTMLElement;
      comments.getBoundingClientRect = () => ({ top: 2400, bottom: 4000, height: 1600 }) as DOMRect;
      const { scrollTo, fill } = scrollingArticle(fixture);

      scrollTo(1600);

      expect(fill()).toBe('50');
    });
```

(1600 puts the body's last line at the pane's foot; the scope ends at 4000, so 1600 / (4000 − 800) = 50 %.)

- [ ] **Step 6: Run both specs, expect PASS.**

```bash
docker compose exec -T frontend npx jest src/app/reader/article/reading/reading-scope.service.spec.ts src/app/reader/article/reader-view/reader-view.component.spec.ts
```

- [ ] **Step 7: Break-test the guard.** Temporarily make `measureScrollRange` set `measuredReadingEnd` to `bottomWithin(scroller, content)` (the old body-only measure), confirm the new reader-view test FAILs and quote its fill, then restore the line by hand (no `git checkout --`).

- [ ] **Step 8: Commit.**

```bash
git add frontend/src/app/reader/article/reading frontend/src/app/reader/article/reader-view
git commit -m "fix(#1662): the progress rail ends where the reading scope ends"
```

Body: the rail kept #238's body-only measure when #1150 brought the comments into the scope; with many comments it read full at the body's last line and stayed full through them, and on a phone the rail is the only position cue. `expectedHeight` lets a still-loading part extend the rail.

---

### Task 2: The comments forecast

**Files:**
- Create: `frontend/src/app/reader/article/entry-comments/comments-forecast.ts`
- Create: `frontend/src/app/reader/article/entry-comments/comments-forecast.spec.ts`
- Modify: `frontend/src/app/reader/article/reader-view/reader-view.component.ts` (`trailingParts`, a new `commentsForecast` field, imports)
- Test: `frontend/src/app/reader/article/reader-view/reader-view.component.spec.ts`

**Interfaces:**
- Consumes: `TrailingPart.expectedHeight` (Task 1); `CommentsService.state(id): Signal<CommentsState>`; `SocialCountsService.state(id): Signal<SocialCountsState>`.
- Produces: `expectedThreadSize(replies: number): number`, `expectedCommentHeight(readingFontSize: number, width: number): number`, `class CommentsForecast { constructor(entry, comments, counts); expectedHeight(section: HTMLElement, readingFontSize: number): number | null }`.

- [ ] **Step 1: Write the failing spec** `comments-forecast.spec.ts`:

```ts
import { signal } from '@angular/core';
import { anEntry } from '../../../../testing/entry-testing';
import { EntryDto } from '../../models';
import { SocialCountsService, SocialCountsState } from '../social-counts/social-counts.service';
import { CommentsService, CommentsState } from './comments.service';
import { CommentsForecast, expectedCommentHeight, expectedThreadSize } from './comments-forecast';

const READING_FONT = 16;
const PHONE_WIDTH = 343;

function sectionOfWidth(width: number): HTMLElement {
  const section = document.createElement('section');
  Object.defineProperty(section, 'clientWidth', { value: width });
  return section;
}

const countsOf = (replies: number): SocialCountsState => ({
  status: 'ok',
  counts: { likes: 0, reposts: 0, replies, quotes: 0 },
  likedAtLoad: false,
  loadedAt: 0,
});

function forecastFor(
  entry: EntryDto,
  comments: CommentsState = { status: 'idle' },
  counts: SocialCountsState = countsOf(10),
): CommentsForecast {
  return new CommentsForecast(
    () => entry,
    { state: () => signal(comments) } as unknown as CommentsService,
    { state: () => signal(counts) } as unknown as SocialCountsService,
  );
}

const socialPost = (over: Partial<EntryDto> = {}): EntryDto =>
  anEntry({
    id: 1,
    comments: 'auto',
    social: { network: 'bluesky', isLiked: false, canLike: true },
    ...over,
  });

describe('expectedThreadSize', () => {
  it('counts the nested replies a direct reply draws', () => {
    expect(expectedThreadSize(10)).toBe(17);
  });

  it('stops at the most comments a thread renders', () => {
    expect(expectedThreadSize(71)).toBe(100);
  });
});

describe('expectedCommentHeight', () => {
  it('runs taller on a narrow pane, where the text wraps more', () => {
    expect(expectedCommentHeight(READING_FONT, PHONE_WIDTH)).toBeCloseTo(150.4, 1);
    expect(expectedCommentHeight(READING_FONT, 688)).toBeCloseTo(111.1, 1);
  });
});

describe('CommentsForecast', () => {
  it('expects the thread a social post counts while its comments are on the way', () => {
    const expected = expectedThreadSize(10) * expectedCommentHeight(READING_FONT, PHONE_WIDTH);

    for (const status of ['idle', 'loading'] as const) {
      const forecast = forecastFor(socialPost(), { status });
      expect(forecast.expectedHeight(sectionOfWidth(PHONE_WIDTH), READING_FONT)).toBeCloseTo(
        expected,
      );
    }
  });

  it('leaves the comments to their own height once they arrive', () => {
    const loaded: CommentsState = {
      status: 'ok',
      comments: [],
      omitted: 0,
      fetchedAt: '2026-10-17T12:00:00Z',
      loadedAt: 0,
    };

    expect(forecastFor(socialPost(), loaded).expectedHeight(sectionOfWidth(PHONE_WIDTH), READING_FONT)).toBeNull();
  });

  it('expects nothing before the post counts its replies', () => {
    const forecast = forecastFor(socialPost(), { status: 'idle' }, { status: 'loading' });

    expect(forecast.expectedHeight(sectionOfWidth(PHONE_WIDTH), READING_FONT)).toBeNull();
  });

  it('expects nothing of comments the reader loads by hand', () => {
    const forecast = forecastFor(socialPost({ comments: 'manual' }));

    expect(forecast.expectedHeight(sectionOfWidth(PHONE_WIDTH), READING_FONT)).toBeNull();
  });

  it('expects nothing of a discussion without a reply count', () => {
    const forecast = forecastFor(socialPost({ social: null }));

    expect(forecast.expectedHeight(sectionOfWidth(PHONE_WIDTH), READING_FONT)).toBeNull();
  });

  it('expects nothing it cannot lay out', () => {
    const forecast = forecastFor(socialPost());

    expect(forecast.expectedHeight(sectionOfWidth(0), READING_FONT)).toBeNull();
    expect(forecast.expectedHeight(sectionOfWidth(PHONE_WIDTH), Number.NaN)).toBeNull();
  });
});
```

(Prettier will rewrap the long `expect` line; run it.)

- [ ] **Step 2: Run, expect FAIL** (module missing).

```bash
docker compose exec -T frontend npx jest src/app/reader/article/entry-comments/comments-forecast.spec.ts
```

- [ ] **Step 3: Implement** `comments-forecast.ts`:

```ts
import { EntryDto } from '../../models';
import { SocialCountsService } from '../social-counts/social-counts.service';
import { CommentsService } from './comments.service';

type ForecastEntry = Pick<EntryDto, 'id' | 'comments' | 'social'>;

/** Rendered comments per direct reply a post counts, measured over 100 posts (#1662). */
const COMMENTS_PER_REPLY = 1.7;
/** The backend's `social_replies_max_count`. */
const MOST_THREAD_COMMENTS = 100;
const COMMENT_LINE_HEIGHT = 1.5;
/** A comment's byline, padding and media, in lines. */
const COMMENT_CHROME_LINES = 3;
/** A comment's text, in lines on a pane one em wide. */
const COMMENT_TEXT_LINES_PER_EM = 70;

export function expectedThreadSize(replies: number): number {
  return Math.min(MOST_THREAD_COMMENTS, Math.round(replies * COMMENTS_PER_REPLY));
}

export function expectedCommentHeight(readingFontSize: number, width: number): number {
  const textLines = (COMMENT_TEXT_LINES_PER_EM * readingFontSize) / width;
  return readingFontSize * COMMENT_LINE_HEIGHT * (COMMENT_CHROME_LINES + textLines);
}

/** How tall a social post's comments will be while they are still on the way. */
export class CommentsForecast {
  constructor(
    private readonly entry: () => ForecastEntry | null,
    private readonly comments: CommentsService,
    private readonly counts: SocialCountsService,
  ) {}

  /** The section's height once its comments render, or null when it already shows what it will. */
  expectedHeight(section: HTMLElement, readingFontSize: number): number | null {
    const entry = this.entry();
    if (!entry?.social || entry.comments !== 'auto' || !this.awaitsComments(entry.id)) return null;
    const replies = this.replies(entry.id);
    if (replies === null || Number.isNaN(readingFontSize) || section.clientWidth === 0) return null;
    return expectedThreadSize(replies) * expectedCommentHeight(readingFontSize, section.clientWidth);
  }

  private awaitsComments(id: number): boolean {
    const status = this.comments.state(id)().status;
    return status === 'idle' || status === 'loading';
  }

  private replies(id: number): number | null {
    const state = this.counts.state(id)();
    return state.status === 'ok' ? state.counts.replies : null;
  }
}
```

- [ ] **Step 4: Run, expect PASS** (same command as Step 2).

- [ ] **Step 5: Wire it into the reader view.** In `reader-view.component.ts` import `CommentsService` (`../entry-comments/comments.service`), `SocialCountsService` (`../social-counts/social-counts.service`) and `CommentsForecast` (`../entry-comments/comments-forecast`); add above `trailingParts`:

```ts
  private readonly commentsForecast = new CommentsForecast(
    this.entry,
    inject(CommentsService),
    inject(SocialCountsService),
  );
```

and give the comments part its hook:

```ts
    {
      query: this.commentsSection,
      joinsFocus: true,
      expectedHeight: (section, readingFontSize) =>
        this.commentsForecast.expectedHeight(section, readingFontSize),
    },
```

- [ ] **Step 6: Reader-view wiring test** (same describe as Task 1 Step 5):

```ts
    it('runs the rail on by the thread a social post counts before it loads (#1662)', () => {
      stubComments(signal<CommentsState>({ status: 'idle' }));
      TestBed.overrideProvider(SocialCountsService, {
        useValue: {
          state: () =>
            signal<SocialCountsState>({
              status: 'ok',
              counts: { likes: 0, reposts: 0, replies: 10, quotes: 0 },
              likedAtLoad: false,
              loadedAt: 0,
            }),
          load: jest.fn(),
          reload: jest.fn(),
        },
      });
      const fixture = mount(
        entry({ comments: 'auto', social: { network: 'bluesky', isLiked: false, canLike: true } }),
      );
      const host = fixture.nativeElement as HTMLElement;
      const comments = host.querySelector('app-entry-comments') as HTMLElement;
      comments.getBoundingClientRect = () => ({ top: 2400, bottom: 2448, height: 48 }) as DOMRect;
      Object.defineProperty(comments, 'clientWidth', { value: 343 });
      (host.querySelector('.content') as HTMLElement).style.fontSize = '16px';
      const { scrollTo, fill } = scrollingArticle(fixture);

      scrollTo(1600);

      expect(Number(fill())).toBeLessThan(40);
    });
```

(The auto section uses an IntersectionObserver; if the describe has none, stub it as the `#1615` counts describe does. 17 comments × 150.4 px − the 48 px already shown ≈ 2508 px past a 2448 end, so the scope ends near 4956 and the body's last line sits at 1600 / 4156 ≈ 38 %.)

- [ ] **Step 7: Run reader-view spec, expect PASS.** Break-test: drop the `expectedHeight` line from the comments part, see the new test FAIL (quote the fill), restore.

- [ ] **Step 8: Commit.**

```bash
git add frontend/src/app/reader/article/entry-comments frontend/src/app/reader/article/reader-view
git commit -m "fix(#1662): the rail approximates a social post's comments before they load"
```

Body: carry the **Measurements** section above (thread size 1.7× replies capped at 100; comment height `1.5em × (3 + 70em / width)`), and that Reddit and manual sections keep their measured length.

---

### Task 3: The counts row loads when the article opens

**Files:**
- Modify: `frontend/src/app/reader/article/social-counts/social-counts.component.ts`
- Modify: `frontend/src/app/reader/article/reader-view/reader-view.component.html:214`
- Test: `frontend/src/app/reader/article/social-counts/social-counts.component.spec.ts`

**Interfaces:**
- Produces: `SocialCountsComponent` without a `scroller` input.

Why: the forecast needs the reply count before the comments arrive; on sight, the row and the comments load at the same moment. #1615's issue asked for the counts "fetched live when the article opens" and said an extra call per opened article is fine; its plan copied the comments' on-sight loading without a reason.

- [ ] **Step 1: Rewrite the two on-sight tests** (`'loads only once the row nears the viewport'`, `'looks ahead within the reader scroller'`) as:

```ts
  it('loads as soon as the post opens', () => {
    const entry = post();
    show(entry);

    expect(service.load).toHaveBeenCalledWith({ id: 1, social: entry.social });
  });

  it('loads a post once, however often its like changes', () => {
    show(post());
    show(post({ isLiked: true }));

    expect(service.load).toHaveBeenCalledTimes(1);
  });
```

Remove from the spec what only served the observer: `observers`, `trigger`, `scroller`, `realIntersectionObserver`, the `IntersectionObserver` stub and its `afterEach` restore, the `prefetchMargin` import, and `setInput('scroller', …)` in `show`.

- [ ] **Step 2: Run, expect FAIL** (`load` not called without a sighting).

```bash
docker compose exec -T frontend npx jest src/app/reader/article/social-counts/social-counts.component.spec.ts
```

- [ ] **Step 3: Implement.** In `social-counts.component.ts` delete the `scroller` input and the `observeOnSight` import; replace the constructor with:

```ts
  constructor() {
    effect(() => {
      const id = this.entryId();
      untracked(() => this.service.load({ id, social: this.entry().social }));
    });
  }
```

(import `untracked`; drop `ElementRef`/`inject` only if unused — `host` stays, the reader view reads it.) In `reader-view.component.html` change `<app-social-counts [entry]="e" [scroller]="scroller" />` to `<app-social-counts [entry]="e" />`.

- [ ] **Step 4: Run the counts spec and the reader-view spec, expect PASS.**

- [ ] **Step 5: Commit.**

```bash
git add frontend/src/app/reader/article/social-counts frontend/src/app/reader/article/reader-view
git commit -m "fix(#1662): the counts row loads when the article opens"
```

---

### Task 4: Gate and real-render verification

- [ ] **Step 1:** `docker compose exec -T frontend npm run check` — must pass (ESLint, Prettier, Stylelint, tsc, Jest).
- [ ] **Step 2: Real render.** Container current first (`verify-containers-are-current`). In the built-in browser on `:4200`, Mobile viewport, open `?entry=609161` (NPR, 71 replies, 100 comments rendered). Read the rail with `document.querySelector('app-progress-rail').style.getPropertyValue('--rail-fill')`:
  - right after open, scrolled so the body's last line is at the pane's foot: well below 100 (the forecast holds the comments' length);
  - after the comments render (scroll on, or `ng.getComponent(app-entry-comments).load()`), the same scroll position: the fill should move by less than ~15 points;
  - at the last comment: 100.
  Repeat at desktop width, and once on a Reddit entry (`?entry=612913`) and a manual-comments entry to see they behave as before apart from Task 1.
- [ ] **Step 3:** Push, open the PR against `develop` with `Closes #1662`, quoting the measurements and the before/after fills.
