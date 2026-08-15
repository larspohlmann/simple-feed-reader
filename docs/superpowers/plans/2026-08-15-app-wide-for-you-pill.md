# App-wide For-You progress pill — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show a running For-You recommendation run's progress in the app-wide toast pill on every route, and let that same pill carry the ready message when the run finishes.

**Architecture:** The pill is a second mode of the existing shared toast, not a new surface. `ToastData` becomes a union of a message toast and a component-backed content toast, gains a persistent mode (`durationMs: null`) and a fixed-width mode. `RecommendationsService` raises the pill the moment a run goes live and takes it down in `finish()`, the single exit from every end state. `ForYouProgressComponent` moves out of the reader's list header and becomes the pill's content; it already reads the service directly, so nothing new is plumbed.

**Tech Stack:** Angular 20 standalone components and signals, Angular CDK Dialog/Overlay, Transloco, Jest + jsdom, SCSS with design tokens.

Design spec: [`docs/superpowers/specs/2026-08-15-app-wide-for-you-pill-design.md`](../specs/2026-08-15-app-wide-for-you-pill-design.md).
Issue: [#398](https://github.com/larspohlmann/simple-feed-reader/issues/398).
Branch: `feature/398-app-wide-for-you-pill` (already created, spec already committed).

## Global Constraints

- All work is in `frontend/`. Run every command from `frontend/`.
- The CI gate is `npm run check` (ESLint + Prettier + Stylelint + Jest). Run single specs during a task with `npx jest <path>`.
- Prettier is configured to 100 columns. Break long chains rather than let Prettier reflow them.
- Stylelint forbids hex colours, ad-hoc `px` spacing values and media-query literals in `.scss` outside `src/app/theme/`. Use tokens (`var(--space-*)`, `var(--fs-*)`, `var(--radius-*)`) and `rem`.
- Component styles live in a sibling `.scss` file referenced by `styleUrl`. Never inline styles in the `.ts` — Stylelint cannot see them.
- Standalone components and signals. No NgModules.
- Comments explain *why*, never *what*. Match the density and tone of the surrounding code, which is heavily commented with decision records and issue numbers.
- `shared/` components must not know any feature's i18n keys. `ToastData` carries already-translated strings only.
- Commit after each task with a `type(#398): subject` message.

---

### Task 1: The toast learns to host a persistent, component-backed pill

Three capabilities land together, because they describe one thing: a toast that can be a long-lived surface hosting a feature's own component at a stable width. They share a type change, so splitting them would mean writing `ToastData` twice.

**Files:**
- Modify: `frontend/src/app/shared/toast/toast.component.ts`
- Modify: `frontend/src/app/shared/toast/toast.component.html`
- Modify: `frontend/src/app/shared/toast/toast.component.scss`
- Modify: `frontend/src/app/shared/toast/toast.service.ts`
- Modify: `frontend/src/styles.scss:29-31`
- Test: `frontend/src/app/shared/toast/toast.service.spec.ts`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces:
  - `export type ToastData = MessageToast | ContentToast` from `shared/toast/toast.component.ts`.
  - `MessageToast = ToastBase & { message: string }`, `ContentToast = ToastBase & { content: Type<unknown> }`.
  - `ToastBase` fields: `actionLabel?: string`, `action?: () => void`, `durationMs?: number | null`, `width?: 'fit' | 'fixed'`.
  - `ToastService.show(toast: ToastData): void` and `ToastService.dismiss(): void` keep their existing signatures.
  - CSS class `app-toast--fixed` on the overlay pane when `width: 'fixed'`.

- [ ] **Step 1: Write the failing tests**

Append these four tests inside the existing `describe('ToastService', …)` block in `frontend/src/app/shared/toast/toast.service.spec.ts`, before its closing `});`.

Widen the file's existing `@angular/core` import — do not add a second one, ESLint rejects duplicate imports from one module. Replace:

```ts
import { ApplicationRef } from '@angular/core';
```

with:

```ts
import { ApplicationRef, ChangeDetectionStrategy, Component } from '@angular/core';
```

Then add, immediately after the `const el = () => container.querySelector<HTMLElement>('.toast');` line:

```ts
  @Component({
    selector: 'app-toast-test-content',
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `<p class="hosted">hosted content</p>`,
  })
  class HostedContentComponent {}
```

And the tests:

```ts
  it('never auto-dismisses a toast whose durationMs is null', () => {
    jest.useFakeTimers();
    toast.show({ message: 'Ranking your feeds', durationMs: null });
    tick();

    jest.advanceTimersByTime(600_000);
    tick();

    expect(el()).not.toBeNull();
  });

  it('lets the close button dismiss a persistent toast', () => {
    jest.useFakeTimers();
    toast.show({ message: 'Ranking your feeds', durationMs: null });
    tick();

    el()!.querySelector<HTMLButtonElement>('.close')!.click();
    tick();

    expect(el()).toBeNull();
  });

  it('renders a content toast through the component outlet instead of a message', () => {
    toast.show({ content: HostedContentComponent });
    tick();

    expect(el()!.querySelector('.hosted')!.textContent).toBe('hosted content');
  });

  it('marks the pane fixed-width only when the toast asks for it', () => {
    toast.show({ message: 'Fits its content' });
    tick();
    expect(container.querySelector('.cdk-overlay-pane')!.classList).not.toContain(
      'app-toast--fixed',
    );

    toast.show({ message: 'Holds one width', width: 'fixed' });
    tick();
    expect(container.querySelector('.cdk-overlay-pane')!.classList).toContain('app-toast--fixed');
  });
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
npx jest src/app/shared/toast/toast.service.spec.ts
```

Expected: FAIL. The `durationMs: null` test fails because `??` folds `null` into the 6000 ms default and the toast disappears. The content test fails to compile — `content` is not on `ToastData`. The width test fails because the pane carries only `app-toast`.

- [ ] **Step 3: Rewrite the toast's data type and component**

Replace the whole of `frontend/src/app/shared/toast/toast.component.ts` with:

```ts
// src/app/shared/toast/toast.component.ts
import { ChangeDetectionStrategy, Component, Type, inject } from '@angular/core';
import { NgComponentOutlet } from '@angular/common';
import { DIALOG_DATA } from '@angular/cdk/dialog';
import { ToastService } from './toast.service';

/** What every toast carries, whichever mode it is in. Already-translated
 *  strings only -- this lives in shared/ and must not know any feature's
 *  i18n keys. */
interface ToastBase {
  actionLabel?: string;
  action?: () => void;
  /** Omitted takes the 6000ms default. An explicit `null` never auto-dismisses,
   *  for a surface that must live as long as the work it reports on. */
  durationMs?: number | null;
  /** `fixed` holds one box width across successive toasts, so a surface whose
   *  content changes mid-life does not resize under the user. */
  width?: 'fit' | 'fixed';
}

/** A toast showing one translated line. */
export type MessageToast = ToastBase & { message: string };

/** A toast hosting a feature's own component, for content this shared shell
 *  cannot render itself -- a live progress readout, for one. The component is
 *  built through the outlet, so it injects and reads its own feature's
 *  services without anything being threaded through here. */
export type ContentToast = ToastBase & { content: Type<unknown> };

export type ToastData = MessageToast | ContentToast;

@Component({
  selector: 'app-toast',
  imports: [NgComponentOutlet],
  templateUrl: './toast.component.html',
  styleUrl: './toast.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ToastComponent {
  private readonly data = inject<ToastData>(DIALOG_DATA);
  readonly svc = inject(ToastService);

  readonly message = 'message' in this.data ? this.data.message : null;
  readonly content = 'content' in this.data ? this.data.content : null;
  readonly actionLabel = this.data.actionLabel ?? null;

  onAction(): void {
    this.data.action?.();
    this.svc.dismiss();
  }
}
```

- [ ] **Step 4: Render the two modes**

Replace the whole of `frontend/src/app/shared/toast/toast.component.html` with:

```html
<!-- src/app/shared/toast/toast.component.html -->
<div class="toast" role="status" aria-live="polite">
  @if (content) {
    <div class="msg">
      <ng-container *ngComponentOutlet="content"></ng-container>
    </div>
  } @else {
    <span class="msg">{{ message }}</span>
  }
  @if (actionLabel) {
    <button type="button" class="act" (click)="onAction()">{{ actionLabel }}</button>
  }
  <button type="button" class="close" (click)="svc.dismiss()" aria-label="✕">✕</button>
</div>
```

In `frontend/src/app/shared/toast/toast.component.scss`, change the `.msg` rule so hosted content can wrap inside a flex row rather than force the box wider:

```scss
.msg {
  flex: 1;
  min-width: 0;
}
```

- [ ] **Step 5: Teach the service the persistent and fixed-width modes**

In `frontend/src/app/shared/toast/toast.service.ts`, replace the `show()` method and add two private helpers below it:

```ts
  /** Replaces any toast currently visible. */
  show(toast: ToastData): void {
    this.clearTimer();
    this.ref?.close();

    this.ref = this.dialog.open<void, ToastData, ToastComponent>(ToastComponent, {
      panelClass: this.panelClasses(toast),
      positionStrategy: this.overlay
        .position()
        .global()
        .centerHorizontally()
        .bottom(this.bottomOffset()),
      hasBackdrop: false,
      autoFocus: false,
      restoreFocus: false,
      data: toast,
    });

    const durationMs = this.durationOf(toast);
    if (durationMs === null) return;
    this.timer = setTimeout(() => this.dismiss(), durationMs);
  }

  /** Omitting the duration takes the default; an explicit `null` means never.
   *  `??` cannot express that pair -- it folds `null` into the default and
   *  would dismiss a run pill after six seconds -- so the two cases are read
   *  apart here rather than coalesced. */
  private durationOf(toast: ToastData): number | null {
    return toast.durationMs === undefined ? DEFAULT_DURATION_MS : toast.durationMs;
  }

  private panelClasses(toast: ToastData): string[] {
    return toast.width === 'fixed' ? ['app-toast', 'app-toast--fixed'] : ['app-toast'];
  }
```

Also widen the class docblock's first sentence, which currently promises only "a message with an optional single action":

```ts
/**
 * The app's one toast: a message -- or a feature's own component -- with an
 * optional single action, replacing whatever is currently visible. Rendered
 * through the CDK overlay -- never `position: fixed` -- because a transformed
 * ancestor (the open drawer, a dialog) would re-anchor a fixed child to the
 * wrong containing block (#85, #100). `hasBackdrop: false`, `autoFocus: false`,
 * `restoreFocus: false`: a toast must never steal focus from whatever the user
 * is doing.
 */
```

- [ ] **Step 6: Add the fixed-width pane rule**

In `frontend/src/styles.scss`, immediately after the existing `.cdk-overlay-pane.app-toast` rule at line 29, add:

```scss
/* A surface whose content changes mid-life -- the For-You run pill, which turns
   into the ready message when the run ends -- holds one box width for its whole
   life. The pane is content-sized and centre-anchored, so without this the pill
   would shrink from both edges at the moment of completion (#398). 22rem holds
   the longest progress line on one line in both languages; the viewport cap
   still wins on a phone. */
.cdk-overlay-pane.app-toast--fixed {
  width: min(100% - 2 * var(--space-4), 22rem);
}
```

- [ ] **Step 7: Run the toast tests to verify they pass**

```bash
npx jest src/app/shared/toast/toast.service.spec.ts
```

Expected: PASS, all tests including the pre-existing ones (the default 6000 ms dismiss, the replace-in-place behaviour, the `role=status` region and the `--space-5` offset).

- [ ] **Step 8: Commit**

```bash
git add frontend/src/app/shared/toast frontend/src/styles.scss
git commit -m "feat(#398): let the toast host a persistent component at a fixed width"
```

---

### Task 2: The progress component becomes pill content

**Files:**
- Modify: `frontend/src/app/reader/for-you-progress/for-you-progress.component.ts`
- Modify: `frontend/src/app/reader/for-you-progress/for-you-progress.component.html`
- Modify: `frontend/src/app/reader/for-you-progress/for-you-progress.component.scss`
- Test: `frontend/src/app/reader/for-you-progress/for-you-progress.component.spec.ts`

**Interfaces:**
- Consumes: nothing at compile time. The component is passed as `ContentToast['content']` in Task 3.
- Produces: `ForYouProgressComponent`, unchanged class name and selector `app-for-you-progress`, exported from `reader/for-you-progress/for-you-progress.component.ts`. It takes no inputs and injects `RecommendationsService` itself.

Why the accessibility change: the toast shell already declares `role="status" aria-live="polite"`. Nesting a second live region inside it duplicates the announcement. Worse, `formatEta` returns whole seconds below one minute, so the ETA text changes on every ticker bump — inside a live region that is an announcement on every tick. Hiding the ETA from assistive technology leaves the live region announcing only "Ranking your feeds — X of Y", which changes once per batch. That is what issue #398 asks for.

- [ ] **Step 1: Write the failing tests**

Add these two tests to `frontend/src/app/reader/for-you-progress/for-you-progress.component.spec.ts`, before the closing `});`:

```ts
  it('hides the ETA from assistive technology, so a per-tick estimate is not announced', () => {
    etaState.set('eta');
    etaSeconds.set(30);
    const el = build().nativeElement as HTMLElement;
    expect(el.querySelector('.eta')!.getAttribute('aria-hidden')).toBe('true');
  });

  it('declares no live region of its own — the toast shell it renders into owns that', () => {
    const el = build().nativeElement as HTMLElement;
    const line = el.querySelector('.for-you-progress')!;
    expect(line.getAttribute('role')).toBeNull();
    expect(line.getAttribute('aria-live')).toBeNull();
  });
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
npx jest src/app/reader/for-you-progress
```

Expected: FAIL — the `.eta` span has no `aria-hidden`, and the `<p>` still carries `role="status"` and `aria-live="polite"`.

- [ ] **Step 3: Move the live region off the component**

Replace the whole of `frontend/src/app/reader/for-you-progress/for-you-progress.component.html` with:

```html
@if (recs.running()) {
  <p class="for-you-progress">
    <span>{{ 'reader.forYouProgress' | transloco: count() }}</span>
    @if (eta(); as phrase) {
      <!-- Hidden from the toast's live region on purpose: below a minute the
           estimate changes on every ticker bump, and announcing it each time
           would bury the count that actually moves once per batch (#398). -->
      <span class="eta" aria-hidden="true"> · {{ phrase.key | transloco: phrase.params }}</span>
    }
  </p>
  <div
    class="track"
    role="progressbar"
    aria-valuemin="0"
    aria-valuemax="100"
    [attr.aria-valuenow]="percent()"
  >
    <span [style.width.%]="percent()"></span>
  </div>
}
```

- [ ] **Step 4: Restyle for the pill**

Replace the first two rules of `frontend/src/app/reader/for-you-progress/for-you-progress.component.scss` (the `:host` and `.for-you-progress` blocks and the comment above them) with:

```scss
/* The count+ETA line and its bar stack full-width inside the app-wide pill,
   which owns its own width (`.app-toast--fixed` in `styles.scss`). The line is
   allowed to wrap: 22rem holds the longest translation on one line, but the
   pane's viewport cap is narrower than that on a phone, and wrapping is the
   only honest answer there. */
:host {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: var(--space-1);
}

.for-you-progress {
  margin: 0;
  color: var(--text-muted);
  font-size: var(--fs-xs);
}
```

Leave the `.track` and `.track span` rules exactly as they are.

- [ ] **Step 5: Update the class docblock**

In `frontend/src/app/reader/for-you-progress/for-you-progress.component.ts`, replace the class docblock with:

```ts
/**
 * The For-You run's progress surface: the "Ranking your feeds — X of Y" count
 * with the live ETA on the same line, and a determinate bar beneath it. It is
 * the content of the app-wide toast pill (`RecommendationsService` raises it),
 * so the run stays visible on every route rather than only in the reader. It
 * reads the run service directly and renders nothing unless a run is in flight.
 */
```

- [ ] **Step 6: Run the tests to verify they pass**

```bash
npx jest src/app/reader/for-you-progress
```

Expected: PASS, including the five pre-existing tests.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/reader/for-you-progress
git commit -m "refactor(#398): make the progress block pill content, not a header child"
```

---

### Task 3: `RecommendationsService` owns the pill for the whole run

**Files:**
- Modify: `frontend/src/app/reader/recommendations.service.ts`
- Test: `frontend/src/app/reader/recommendations.service.spec.ts`

**Interfaces:**
- Consumes: `ToastData`'s `content`, `durationMs` and `width` fields from Task 1; `ForYouProgressComponent` from Task 2.
- Produces: no new public API. Private `markRunning(): void` is the single "a run is now live" transition. All four `toast.show()` calls carry `width: 'fixed'`. `finish()` calls `toast.dismiss()`.

Note on the import cycle: `recommendations.service.ts` will import `ForYouProgressComponent`, which imports `RecommendationsService`. This is safe — the component references the service only inside a field initializer that runs at construction time, long after both modules have evaluated — and ESLint here has no `import/no-cycle` rule. Step 6 verifies it at runtime rather than assuming it.

- [ ] **Step 1: Give the toast double a `dismiss` and write the failing tests**

In `frontend/src/app/reader/recommendations.service.spec.ts`:

Add the import, after the existing `RecommendationRunReport` import:

```ts
import { ForYouProgressComponent } from './for-you-progress/for-you-progress.component';
```

Change the mock's type declaration (currently `let toast: { show: jest.Mock };`) and its construction in `beforeEach` (currently `toast = { show: jest.fn() };`):

```ts
  let toast: { show: jest.Mock; dismiss: jest.Mock };
```

```ts
    toast = { show: jest.fn(), dismiss: jest.fn() };
```

Add these three tests before the closing `});` of the describe block:

```ts
  /** The pill's exact shape, asserted in one place so the three tests below
   *  read as intent rather than as a repeated object literal. */
  const PILL = {
    content: ForYouProgressComponent,
    durationMs: null,
    width: 'fixed',
  };

  it('raises the persistent pill the moment a run starts, before any report arrives', () => {
    svc.start();

    expect(toast.show).toHaveBeenCalledWith(PILL);

    ctrl
      .expectOne('https://api.test/api/recommendations/runs')
      .flush(report({ status: 'completed', batchesTotal: 1, batchesDone: 1 }));
  });

  it('raises the pill for a run resumed from an earlier session', () => {
    svc.resume();
    ctrl
      .expectOne('https://api.test/api/recommendations/runs/current')
      .flush(report({ status: 'running', batchesTotal: 2, batchesDone: 1 }));

    expect(toast.show).toHaveBeenCalledWith(PILL);

    ctrl
      .expectOne('https://api.test/api/recommendations/runs/tick')
      .flush(report({ status: 'completed', batchesTotal: 2, batchesDone: 2 }));
  });

  it('takes the pill down on a cancelled run, which raises no toast of its own', () => {
    svc.start();
    ctrl
      .expectOne('https://api.test/api/recommendations/runs')
      .flush(report({ status: 'running', batchesTotal: 2, batchesDone: 1 }));

    ctrl
      .expectOne('https://api.test/api/recommendations/runs/tick')
      .flush(report({ status: 'cancelled', batchesTotal: 2, batchesDone: 1 }));

    expect(svc.running()).toBe(false);
    expect(toast.dismiss).toHaveBeenCalled();
    expect(toast.show).toHaveBeenCalledTimes(1); // the pill, and nothing after it
  });
