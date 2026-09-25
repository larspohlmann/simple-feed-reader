# Reading Focus Over the Comments Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The article reading focus dims and highlights the comments section the same way it does the article body, and a short post with a long thread gets the tail space its last comments need to reach the reading centre (#1150).

**Architecture:** The reading scope of `ReaderViewComponent` grows from `#content` to `#content` plus the `<app-entry-comments>` host. The `ReadingFocusApplier`'s `blocks` callback collects `readingBlocks` over both roots, so comments follow the body's rules unchanged (a tall list divides into #1077 sections). The comments load lazily, after the applier's last refresh, so a `ResizeObserver` on the comments host refreshes the applier and re-measures the scroll range. The tail keys on a new `readingBottom` (the bottom of the reading scope); the progress bar keeps `contentBottom` (the article body), so a full bar still means "article finished".

**Tech Stack:** Angular 20 (standalone, signals, `viewChild`, `untracked`), Jest + jsdom.

**Spec:** GitHub issue #1150 (root cause and fix), reproduced on entry 543216 ("Cost of Ai subs after the bubble birst", r/ClaudeAI) at 375×812: body paragraph dims to `0.28`, none of the 18 comments ever carries an opacity.

## Global Constraints

- General rule, no comment-specific DOM knowledge in the reader view: comments go through `readingBlocks` exactly like the body. Per-comment units are an explicit non-goal (user decision; revisit only if the grouping reads badly).
- The progress bar and `showProgress` keep measuring the article body (`contentBottom`), not the comments.
- No change to `ReadingFocusApplier`, `reading-focus.ts` or `reading-sections.ts`.
- Comments: default to none; one line where a future reader would otherwise break it (the `untracked` read qualifies).
- Frontend tests run in the Docker frontend container: `docker compose exec -T frontend npx jest <path>`; the gate is `docker compose exec -T frontend npm run check`. Never run two Jest processes concurrently (container OOM).
- Commits: `fix(#1150): …`.

---

### Task 1: Reading focus covers the comments section

**Files:**
- Modify: `frontend/src/app/reader/reader-view/reader-view.component.ts` (imports; `viewChild` next to `content` at ~line 160; the applier effect at ~lines 404–417; a new effect next to the `contentObs` effect at ~lines 474–486; a new private field next to `contentObs` at ~line 201)
- Test: `frontend/src/app/reader/reader-view/reader-view.component.spec.ts` (the `describe('comments section (#1140)')` block at ~line 967)

**Interfaces:**
- Produces: `private readonly commentsSection = viewChild(EntryCommentsComponent, { read: ElementRef<HTMLElement> })` — Task 2 reads it in `measureScrollRange()`.
- Produces: `private commentsObs?: ResizeObserver`, whose callback calls `this.applier?.refresh()` and `this.measureScrollRange()`.

- [ ] **Step 1: Write the failing tests**

In `reader-view.component.spec.ts`, replace the `describe('comments section (#1140)', …)` block's `beforeEach` so each test can drive the comments state, and add two tests. The fake service hands out one writable signal; `'manual'` is used because `'auto'` needs an `IntersectionObserver`, which jsdom lacks. The existing three tests keep working: their state starts `idle`.

```ts
  describe('comments section (#1140)', () => {
    let commentsState: WritableSignal<CommentsState>;

    beforeEach(() => {
      commentsState = signal<CommentsState>({ status: 'idle' });
      TestBed.overrideProvider(CommentsService, {
        useValue: { state: () => commentsState, load: jest.fn(), reload: jest.fn() },
      });
    });

    function commentsSection(f: { nativeElement: HTMLElement }): Element | null {
      return f.nativeElement.querySelector('article app-entry-comments');
    }

    const loadedComments: CommentsState = {
      status: 'ok',
      comments: [
        {
          author: 'u/first',
          authorUrl: null,
          url: null,
          publishedAt: null,
          byEntryAuthor: false,
          html: '<p>First comment</p>',
        },
      ],
      loadedAt: 0,
    };

    // … the three existing tests stay as they are …

    it('falls under the reading focus with the article body (#1150)', async () => {
      commentsState.set(loadedComments);
      const f = mount(entry({ comments: 'manual' }));
      await Promise.resolve();
      f.detectChanges();
      await new Promise<void>((resolve) => requestAnimationFrame(() => resolve()));

      const list = commentsSection(f)!.querySelector<HTMLElement>('.list')!;
      expect(list.style.opacity).not.toBe('');
    });

    it('re-seats the reading focus when the comments arrive late (#1150)', async () => {
      commentsState.set({ status: 'loading' });
      const f = mount(entry({ comments: 'manual' }));
      await Promise.resolve();
      f.detectChanges();
      await new Promise<void>((resolve) => requestAnimationFrame(() => resolve()));

      commentsState.set(loadedComments);
      f.detectChanges();
      const host = commentsSection(f)!;
      MockResizeObserver.instances.find((observer) => observer.targets.has(host))!.fire();
      await new Promise<void>((resolve) => requestAnimationFrame(() => resolve()));

      expect(host.querySelector<HTMLElement>('.list')!.style.opacity).not.toBe('');
    });
  });
```

The fixture matches `EntryCommentDto` (`models.ts:169`) and the `ok` arm of `CommentsState` (`comments.service.ts:14`). `WritableSignal` and `signal` are already imported at the top of the spec.

In jsdom every rect is zero and `clientHeight` is 0, so `focusOpacityForSpan` answers `1` and the applier writes `'1'` — "not empty" is the observable proof a block is under the focus, the same assertion the existing `restores dimming` test uses.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/reader-view/reader-view.component.spec.ts -t "#1150"`
Expected: both FAIL — the first with `.list` opacity `''`; the second with `Cannot read properties of undefined (reading 'fire')` (no observer watches the comments host).

- [ ] **Step 3: Implement**

In `reader-view.component.ts`, add `untracked` to the `@angular/core` import, then next to `content`:

```ts
  private readonly content = viewChild<ElementRef<HTMLElement>>('content');
  private readonly commentsSection = viewChild(EntryCommentsComponent, {
    read: ElementRef<HTMLElement>,
  });
```

Next to `private contentObs?: ResizeObserver;`:

```ts
  private commentsObs?: ResizeObserver;
```

In the applier effect, widen `blocks` to the reading scope:

```ts
      this.applier = new ReadingFocusApplier({
        scroller: this.host.nativeElement,
        blocks: () => [content, ...this.commentsRoot()].flatMap((root) => readingBlocks(root)),
        curve: ARTICLE_FOCUS_CURVE,
        isActive: () => this.readingFocus.enabled() && !this.screen.isWide() && !this.reduceMotion,
        units: sectionedUnits(() => this.language.lang()),
      });
```

Add the helper as a private method (next to `measureScrollRange()`):

```ts
  // `blocks()` runs inside effects, which must not rerun when the comments mount.
  private commentsRoot(): HTMLElement[] {
    const comments = untracked(this.commentsSection);
    return comments ? [comments.nativeElement] : [];
  }
```

After the `contentObs` effect (before its `onDestroy`), add the late-arrival trigger:

```ts
    // The comments load after the article, past the applier's last refresh.
    effect(() => {
      const comments = this.commentsSection()?.nativeElement;
      this.commentsObs?.disconnect();
      this.commentsObs = undefined;
      if (!comments || typeof ResizeObserver === 'undefined') return;
      const obs = new ResizeObserver(() => {
        this.applier?.refresh();
        this.measureScrollRange();
      });
      obs.observe(comments);
      this.commentsObs = obs;
    });
    this.destroyRef.onDestroy(() => this.commentsObs?.disconnect());
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `docker compose exec -T frontend npx jest src/app/reader/reader-view/reader-view.component.spec.ts`
Expected: the whole file PASSES, including both `#1150` tests and the existing `reading focus setting` tests.

- [ ] **Step 5: Verify the guard by breaking it**

Temporarily change `blocks` back to `() => readingBlocks(content)` by editing (not `git checkout --`); rerun `-t "#1150"`; both tests must FAIL. Then remove only the `obs.observe(comments);` line; the second test must FAIL. Restore both edits and rerun the file green.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/reader/reader-view/reader-view.component.ts frontend/src/app/reader/reader-view/reader-view.component.spec.ts
git commit -m "fix(#1150): extend the reading focus over the comments section"
```

---

### Task 2: The tail keys on the bottom of the reading scope

**Files:**
- Modify: `frontend/src/app/reader/reader-view/reader-view.component.ts` (signals at ~lines 260–268; `measureScrollRange()` at ~line 702)
- Test: `frontend/src/app/reader/reader-view/reader-view.component.spec.ts` (`describe('tail space below a long article')` at ~line 409)

**Interfaces:**
- Consumes: `commentsSection` from Task 1.
- Produces: `private readonly readingBottom = signal(0)`, feeding `hasTail` only.

- [ ] **Step 1: Write the failing test**

In the tail `describe`, add a `CommentsService` override and one test. The comments host is stubbed below a body that fits the pane:

```ts
    it('adds it when the comments carry a short post past the pane (#1150)', () => {
      TestBed.overrideProvider(CommentsService, {
        useValue: {
          state: () => signal<CommentsState>({ status: 'idle' }),
          load: jest.fn(),
          reload: jest.fn(),
        },
      });
      const f = mount(entry({ comments: 'manual' }));
      const comments = (f.nativeElement as HTMLElement).querySelector(
        'app-entry-comments',
      ) as HTMLElement;
      comments.getBoundingClientRect = () => ({ top: 400, bottom: 2400 }) as DOMRect;

      const host = stubGeometry(f, 400, 800);

      expect(host.querySelector('.reader')!.classList).toContain('with-tail');
      expect(host.querySelector('.progress-rail, .progress')).toBeNull();
    });
```

The second expectation pins the constraint that the progress bar still measures the body: a body that fits shows no bar, whatever the thread's length.

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec -T frontend npx jest src/app/reader/reader-view/reader-view.component.spec.ts -t "short post past the pane"`
Expected: FAIL — `with-tail` missing.

- [ ] **Step 3: Implement**

Next to `contentBottom`:

```ts
  private readonly contentBottom = signal(0);
  private readonly readingBottom = signal(0);
```

```ts
  readonly hasTail = computed(() => needsReadingTail(this.readingBottom(), this.viewportHeight()));
```

Replace `measureScrollRange()`:

```ts
  private measureScrollRange(): void {
    this.viewportHeight.set(this.host.nativeElement.clientHeight);
    const content = this.content()?.nativeElement;
    if (!content) {
      this.contentBottom.set(0);
      this.readingBottom.set(0);
      return;
    }
    this.contentBottom.set(this.bottomInScroller(content));
    this.readingBottom.set(this.bottomInScroller(this.commentsRoot()[0] ?? content));
  }

  private bottomInScroller(element: HTMLElement): number {
    const host = this.host.nativeElement;
    return element.getBoundingClientRect().bottom - host.getBoundingClientRect().top + host.scrollTop;
  }
```

Update the comment above the signals (~line 259) only if it now says something false; it names "the reading tail and the progress bar" as derived from the scroll range, which still holds.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `docker compose exec -T frontend npx jest src/app/reader/reader-view/reader-view.component.spec.ts`
Expected: PASS, including the two existing tail tests.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/reader-view/reader-view.component.ts frontend/src/app/reader/reader-view/reader-view.component.spec.ts
git commit -m "fix(#1150): size the reading tail to the comments below a short post"
```

---

### Task 3: Gate and real-render verification

- [ ] **Step 1: Frontend gate**

Run: `docker compose exec -T frontend npm run check`
Expected: ESLint, Prettier, Stylelint and Jest all green.

- [ ] **Step 2: Real render at phone width**

Confirm the frontend container serves the new chunk (hard reload; see the stale-chunk memory). In the built-in browser, `resize_window preset: "mobile"`, open `http://localhost:4200/?entry=543216`, scroll through the thread, and read the `li.comment` / `.list` opacities with `javascript_tool`:
- comments away from the centre carry opacity < 1; the ones at the centre carry `1`;
- scrolled to the very end, the last comment can sit at the centre (tail present), i.e. its opacity reaches `1`;
- the body paragraph still dims (`0.28`) when scrolled away;
- the progress rail stays absent for this short post.

Take one screenshot mid-thread for the PR. Reset the viewport to `desktop` afterwards.
