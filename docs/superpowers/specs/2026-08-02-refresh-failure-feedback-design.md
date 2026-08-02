# Tell the user when a refresh accomplished nothing (#119)

## Problem

`RefreshService` can end a refresh having fetched nothing and say nothing. There
are three paths.

1. **Busy exhaustion.** After `MAX_BUSY_RETRIES` the service calls `finish()`
   and sets no error. The user watches the spinner for about 7.5 seconds and
   then sees it stop. Retrying longer is not the fix: a CLI sweep holds the lock
   for up to its full budget.
2. **An aborted sweep.** `aborted` and `completed` take the same branch. An
   `aborted` sweep stopped mid-way and left feeds still due, but it presents as
   a clean run.
3. **HTTP failures reach only one screen.** The signal is set correctly, and the
   shell does render it — but the whole banner is gated on `sweeping()`, the
   post-onboarding sweep. A failed refresh from the sidebar button, a scoped
   refresh or add-feed still shows nothing. (The issue reports the signal as
   never rendered; the sweep banner has landed since it was written.)

## Decision

### A union, not a synthesized `Problem`

```ts
export type RefreshFailure =
  | { kind: 'busy' }
  | { kind: 'aborted' }
  | { kind: 'http'; problem: Problem };
```

Busy and aborted are not HTTP failures. A `Problem` demands a `type`, a `title`
and a `status`, and all three would have to be invented for a case where no
request failed. The union states each cause once, and the template switches on
one field.

The signal is renamed `error` to `failure`, because the value it carries is no
longer a `Problem`.

### One banner, three messages

The under-header seam already holds the counted sweep banner and its Retry
button. The failure message goes in the same strip, ungated:

- `busy` — "Another refresh is already running. Try again in a moment."
- `aborted` — "The refresh stopped early. Some feeds are still due."
- `http` — the existing "Some feeds could not be fetched."

A 429 from the refresh limiter shows the generic HTTP message. Splitting it out
was considered and dropped: it is one more branch for advice ("wait") that the
Retry button already invites the user to discover.

## Scope

### `frontend/src/app/reader/refresh.service.ts`

- Export `RefreshFailure`.
- `failure = signal<RefreshFailure | null>(null)` replaces `error`.
- Busy exhaustion records `{ kind: 'busy' }` before finishing.
- The `completed | aborted` branch splits; `aborted` records `{ kind: 'aborted' }`.
- The HTTP path records `{ kind: 'http', problem: parseProblem(e) }`.

### `frontend/src/app/reader/refresh-message.ts` (new)

A pure function from a `RefreshFailure` to a translation key. Its own file and
its own spec, so the wording rule is testable without mounting the shell.

### `frontend/src/app/reader/reader-shell.component.{ts,html}`

- The counted progress banner stays gated on `sweeping()` and now also requires
  no failure.
- A failure banner renders whenever `failure()` is set, from any refresh, with
  the existing Retry button.
- The progress banner keeps `role="status"`. The failure banner takes
  `role="alert"`, matching the shared error-banner convention in
  `docs/design-language.md`.
- The sweep-window effect keeps its logic under the new signal name.

### `frontend/public/i18n/{en,de}.json`

`reader.refreshBusy` and `reader.refreshAborted`. The dictionary parity spec
enforces both languages.

## What does not change

- **The backend.** `/api/refresh` already returns the status the client needs,
  and problem+json already covers the HTTP cases.
- **The visual style.** The same `.fetch-banner` strip in the same seam.
- **The counted banner's scope.** It still belongs to the post-onboarding sweep
  alone. A later successful refresh still renders nothing.

## Verification

- `npm run check` in `frontend/`.
- Service specs: busy exhaustion after the full retry budget records `busy`; an
  `aborted` report records `aborted`; an HTTP error records the problem.
- Map spec: the three cases.
- Shell specs: a failed refresh outside the sweep renders the banner and its
  Retry; a later successful refresh renders nothing.