```

- [ ] **Step 2: Fix the existing assertions the pill invalidates**

Three existing tests count `toast.show` calls or read `mock.calls[0]`. The pill is now call 1, so each must move to the last call. Make exactly these four edits in the same file:

In `it('starts a run, ticks until completed, and shows the ready toast', …)`, replace:

```ts
    expect(toast.show).toHaveBeenCalledTimes(1);
    expect(toast.show).toHaveBeenCalledWith(
      expect.objectContaining({ message: 'reader.forYouReady' }),
    );
```

with:

```ts
    expect(toast.show).toHaveBeenCalledTimes(2); // the pill, then the ready message
    expect(toast.show).toHaveBeenLastCalledWith(
      expect.objectContaining({ message: 'reader.forYouReady', width: 'fixed' }),
    );
```

In `it('the ready toast action navigates to the for-you view', …)`, replace:

```ts
    const call = toast.show.mock.calls[0][0] as { action?: () => void };
```

with:

```ts
    const call = toast.show.mock.calls.at(-1)![0] as { action?: () => void };
```

In `it('records a failed run, shows the failure toast, and issues no further requests', …)`, replace:

```ts
    expect(toast.show).toHaveBeenCalledTimes(1);
    expect(toast.show).toHaveBeenCalledWith(
      expect.objectContaining({ message: 'reader.forYouFailed' }),
    );
