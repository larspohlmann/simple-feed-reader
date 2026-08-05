# Boot Watchdog Design (#282)

**Problem.** A lazy route chunk that stalls during boot leaves `<app-root>`
permanently empty. The #281 plumbing (`main.ts` bootstrap catch,
`withNavigationErrorHandler`) fires only on rejections; a hung `import()`
never rejects, so nothing reveals the `#boot-error` surface. Reproduced: with
the login chunk's request hung and a direct load of `/login`, the page is
blank after 12 s and `#boot-error` still carries `hidden`.

**Decision (Lars, 2026-08-05).** Add a pre-bootstrap watchdog in
`index.html` and **keep** the #281 plumbing. The watchdog cancels on first
render, so it cannot see a lazy chunk that fails during a later in-app
navigation — only `withNavigationErrorHandler` covers that case. The
issue-comment claim that the handler becomes deletable is wrong for that
reason. Deadline: **15 000 ms**.

## Architecture

A ~15-line inline `<script>` in `frontend/src/index.html`, placed directly
after the `#boot-error` div. It runs before any bundle loads, so it is immune
to every failure mode the bundles have. It is symptom-level by design: it
covers boot stalls anywhere — chunk, guard, resolver, future initializer —
with no app-side hook.

## Behavior

1. On parse, start a `setTimeout` with a 15 000 ms deadline. On expiry,
   remove `hidden` from `#boot-error` and `console.error` a one-line
   explanation.
2. A `MutationObserver` on `<app-root>`'s `childList` watches for the first
   added node — the first real render. When it fires: cancel the timer,
   re-add `hidden` to `#boot-error` (undoing a false-positive reveal if the
   render arrived late), and disconnect.
3. The deadline is a named constant at the top of the script with a comment
   recording the trade-off (false-positive flash vs. time-to-help). It lives
   only in `index.html`: importing TS pre-bundle is impossible, and a copy
   anywhere else would be fake sharing.

## Interaction with existing paths

- Boot-time chunk **fails**: `withNavigationErrorHandler` reveals the
  surface immediately; the watchdog later fires redundantly onto the
  already-visible surface — harmless.
- In-app navigation **fails** after first render: the observer is already
  disconnected; the handler alone acts, exactly as today.
- In-app **stall** (dead click, previous page still visible): out of scope.
  Note this residual on issue #282 when closing it.

## Testing

New e2e spec `frontend/e2e/boot-watchdog.spec.ts`:

- **Stall test** (the issue's reproduction): direct `goto('/login')` with
  `chunk-*.js` requests stalled (routed, never fulfilled, never aborted);
  assert `#boot-error` becomes visible within ~20 s. Two dev-server
  realities shape the mechanics (found in implementation, 2026-08-05): the
  dev build serves shared code as chunk-named STATIC imports, so the stall
  hangs the whole module graph — still a silent boot stall, which is the
  watchdog's exact net — and a hung graph suppresses DOMContentLoaded and
  load, so the gotos need `waitUntil: 'commit'`. Falsifiable: without the
  watchdog this times out blank (proven during the #281 review).
- **Happy-path guard**: after a normal boot renders, `#boot-error` still
  has `hidden` — guards against the observer failing to cancel the timer.

No Jest coverage is possible for an inline `index.html` script; e2e is the
owning layer. The three existing tests in
`frontend/e2e/boot-without-dictionary.spec.ts` stay green, unchanged.

## Out of scope / unchanged

`boot-error-surface.ts`, `boot-language.ts`, `app.routes.ts`,
`deploy/strato/.htaccess`, the backend.
