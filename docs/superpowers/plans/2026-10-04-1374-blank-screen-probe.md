# #1374 Blank-screen probe — implementation plan

**Goal:** report, once per page, when the reader shows nothing visible after load or
after a resume, with enough state to tell apart the hypotheses for the grey page
after an iOS browser relaunch. A diagnostic, not a fix.

**Architecture:** an Angular-free module `core/errors/blank-screen-probe.ts`, started
from `main.ts` before `bootstrapApplication`, so it runs even if bootstrap stalls and
reports through the existing `client-error-beacon` (no backend change). It stays out
of the #282 boot watchdog's window: while no route content exists and the page is
younger than 15 s, the boot watchdog owns the case.

## Task 1 — the probe (TDD, `blank-screen-probe.spec.ts`)

Checks are scheduled 5 s after `load` (or immediately-scheduled if the document is
already complete), 3 s after a `pageshow` with `persisted`, and 3 s after
`visibilitychange` to `visible`. A check reports when:

- the `#boot-error` surface is hidden (a visible surface was already reported), and
- route content exists or the page is past the 15 s boot window, and
- no non-blank text node (measured with a `Range`) and no `img`/`svg`/`video`/`canvas`
  intersects the viewport while passing `checkVisibility({opacityProperty,
  visibilityProperty})` where the browser has it.

The report is a `BlankScreen` client error whose `stack` carries a JSON snapshot:
trigger, age, navigation type/transfer size/status, visibility, viewport and window
scroll, document height, route-content flag, element count and a depth-3 tag outline
under `<app-root>`, up to five scrolled elements, the element at the viewport centre,
and up to ten script resources with transfer size and status.

Tests: reports after load with nothing visible; silent with visible text; reports when
the only text lies outside the viewport; silent inside the boot window without route
content; silent while the boot-error surface shows; `pageshow` persisted and
`visibilitychange` trigger checks; at most one report per page; the payload carries
the snapshot.

## Task 2 — wiring and gates

`main.ts` calls `startBlankScreenProbe()`. `npm run check` in the frontend container,
`npm run build`, then a live check against the Docker stack that a forced blank
(scrolled far past the content) produces a report in the dev log.