```

with:

```ts
    expect(toast.show).toHaveBeenCalledTimes(2); // the pill, then the failure message
    expect(toast.show).toHaveBeenLastCalledWith(
      expect.objectContaining({ message: 'reader.forYouFailed', width: 'fixed' }),
    );
```

Leave the two `expect(toast.show).not.toHaveBeenCalled()` assertions alone — they cover a resume that finds a finished run and a resume whose fetch fails, and neither reaches `markRunning()`.

- [ ] **Step 3: Run the tests to verify they fail**

```bash
npx jest src/app/reader/recommendations.service.spec.ts
```

Expected: FAIL — no pill is shown on start or resume, `toast.dismiss` is never called, and the reworked count assertions read 1 where they expect 2.

- [ ] **Step 4: Raise the pill from a single "run is live" transition**

In `frontend/src/app/reader/recommendations.service.ts`, add the import after the existing `./reader-api` import:

```ts
import { ForYouProgressComponent } from './for-you-progress/for-you-progress.component';
```

Replace the body of `beginRun()` so it delegates the shared transition:

```ts
  /** Shared entry for both start and resume: guard against a double-run, reset
   *  the per-run signals, then drive the returned run report into the poll
   *  loop. The two differ only in which endpoint opens the run. */
  private beginRun(source: Observable<RecommendationRunReport>): void {
    if (this.running()) return;
    this.markRunning();
    this.stopping.set(false);
    this.report.set(null);
    source.subscribe({
      next: (r) => this.onReport(r),
      error: (e: HttpErrorResponse) => this.stopWithHttpError(e),
    });
  }

  /** The one place a run becomes live, whether it was started here or found
   *  already in flight by `resume()`. The pill goes up here rather than at
   *  each call site, which is what makes a resumed run visible too -- and it
   *  is the app-wide surface, so the run stays visible after the user leaves
   *  the reader (#398). */
  private markRunning(): void {
    this.running.set(true);
    this.startTicker();
    this.failure.set(null);
    this.toast.show({
      content: ForYouProgressComponent,
      durationMs: null,
      width: 'fixed',
    });
  }
