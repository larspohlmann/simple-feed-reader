# #1135 — Account-scoped state: nothing of one account survives into the next

## Problem

After a logout, or an expired session, and a sign-in as another account in the
same tab, the reader briefly shows the previous account's entry list. It stays
until the new account's first page arrives.

Sign-out never reloads the page. `AuthService.logout()` and the interceptor's
401 path clear the token and navigate to `/login`, so every `providedIn: 'root'`
service survives into the next session with whatever it held (#263).
`EntriesStore` never resets, and `load()` deliberately keeps the outgoing list
rendered until the response lands (#254), so the new account sees the old rows
under its own shell.

The fix for #263 (`onIdentityChange`) is opt-in: each store must remember to
reset itself. This is the third leak of the class (#263, #727, #1135). Today:

| Store | Leak |
|---|---|
| `EntriesStore` | Entry list, cursor, search matches, retry closure |
| `TagsStore` | Tag names (Discover, Manage, add-feed dialog) |
| `SavedSearchesStore` | Saved-search terms and counts |
| `RecommendationsService` | Run report; ticker and poll timer keep running and poll with the next token |
| `RefreshService` | Run state; a busy-backoff timer fires a refresh with the next token and the previous shell's callback |
| `MailHealthStore` | Admin mail-failure log, including recipient addresses |
| `BackupRestoreRun` | Restore progress (reset only by an explicit call) |
| `AuthService.user`, `PreferencesService`, `DigestService` | Reset by `logout()` only, not on the 401 path |
| `ToastService` | An open toast ("Undo", the For-you pill) stays open into the next session |
| `ReaderLocationService` | After a 401, the next account to sign in lands on the previous account's reader URL |

A related fault: the interceptor clears the token on every 401, so a request
the previous account sent that fails after the next account signed in signs the
next account out.

## Decision

Per-account state lives in one declared form that resets itself, and no
response issued for one identity can reach the app once the identity changed.
No page reload.

1. **`accountSignal(initial)`** — a writable signal that returns to `initial` on
   identity change. Every per-account value, signal or plain field today, is
   declared with it — including request bookkeeping such as an in-flight
   handle: a dropped response completes without `next` or `error`, so a marker
   cleared only there would stay set for the next account. A plain field that
   the next run or load always overwrites before reading it stays plain.
2. **The interceptor drops stale-identity responses.** A response (success or
   error) to an API request whose token differs from the current token is
   discarded; the observable completes without a value.
3. **`onIdentityChange` stays, for side effects only:** stopping loops, timers
   and audio, closing toasts, clearing browser storage — and clearing a mutable
   collection (`Map`, `Set`) that holds per-account entries, since a signal
   wrapping a mutated collection would not notify.
4. **The sign-in return URL is bound to the account** through the JWT
   `username` claim.
5. **A Jest guard** fails when a root service holds a plain `signal(` and is not
   on the device/instance allow-list.

The #254 behaviour is unchanged within one account: switching lists keeps the
old list rendered during the load. Only an identity change empties it, so the
next account's first load shows the existing skeleton
(`entry-list.component.html`, `@if (loading() && entries().length === 0)`).

## Design

### `accountSignal` (`core/session-identity.ts`)

```ts
export function accountSignal<T>(initial: T): WritableSignal<T> {
  const state = signal(initial);
  onIdentityChange(() => state.set(initial));
  return state;
}
```

- Called from an injection context, as field initialisers of an injectable are.
- Same trigger semantics as `onIdentityChange`: the identity present at creation
  is not a change (a reload keeps its state); setting the same token again is
  not a change.
- `initial` is the reset value. A service whose start value comes from browser
  storage (`MagazineStyleService`) declares `accountSignal('boxed')` and seeds it
  in the constructor.

### Stale-identity responses (`core/auth.interceptor.ts`)

The interceptor already captures `token` when the request is made. For API
requests it adds, before the existing error handling:

- a response event whose `tokens.token()` no longer equals the captured token is
  filtered out;
- an error under the same condition is swallowed (`EMPTY`), so the 401 branch
  never runs for it — this fixes the stale-401 sign-out.

Non-response events (`Sent`, progress) pass through. Requests that are not API
requests are untouched. A request sent with no token (login, registration,
passkey sign-in) keeps working: its token is `null` before and after the
response, and the sign-in `tap` that sets the new token runs after the
interceptor.

The app never renews a token for the same account; only sign-in sets one. A
token change is always an identity change.

Consequence: `firstValueFrom` on a dropped request rejects with `EmptyError`.
The callers are `BackupRestoreRun` and `PasskeyService`; the plan checks each so
that no error reaches the next account's screen.

### Store migration

Leaking today:

- **`EntriesStore`** — `rawEntries`, `nextCursor`, `loading`, `loadingMore`,
  `error`, `loadedAt`, `matchedWords`, `query`, `failedOperation` become
  `accountSignal`s. `loadSeq` stays (it orders overlapping loads within one
  account, #158); the stale-identity drop covers the sign-out case.
  `inFlightPatches` is cleared by `onIdentityChange` (rule 3's collection case):
  entry ids are shared across accounts, so a patch left by account A would
  otherwise be laid over account B's copy of the same entry.
- **`TagsStore`** — `tags`, `loading`, `error`.
- **`SavedSearchesStore`** — `loaded`, `readSinceLoad`, `lastLoadedAt`.
  `inFlight` is released by `onIdentityChange` (the dropped response never
  clears it).
- **`RecommendationsService`** — `running`, `stopping`, `report`, `failure`, the
  ETA/elapsed signals, `rateLimited`. An `onIdentityChange` side effect stops the
  ticker and cancels a pending poll timer (`stepLater` keeps its handle).
  `completedStamp` stays a plain signal: it is a monotonic event counter, not
  account data.
- **`RefreshService`** — `running`, `report`, `failure`. The busy-backoff timer
  keeps its handle; an `onIdentityChange` side effect cancels it. `slice` and
  `previousRemaining` are per-run and reset by `run()`.
- **`MailHealthStore`** — `failures`.
- **`BackupRestoreRun`** — `reset()` has callers in the backup section, so it
  stays and is wired to `onIdentityChange`; it already clears every field,
  including the account's open archive.
- **`AuthService.user`** — `accountSignal<CurrentUser | null>(null)`.
- **`PreferencesService`, `DigestService`, `AiAvailabilityService`** — their
  signals become `accountSignal`s; `reset()` is deleted.
- **`MagazineStyleService`** — `style = accountSignal('boxed')`, seeded from
  `localStorage`; an `onIdentityChange` side effect removes the key;
  `saveFailed` is an `accountSignal`; `reset()` is deleted.
- **`ToastService`** — `onIdentityChange` side effect: dismiss.

Already resetting, moved to the new form:

- **`SubscriptionsStore`** — signals, `lastLoadedAt` and `inFlight` become
  `accountSignal`s; the private `invalidate()` and the constructor hook are
  deleted. `latestLoad` and `localEdits` stay (same-account ordering).
- **`CatalogStore`** — signals become `accountSignal`s; the constructor hook is
  deleted. The public `invalidate()` stays for the after-subscribe refetch.
- **`EntryBodyService`** — `generation` is deleted (the interceptor drop
  replaces it). The cache is a `Map` of signals, so it stays cleared by
  `onIdentityChange` (rule 3's collection case).
- **`PasskeyService.lastUserHandle`** — `accountSignal`.
- **`AuthService.logout()`** — loses the `preferences/magazineStyle/digest/ai`
  reset calls; keeps `tokens.clear()`, `clearSignInReturnUrl()` and the
  navigation.

Unchanged: `AudioPlayerService`, `SidebarCountsPollService`, `OnboardingSkip`
(side effects). Device preferences — reading layout, unread filter, pane split,
sidebar visibility and collapse — belong to the device, not the account.

### Sign-in return URL (`core/reader-location.service.ts`)

- `currentReaderUrl` becomes an `accountSignal`; an `onIdentityChange` side
  effect removes its sessionStorage key.
- The return URL is stored with the account identifier: `{ url, account }` in
  sessionStorage. The interceptor's 401 branch passes the `username` claim of
  the failed request's token, and records it before it clears the token. A
  token it cannot decode records no return URL.
- The auth guard's path (a signed-out visitor opens a reader deep link) stores
  `account: null`: the visitor chose that URL, so whoever signs in next uses it.
- Behaviour change against #969: logout no longer keeps the saved reader URL
  for the settings back link — it is the previous account's location. #969's
  acceptance criteria do not require it; the test that asserted it flips.
- `consumeSignInReturnUrl()` compares the stored `account` with the `username`
  claim of the current token. Equal → the URL; otherwise `/`. The stored value
  is deleted in both cases.
- The payload is decoded only to compare two values; the signature is not
  verified and nothing is authorised by it — the server checks every request.
- A small pure helper, `accountOf(token): string | null` in `core/`, decodes the
  base64url payload.
- An explicit logout still clears the return URL.

### Guard (`core/account-state.guard.spec.ts`)

A Jest spec reads every non-spec `.ts` file under `src/app`. A file containing
`providedIn: 'root'` and a plain `signal(`/`signal<` call fails unless it also
uses `accountSignal`, or it is on the allow-list. The allow-list names device
and instance state (theme, brightness, layout, language, version, setup,
toasts, and the like), each with a one-line reason. It only ever shrinks.

## Testing

Jest, inside the Docker frontend container.

- `accountSignal`: resets on A → empty and A → B; not at creation; not on the
  same token set again.
- Interceptor: drops a success and an error response when the token changed in
  flight; passes both when it did not; a 401 for a dropped request leaves the
  new token in place; non-API requests are untouched.
- Each migrated store: filled as account A, token changed, state empty. For
  `EntriesStore`, the reported fault: after the change `entries()` is empty, and
  the first `load()` for B has `loading()` true with no rows until B's page
  arrives.
- `RecommendationsService`: a pending poll timer sends no request after the
  identity change; the ticker stops.
- `ToastService`: an open toast closes on identity change.
- Return URL: same account → URL; other account → `/`; undecodable token → `/`.
- The existing `invalidate()`/`reset()`/`generation` tests move to the new form
  or go with the code they test.
- The guard spec fails when a new root service with a plain signal is added
  (verified by breaking it once).

Infection gates only PHP, so these Jest tests are the net.

## Out of scope

- Signing out when the JWT `exp` passes in an idle tab (a timer; separate issue).
- The IndexedDB article cache (`ReaderCacheService`): entries belong to shared
  feeds, and the reader view reads the cache only for an entry the server has
  already returned to the current account (a list row or a deep-link detail).
