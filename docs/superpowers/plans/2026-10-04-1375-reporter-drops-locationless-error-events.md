# #1375 Reporter drops error events that carry no error — implementation plan

**Goal:** stop `Script error.` and `ResizeObserver loop …` reports from swamping the
Frontend errors panel (53 of 55 reports, 2026-10-02 to 2026-10-04), without matching
on either message text.

**Finding that shapes the design:** both arrive as a window `ErrorEvent` whose `error`
is `null`. Angular's `provideBrowserGlobalErrorListeners` then synthesises
`new Error(e.message, { cause: e })` inside its own listener, so the stack reported is
the listener's construction site (`r@…/chunk-*.js:4:15845` → zone `runTask`), never the
origin. The bundle frame #1038 counted as "ours" is that listener frame. The event is
the only evidence of where the error came from: a masked cross-origin error has an empty
`filename` and `0:0`; a ResizeObserver loop notice has no location either.

**Rule (general, no message or browser list):** an error whose `cause` is an `ErrorEvent`
with no `error` is judged by the event's own location, not the synthesised stack. It is
reported only when `filename:lineno:colno` is an application-bundle frame (the existing
`frameIsApplicationCode` test). Otherwise it is dropped as originating outside the
application.

This replaces the issue's proposal (frame fingerprinting of the listener, plus a
ResizeObserver message match) with one rule that reads the evidence directly.

## Task 1 — the rule (TDD, `client-error-reporter.spec.ts`)

Add a helper that builds the error Angular builds:
`new Error(message, { cause: new ErrorEvent('error', { message, filename, lineno, colno }) })`
with the production listener stack.

Tests:
- drops a masked `Script error.` event (no filename) whose synthesised stack carries the
  listener's bundle frame. This replaces `reports a masked cross-origin error that still
  carries a same-origin bundle frame`, whose fixture lacked the `cause` that tells the cases apart.
- drops a `ResizeObserver loop completed with undelivered notifications.` event (no location).
- reports an event with no error whose filename is a same-origin bundle at a location.
- drops an event with no error whose filename is a foreign script.
- an `Error` with an `ErrorEvent` cause that *does* carry an `error` keeps today's
  stack-based judgement (reported when its stack points at the bundle).
- a stackless string `'Script error.'` stays reportable (existing test, unchanged).

Implementation in `ClientErrorReporter.originatesOutsideApplication(error, item)`: if
`errorEventWithoutError(error)` returns the event, return
`!this.frameIsApplicationCode(`${filename}:${lineno}:${colno}`)`; otherwise today's logic.
Update the docblock.

## Task 2 — gates

`docker compose exec -T frontend npm run check` and `npm run build` in the container;
commit `fix(#1375): …`, PR to `develop` with `Closes #1375`, then merge when green.