```

In `resume()`, replace these four lines of its `next` handler:

```ts
        if (r.status !== 'pending' && r.status !== 'running') return;
        this.running.set(true);
        this.startTicker();
        this.failure.set(null);
        this.step(NO_ATTEMPTS);
```

with:

```ts
        if (r.status !== 'pending' && r.status !== 'running') return;
        this.markRunning();
        this.step(NO_ATTEMPTS);
```

- [ ] **Step 5: Take the pill down from the single exit, and pin every width**

Still in `frontend/src/app/reader/recommendations.service.ts`:

Replace `finish()`:

```ts
  private finish(): void {
    this.running.set(false);
    this.stopping.set(false);
    this.rateLimited.set(false);
    this.stopTicker();
    // The single exit from every end state, which is why the pill comes down
    // here: `cancelled` and `none` raise no toast of their own and would
    // otherwise leave it up forever. The completed and failed paths call this
    // first and then put their own message in the same slot.
    this.toast.dismiss();
  }
```

In `onReport()`'s `completed` branch, add the width:

```ts
        this.toast.show({
          message: this.i18n.translate('reader.forYouReady'),
          actionLabel: this.i18n.translate('reader.forYouView'),
          action: () => this.navigateToForYou(),
          width: 'fixed',
        });
```

In `onReport()`'s `failed` branch:

```ts
        this.toast.show({ message: this.i18n.translate('reader.forYouFailed'), width: 'fixed' });
