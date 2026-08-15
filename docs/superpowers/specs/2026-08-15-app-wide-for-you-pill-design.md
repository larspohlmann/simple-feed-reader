# App-wide For-You progress pill

Design for [#398](https://github.com/larspohlmann/simple-feed-reader/issues/398).

## Problem

A For-You run takes around 200 s on the production install. It is exactly the
kind of work a user starts and then navigates away from. Today the two halves of
its lifetime live in different places:

- **In flight** — `ForYouProgressComponent` renders the count, the ETA and the
  determinate bar. It is placed once, in the reader shell's sticky list header
  (`reader-shell.component.html:215`). Leave the reader and the run is invisible.
- **On completion** — `RecommendationsService.onReport()` raises the shared
  toast with `reader.forYouReady` and a **View** action. The toast is a CDK
  overlay opened by a root service, so it reaches the user on any route.

The end of the run follows the user. The middle of it does not.

## Decision: the pill is a second mode of the existing toast

The issue asks for this choice to be recorded. It is the toast, not a sibling
surface.

The deciding fact is that `RecommendationsService` is the **only** caller of
`ToastService.show()` in the whole frontend. Every toast that exists today
already belongs to the For-You run. A sibling surface would put a second
overlay on the same bottom-centre anchor, and the first other feature to raise a
toast would collide with it. One overlay slot with replace-in-place semantics —
which `show()` already has — gives the run a single continuous surface for free.

The cost is that `shared/toast` grows a second mode, and that its
`docs/design-language.md` entry no longer describes only transient
confirmations. That entry is amended as part of this work.

Rejected: a `position: fixed` pill in the app shell. Fixed positioning is banned
here — a transformed ancestor re-anchors it to the wrong containing block
(#85, #100) — and it cannot carry the ready message in the same surface.

## The toast's two new modes

`ToastData` becomes a union, so a toast carries either a message or a component,
never both:

```ts
interface ToastBase {
  actionLabel?: string;
  action?: () => void;
  /** `null` does not auto-dismiss. Use it for a surface that must live as long
   *  as the work it reports on. */
  durationMs?: number | null;
  /** `fixed` holds one box width across successive toasts, so a surface that
   *  changes content mid-life does not resize under the user. Default `fit`. */
  width?: 'fit' | 'fixed';
}

export type ToastData =
  | (ToastBase & { message: string })
  | (ToastBase & { content: Type<unknown> });
```

- `ToastService.show()` starts no dismiss timer when `durationMs` is `null`.
  Note the trap: the current line is `toast.durationMs ?? DEFAULT_DURATION_MS`,
  and `??` treats `null` as nullish, so an explicit `null` would silently become
  6000 ms. The check must be `=== null`, not a coalesce.
- `ToastService.show()` adds an `app-toast--fixed` panel class when `width` is
  `fixed`.
- `ToastComponent` renders `content` through `NgComponentOutlet` in place of the
  message span. It keeps its own shell, its placement, its action slot and its
  ✕ button. It learns no feature i18n key.

`styles.scss` gains, next to the existing pane rule:

```scss
.cdk-overlay-pane.app-toast--fixed {
  width: min(100% - 2 * var(--space-4), 22rem);
}
```

## Why the width is pinned

The pane is content-sized and centre-anchored. Measured against the real
strings, that gives three defects the move would otherwise introduce:

1. The pill loses roughly a third of its width at completion, and it shrinks
   from both edges at once.
2. On a 375 px phone the German progress line needs about 337 px inside a 343 px
   cap. With `white-space: nowrap` it overflows the pill.
3. Letting it wrap trades the width jump for a height jump.

22 rem (352 px) holds every progress line on one line on desktop, and is wide
enough that the ready message does not read as an empty bar. The progress line
also loses `white-space: nowrap`, so the narrowest phones wrap rather than
overflow.

The box still loses one line of height at completion, when the bar goes away.
That is accepted: it is a single step at a moment the user is meant to notice.

Rejected: keeping one component mounted across both states so the box could
animate between them. The ready state would then need its own dismiss timer
inside the feature, duplicating the timer `ToastService` already owns. The fixed
width reaches the same visual result with much less machinery.

## The run's lifecycle in `RecommendationsService`

- A private `beginRun()` collects what `start()`, `resume()` and `resumeRun()`
  repeat today: set `running`, start the ticker, clear the failure, and show the
  sticky pill —
  `{ content: ForYouProgressComponent, durationMs: null, width: 'fixed' }`.
- `finish()` calls `toast.dismiss()`. It is the single exit from every end
  state, so `cancelled` and `none` leave no pill behind.
- The `completed`, `failed` and unreachable paths already call `finish()` and
  then `show()`. Each of those `show()` calls gains `width: 'fixed'`, so the box
  holds still across the handover.
- `stopWithHttpError()` carries a comment that reads "The run's only in-reader
  surface is the progress hairline, which vanishes the moment the run ends"
  (#325). The surface is no longer in-reader, so the comment is reworded to
  describe the pill.
- The ✕ closes the pill. The run continues, and the pill does not come back for
  that run. The completion message still arrives, because it is a fresh `show()`.
  The Stop button remains the way to end a run.

## `ForYouProgressComponent`

The component does not move directory. It already reads `RecommendationsService`
directly, so becoming the toast's `content` needs no new plumbing.

- Its `@if (recs.running())` guard stays, as a defence of the boundary.
- Its SCSS changes from a right-aligned stack — which existed to sit under the
  Stop button in the header's action column — to a full-width one.
- The progress line drops `white-space: nowrap`.

## Accessibility

`ToastComponent`'s shell already carries `role="status" aria-live="polite"`, and
the progress `<p>` carries the same pair. Nested live regions duplicate the
announcement, so the inner pair goes. The track keeps its `role="progressbar"`
and `aria-valuenow`.

The ETA changes every second while the estimate is under a minute
(`formatEta` returns whole seconds below 60). Inside the toast's live region
that becomes an announcement on every tick. The ETA span therefore gets
`aria-hidden="true"`. The live region then announces only
"Ranking your feeds — X of Y", which changes once per batch. This is what the
issue asks for: the announcement behaviour is kept, and the per-poll storm is
not introduced.

## The reader header

- Remove `<app-for-you-progress />` from `reader-shell.component.html:215`,
  together with the comment above it about the block growing downward under the
  button.
- Correct `reader-shell.component.scss:180-182`. It claims the progress block
  hides itself at the small breakpoint and points at
  `for-you-progress.component.scss`. That file has no media query, so the
  comment is already untrue.
- The Stop button does not change.

## Tests

- `toast.service.spec.ts` — a `durationMs: null` toast survives past the default
  timer; a `content` toast renders the component; ✕ closes a sticky toast; a
  `width: 'fixed'` toast carries the panel class.
- `for-you-progress.component.spec.ts` — the existing count, ETA and percent
  assertions hold; the ETA is hidden from assistive technology; the `<p>` no
  longer declares its own live region.
- `recommendations.service.spec.ts` — a start opens a sticky pill; a completion
  replaces it with the ready message and its View action; cancel, failure and
  `none` leave no pill behind.
- `reader-shell.component.spec.ts` — the list header holds no progress block;
  the Stop button is unchanged.

`npm run check` is the gate.

## Documentation

`docs/design-language.md` §`<app-toast>` records the persistent mode, the
content mode and the fixed width, and names the For-You pill as the one
persistent user. Its "Not for:" paragraph is amended, because the toast is no
longer only for transient confirmations.

## Out of scope

`RecommendationsService.resume()` is called only from
`reader-shell.component.ts:475`. A page reload on `/settings` or `/admin` during
a run therefore shows no pill until the user opens the reader. Moving that call
to the app shell, gated on `TokenStore.isAuthenticated()`, would close the gap.
It is deliberately left out of this branch and gets its own issue.

## Acceptance criteria

- [ ] A running For-You run shows its progress in the app-wide pill, on any route
- [ ] The same pill carries the ready message and its View action when the run
      completes
- [ ] The pill does not auto-dismiss while a run is active
- [ ] The Stop button is unchanged and stays in the list header
- [ ] `npm run check` passes; the progress and toast specs cover the new lifetime
