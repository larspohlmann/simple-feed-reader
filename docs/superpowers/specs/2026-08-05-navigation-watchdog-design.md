# Navigation Watchdog Design (#285)

**Problem.** An in-app navigation whose lazy route chunk stalls is silently
dead: the previous page stays on screen, no spinner, no error, the click does
nothing. A hung `import()` never rejects, so `withNavigationErrorHandler`
never fires, and the boot watchdog from #282 has already disconnected on first
render. Stalled guards have the same effect — `authGuard` and
`setupRedirectGuard` issue HTTP with no timeout.

**Decisions (Lars, 2026-08-05).**

1. **A navigation watchdog, not bounded imports.** #285 originally proposed
   wrapping each `loadComponent`/`loadChildren` in a timeout. Rejected in
   favour of one unit that watches router events: it covers stalled chunks,
   guards and resolvers together, touches no route entry so no future route
   can forget it, and is the structural sibling of the boot watchdog. Do NOT
   also add the per-route wrapper — it buys nothing once this exists.
2. **Deadline: 8000 ms.** Long past a slow-3G chunk fetch, short enough that
   the banner still reads as a response to the click. (The boot watchdog's
   15 s covers a cold start on a possibly-dead radio; a mid-session
   navigation starts from a working connection.)
3. **Report differently before and after first render.** Before anything has
   rendered, keep the full-page `#boot-error` surface — nothing else can
   render then. After it, show an in-app retry banner and leave the current
   page intact.

## Architecture

Three small units.

### `frontend/src/app/core/navigation-failure.ts`

Owns the single decision "how do we report a broken navigation". Holds the
signal the banner reads.

- No navigation has ever completed → call `revealBootErrorSurface` (the
  existing DI-free function): there is no Angular render to host a banner.
- Otherwise → set the signal.

Both the watchdog and the existing `withNavigationErrorHandler` report here.
That fixes a second defect for free: today a chunk *failure* mid-session
replaces a working page with the bilingual "The app could not start" screen,
which is false.

### `frontend/src/app/core/navigation-watchdog.ts`

- `NavigationStart` arms an 8000 ms timer (re-arming resets it, so rapid
  navigations keep one timer).
- `NavigationEnd`, `NavigationCancel`, `NavigationError` clear it. Guard
  redirects raise `NavigationCancel`, so `setupRedirectGuard` cannot trip it.
- Expiry reports the failure.
- A later `NavigationEnd` also clears the banner. This self-correction is what
  makes a false positive a brief annoyance instead of a wrong terminal state —
  the same role the boot watchdog's re-hide plays.

### `frontend/src/app/app.html` and `app.ts`

An `@if`-guarded `app-error-banner` (shared component, already used by seven
screens) above the outlet, with `de`/`en` keys for the message and the action
label. `ErrorBannerComponent` takes already-translated strings, so the caller
resolves the keys.

**Critical coupling — the banner must not be static markup.** The #282 boot
watchdog cancels its timer when `<app-root>` holds any element that is not
`<router-outlet>`. Static chrome in `app.html` would therefore disarm the boot
watchdog at ~70 ms and re-open #282. An `@if` whose condition is false renders
only a comment anchor, and comment nodes are not elements, so
`querySelector(':not(router-outlet)')` stays null. Update the comment in
`index.html` to record this coupling explicitly rather than leaving it as a
general warning about "static chrome".

## The retry button reloads the document

Deliberate, and it looks inconsistent with preserving the page, so the reason
is recorded: for a stalled chunk the module system caches the pending promise,
so re-navigating awaits the same hung fetch and the button would appear
broken. Only a fresh document gets a fresh module graph. Keeping the page was
about not showing a false "the app could not start"; pressing retry is the
user explicitly choosing to leave.

## Testing

- **Jest, fake timers, mocked router event stream** (`navigation-watchdog`):
  arms on `NavigationStart`; clears on each of `NavigationEnd`,
  `NavigationCancel`, `NavigationError`; reports on expiry; clears the banner
  on a later `NavigationEnd`; a re-arm resets rather than stacks timers.
- **Jest** (`navigation-failure`): reports to the boot surface before any
  completed navigation, to the signal after one.
- **Jest component test** (`app`): the banner renders only when the signal is
  set, and `app.html` renders no element other than `<router-outlet>` when it
  is not — the regression guard for the coupling above.
- **Playwright** (one test): render `/register`, stall the discovered login
  chunk, click through to `/login`, expect the banner within ~12 s and assert
  `#boot-error` stays hidden. A direct-invocation test can assert something
  the real wiring makes impossible, so this backs the unit tests.

## Out of scope / unchanged

`index.html`'s watchdog script logic (only its comment changes),
`boot-language.ts`, `transloco-loader.ts`, `deploy/strato/.htaccess`, the
backend. No per-route `import()` wrapper.