```

In `stopWithHttpError()`, replace both the comment and the call — the comment describes a reader-only surface that no longer exists:

```ts
    // The run's only surface is the app-wide pill, and `finish()` has just
    // taken it down. A request that fails outright — the start POST, or the
    // poll loop giving up after its transport/rate-limit ceiling — would leave
    // nothing behind without this (#325).
    this.toast.show({
      message: this.i18n.translate('reader.forYouUnreachable'),
      width: 'fixed',
    });
```

- [ ] **Step 6: Run the tests to verify they pass**

```bash
npx jest src/app/reader/recommendations.service.spec.ts
```

Expected: PASS, all tests. A failure mentioning `Cannot access 'RecommendationsService' before initialization` would mean the import cycle bites at module-evaluation time; if that happens, stop and report it rather than working around it — the fix is a design change, not a shim.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/reader/recommendations.service.ts frontend/src/app/reader/recommendations.service.spec.ts
git commit -m "feat(#398): give the run pill the whole life of a For-You run"
```

---

### Task 4: Take the progress block out of the reader's list header

**Files:**
- Modify: `frontend/src/app/reader/reader-shell.component.html:211-215`
- Modify: `frontend/src/app/reader/reader-shell.component.ts:58,72`
- Modify: `frontend/src/app/reader/reader-shell.component.scss:167-187`
- Test: `frontend/src/app/reader/reader-shell.component.spec.ts:1178,1264-1265`

**Interfaces:**
- Consumes: nothing. This task only removes a placement.
- Produces: `.list-header` no longer renders `.for-you-progress` or `.track`. The Stop button (`.for-you-run`) is untouched, as issue #398 requires.

- [ ] **Step 1: Rewrite the two header assertions in the shell spec**

In `frontend/src/app/reader/reader-shell.component.spec.ts`, in `it('replaces the run button with a stop button while a run is in flight', …)`, replace:

```ts
    const progress = f.nativeElement.querySelector('.for-you-progress') as HTMLElement;
    expect(progress.textContent).toContain('1 of 3');
```

with:

```ts
    // The count, the ETA and the bar live in the app-wide pill now, so a live
    // run leaves nothing but the Stop button behind in the header (#398).
    expect(f.nativeElement.querySelector('.for-you-progress')).toBeNull();
```

In `it('shows the run button in the list header and starts a run only after the user confirms', …)`, replace the comment above the existing null check so it no longer claims the block belongs to a live run:

```ts
    // The header never carries the progress caption: it is the pill's, on
    // every route, running or not (#398).
    expect(f.nativeElement.querySelector('.for-you-progress')).toBeNull();
```

- [ ] **Step 2: Run the spec to verify it fails**

```bash
npx jest src/app/reader/reader-shell.component.spec.ts -t "replaces the run button with a stop button"
```

Expected: FAIL — the header still renders `.for-you-progress`, so the new `toBeNull()` assertion does not hold.

- [ ] **Step 3: Remove the block from the header template**

In `frontend/src/app/reader/reader-shell.component.html`, delete these five lines (the comment and the element, lines 211-215):

```html
        <!-- The run's live count, ETA and progress bar, back in the header under
             the button. It is desktop-only and the button is anchored to the
             header's top edge, so this block grows downward without shifting the
             button (#325, #336). -->
        <app-for-you-progress />
```

The `</app-button>` above it and the `} @else {` below it stay exactly as they are.

- [ ] **Step 4: Drop the now-unused component import**

In `frontend/src/app/reader/reader-shell.component.ts`, delete line 58:

```ts
import { ForYouProgressComponent } from './for-you-progress/for-you-progress.component';
```

and delete the `ForYouProgressComponent,` entry from the `imports` array (line 72).

- [ ] **Step 5: Correct the two stale comments in the shell stylesheet**

In `frontend/src/app/reader/reader-shell.component.scss`, replace the comment above `.for-you-action` (it describes a two-item stack that no longer exists):

```scss
/* The header's For-You slot. It holds one control — the run button, or the Stop
   button while a run is in flight — right-aligned with the header's other
   tools. The run's count, ETA and bar are not here: they are in the app-wide
   pill, so they survive the user leaving the reader (#398). */
```

And replace the comment above the `@media (width <= bp.$bp-sm)` block, whose second sentence points at a media query that `for-you-progress.component.scss` has never had:

```scss
/* Below the small breakpoint the button drops its label and stands on the icon
   alone — the same rule the header's other tools follow, with the aria-label
   keeping it named. */
```

Leave both rule bodies unchanged.

- [ ] **Step 6: Run the shell spec to verify it passes**

```bash
npx jest src/app/reader/reader-shell.component.spec.ts
```

Expected: PASS, the whole file.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/reader/reader-shell.component.html frontend/src/app/reader/reader-shell.component.ts frontend/src/app/reader/reader-shell.component.scss frontend/src/app/reader/reader-shell.component.spec.ts
git commit -m "refactor(#398): take the For-You progress block out of the list header"
```

---

### Task 5: Record the toast's new modes and pass the gate

**Files:**
- Modify: `docs/design-language.md:524-560` (the `<app-toast>` entry)

**Interfaces:**
- Consumes: the final shape of `ToastData` from Task 1.
- Produces: documentation only.

- [ ] **Step 1: Rewrite the `<app-toast>` catalog entry**

In `docs/design-language.md`, replace the `### <app-toast> (via the ToastService)` section — everything from that heading down to the `---` that precedes `### <app-settings-card>` — with:

````markdown
### `<app-toast>` (via the `ToastService`)

The app's one toast: a surface pinned to the bottom of the viewport, with an
optional single action, auto-dismissing after `durationMs` (default 6000ms).
Rendered through the CDK overlay, never `position: fixed` — a transformed
ancestor (an open drawer, a dialog) would re-anchor a fixed child to the wrong
containing block (#85, #100). `hasBackdrop: false`, `autoFocus: false`,
`restoreFocus: false`: a toast must never steal focus from whatever the user
is doing, unlike every other surface in this catalog.

```ts
private readonly toast = inject(ToastService);

this.toast.show({
  message: this.transloco.translate('reader.recommendations.applied'),
  actionLabel: this.transloco.translate('reader.recommendations.undo'),
  action: () => this.undo(),
});
```

A toast carries **either** a `message` or a `content` component, never both.
`ToastData` is a union of the two, so the compiler rejects a toast that tries
to be both at once.

| `ToastData` field | Type | Default |
|---|---|---|
| `message` | `string` — the message mode | — |
| `content` | `Type<unknown>` — the content mode | — |
| `actionLabel` | `string` | `undefined` — omits the button |
| `action` | `() => void` | `undefined` — runs before the toast closes |
| `durationMs` | `number \| null` | `6000`; `null` never auto-dismisses |
| `width` | `'fit' \| 'fixed'` | `'fit'` — the pane sizes to its content |

`message` and `actionLabel` are already-translated strings — the component
lives in `shared/` and must not know any feature's i18n keys. `content` is a
component built through `NgComponentOutlet`, so it injects and reads its own
feature's services and resolves its own translations; nothing is threaded
through `ToastData`. `show()` replaces whatever toast is already visible,
clearing its timer; there is no queue. There is only one toast, so
`ToastService` is injected directly rather than opened against a template
reference.

**The persistent modes.** `durationMs: null` and `width: 'fixed'` exist for one
caller, and adding a second is a design decision, not a convenience: the
For-You run pill (#398). A recommendation run takes minutes and the user
navigates away from it, so its progress readout is `ForYouProgressComponent`
hosted as `content` with no dismiss timer, and `RecommendationsService` puts the
ready message in the same slot when the run ends. `width: 'fixed'` pins the pane
to `22rem` across that handover — the pane is otherwise content-sized and
centre-anchored, so the box would shrink from both edges at the moment of
completion. Whoever raises a persistent toast owns taking it down: the run's
`finish()` is the single exit from every end state and calls `dismiss()` there.

**Accessibility.** The toast shell owns the `role="status" aria-live="polite"`
region. A `content` component must not declare a second one inside it, and must
hide any value that changes faster than the user can read it — the run pill's
ETA is `aria-hidden`, so only the batch count is announced.

**Not for:** a failure that blocks the surface it reports on — use
`<app-error-banner>` instead, which stays in the document until its own
action or a reload clears it. The message mode is for a transient, dismissible
confirmation of something that already happened (a background refresh
finished, a bulk action applied) that the user does not have to act on.
````

- [ ] **Step 2: Run the full gate**

```bash
npm run check
```

Expected: PASS — ESLint, Prettier, Stylelint and the whole Jest suite.

If Prettier reports formatting differences, run `npx prettier --write` on the reported files and re-run.

- [ ] **Step 3: Verify the pill in the running app**

Bring the Docker stack up from the repository root and open the reader.

```bash
docker compose up -d
```

Check by hand, because none of this is reachable from jsdom:

1. Start a For-You run. The pill appears at the bottom of the viewport with the count, the ETA and a filling bar.
2. Navigate to `/settings`. The pill is still there and still counting.
3. Confirm the Stop button is still in the reader's list header and still stops the run.
4. Let a run finish. The pill becomes "Recommendations ready" with a **View** action, in the same place and at the same width.
5. Narrow the window to a phone width and start a run. The German progress line wraps rather than overflowing the pill — switch the language in settings to check it.
6. Press ✕ during a run. The pill goes away, the run keeps going, and the ready message still arrives.

- [ ] **Step 4: Commit**

```bash
git add docs/design-language.md
git commit -m "docs(#398): record the toast's persistent and content modes"
```

- [ ] **Step 5: Open the follow-up issue the spec defers**

`RecommendationsService.resume()` is called only from `reader-shell.component.ts`, so a page reload on `/settings` or `/admin` during a run shows no pill until the user opens the reader. The spec puts this out of scope deliberately. Open an issue for it titled "Resume a For-You run's pill on any route, not only in the reader", describing the fix as moving the `resume()` call to `App` gated on `TokenStore.isAuthenticated()`.

---

## Self-review notes

Checked against the spec:

- Decision recorded (toast second mode, not a sibling surface) → Task 5's documentation rewrite, plus the spec document itself.
- Union type, persistent mode, fixed width → Task 1.
- `NgComponentOutlet` rendering, `.msg` min-width → Task 1.
- The `??` trap on an explicit `null` → Task 1, Step 5, `durationOf()`.
- 22rem pane rule → Task 1, Step 6.
- Progress component restyle, no `nowrap` → Task 2.
- Nested live region and the ETA announcement storm → Task 2.
- `markRunning()` covering resume → Task 3. **Spec correction:** the spec said `beginRun()` would be created; it already exists, and `resume()` bypasses it. `markRunning()` is the shared piece, extracted from both.
- `finish()` dismissing, all four `show()` calls pinned → Task 3.
- `stopWithHttpError()` comment reword → Task 3, Step 5.
- Header removal and both stale comments → Task 4.
- Design-language amendment → Task 5.
- Out-of-scope `resume()` gap → Task 5, Step 5, as a follow-up issue.
