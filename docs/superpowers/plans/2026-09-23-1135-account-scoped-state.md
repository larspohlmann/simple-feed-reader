# Account-Scoped State Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Nothing one account held in memory reaches the next account that signs in on the same tab — first of all, the previous account's entry list no longer flashes after sign-in.

**Architecture:** A new `accountSignal(initial)` primitive resets per-account state on identity change (it rides the existing `onIdentityChange` trigger). The auth interceptor drops any API response whose request carried a different token than the current one. Every root store holding per-account state migrates to the primitive; `onIdentityChange` remains for side effects. The sign-in return URL is bound to the JWT `username` claim. A Jest guard fails when a new root service holds plain signals without a decision.

**Tech Stack:** Angular 20 (standalone, signals), RxJS, Jest (jsdom), `HttpTestingController`.

**Spec:** `docs/superpowers/specs/2026-09-23-1135-account-scoped-state-design.md`

## Global Constraints

- Branch `fix/1135-account-scoped-state` (exists). Commit format `type(#1135): lower-case summary`.
- Run Jest **inside the Docker frontend container**, one run at a time (concurrent runs OOM it):
  `docker compose exec -T frontend npx jest <path>` from the repo root.
  Native jest skips the typecheck; also run `docker compose exec -T frontend npm run typecheck:spec` before each commit.
- Final gate: `docker compose exec -T frontend npm run check` (ESLint + Prettier 100-col + Stylelint + typecheck + Jest).
- Comments: default to none. One line, three at most. Delete a comment that the change makes wrong; do not narrate the change.
- **Token-first test rule:** `onIdentityChange` treats the token present at a service's creation as "no change". In every new identity test, call `tokens.set('<account A token>')` **before** the store is injected or filled, then change the token, then `TestBed.tick()` (effects run on tick).
- Identity reset is effect-based: assert it after `TestBed.tick()`.
- Do not change backend code. Do not touch device preferences (reading layout, unread filter, pane split, sidebar visibility/collapse).

## File Structure

| File | Responsibility |
|---|---|
| `frontend/src/app/core/session-identity.ts` | Adds `accountSignal()` next to `onIdentityChange()` |
| `frontend/src/app/core/session-identity.spec.ts` (new) | Primitive semantics |
| `frontend/src/app/core/jwt-account.ts` (new) | `accountOf(token)` — the `username` claim, or `null` |
| `frontend/src/app/core/jwt-account.spec.ts` (new) | Decoding cases |
| `frontend/src/app/core/auth.interceptor.ts` | Stale-identity drop; account-bound 401 return URL |
| `frontend/src/app/core/reader-location.service.ts` | Account-bound return URL; `currentReaderUrl` resets |
| `frontend/src/app/core/account-state.guard.spec.ts` (new) | Guard over root services holding plain signals |
| Stores (one task each group) | Migration per spec |

---

### Task 1: `accountSignal` primitive

**Files:**
- Modify: `frontend/src/app/core/session-identity.ts`
- Create: `frontend/src/app/core/session-identity.spec.ts`

**Interfaces:**
- Produces: `export function accountSignal<T>(initial: T): WritableSignal<T>` in `core/session-identity.ts`. Call from an injection context (field initialiser or constructor of an injectable).

- [ ] **Step 1: Write the failing test** — `frontend/src/app/core/session-identity.spec.ts`:

```ts
import { Injectable } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { accountSignal } from './session-identity';
import { TokenStore } from './token.store';

@Injectable({ providedIn: 'root' })
class AccountHolder {
  readonly rows = accountSignal<string[]>([]);
}

describe('accountSignal', () => {
  let tokens: TokenStore;

  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({});
    tokens = TestBed.inject(TokenStore);
    tokens.set('account-a.jwt');
  });

  const holderFilledByAccountA = (): AccountHolder => {
    const holder = TestBed.inject(AccountHolder);
    holder.rows.set(['a-row']);
    TestBed.tick();
    return holder;
  };

  it('returns to its initial value when the account signs out', () => {
    const holder = holderFilledByAccountA();

    tokens.clear();
    TestBed.tick();

    expect(holder.rows()).toEqual([]);
  });

  it('returns to its initial value when another account signs in', () => {
    const holder = holderFilledByAccountA();

    tokens.set('account-b.jwt');
    TestBed.tick();

    expect(holder.rows()).toEqual([]);
  });

  it('keeps its value when the same token is set again', () => {
    const holder = holderFilledByAccountA();

    tokens.set('account-a.jwt');
    TestBed.tick();

    expect(holder.rows()).toEqual(['a-row']);
  });

  it('does not treat the token present at creation as a change', () => {
    const holder = holderFilledByAccountA();

    TestBed.tick();

    expect(holder.rows()).toEqual(['a-row']);
  });
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `docker compose exec -T frontend npx jest src/app/core/session-identity.spec.ts`
Expected: FAIL — `accountSignal` is not exported.

- [ ] **Step 3: Implement** — in `core/session-identity.ts`, change the import line to
`import { WritableSignal, effect, inject, signal, untracked } from '@angular/core';`
and append:

```ts
/** A signal holding per-account state: it returns to `initial` whenever the
 *  signed-in identity changes, with the same trigger as `onIdentityChange`. */
export function accountSignal<T>(initial: T): WritableSignal<T> {
  const state = signal(initial);
  onIdentityChange(() => state.set(initial));
  return state;
}
```

- [ ] **Step 4: Run it to verify it passes** — same command. Expected: 4 passed.

- [ ] **Step 5: Typecheck and commit**

```bash
docker compose exec -T frontend npm run typecheck:spec
git add frontend/src/app/core/session-identity.ts frontend/src/app/core/session-identity.spec.ts
git commit -m "feat(#1135): add accountSignal, per-account state that resets on identity change"
```

---

### Task 2: The interceptor drops stale-identity responses

**Files:**
- Modify: `frontend/src/app/core/auth.interceptor.ts`
- Test: `frontend/src/app/core/auth.interceptor.spec.ts`

**Interfaces:**
- Consumes: `TokenStore.token()`.
- Produces: behaviour only — an API response (success or error) whose request token differs from `tokens.token()` at arrival never reaches the subscriber; the observable completes without a value. Later tasks rely on this to delete their own sign-out request guards.

- [ ] **Step 1: Write the failing tests** — append inside `describe('authInterceptor', …)`:

```ts
  describe('when the identity changed while the request was on the wire', () => {
    it('drops a success response issued for the previous account', () => {
      tokens.set('account-a.jwt');
      const next = jest.fn();
      const complete = jest.fn();
      http.get('https://api.test/api/entries').subscribe({ next, complete });
      const request = ctrl.expectOne('https://api.test/api/entries');

      tokens.set('account-b.jwt');
      request.flush({ entries: ['a-row'] });

      expect(next).not.toHaveBeenCalled();
      expect(complete).toHaveBeenCalled();
    });

    it('drops an error response, so a stale 401 cannot sign the next account out', () => {
      tokens.set('account-a.jwt');
      const error = jest.fn();
      http.get('https://api.test/api/entries').subscribe({ error });
      const request = ctrl.expectOne('https://api.test/api/entries');

      tokens.set('account-b.jwt');
      request.flush(null, { status: 401, statusText: 'Unauthorized' });

      expect(error).not.toHaveBeenCalled();
      expect(tokens.token()).toBe('account-b.jwt');
      expect(navigate).not.toHaveBeenCalled();
    });

    it('passes a response through when the token did not change', () => {
      tokens.set('account-a.jwt');
      const next = jest.fn();
      http.get('https://api.test/api/entries').subscribe({ next });

      ctrl.expectOne('https://api.test/api/entries').flush({ entries: [] });

      expect(next).toHaveBeenCalledWith({ entries: [] });
    });

    it('passes a signed-out request through, so a sign-in response still arrives', () => {
      const next = jest.fn();
      http.post('https://api.test/api/auth/login', {}).subscribe({ next });

      ctrl.expectOne('https://api.test/api/auth/login').flush({ token: 'account-a.jwt' });

      expect(next).toHaveBeenCalledWith({ token: 'account-a.jwt' });
    });

    it('leaves non-API requests alone', () => {
      tokens.set('account-a.jwt');
      const next = jest.fn();
      http.get('i18n/en.json').subscribe({ next });
      const request = ctrl.expectOne('i18n/en.json');

      tokens.clear();
      request.flush({ hello: 'Hello' });

      expect(next).toHaveBeenCalledWith({ hello: 'Hello' });
    });
  });
```

`navigate` is a shared `jest.fn()` declared outside `beforeEach`; add `navigate.mockClear();` as the first line of the existing `beforeEach` if it is not reset there already.

- [ ] **Step 2: Run to verify the new tests fail**

Run: `docker compose exec -T frontend npx jest src/app/core/auth.interceptor.spec.ts`
Expected: the first two new tests FAIL (the stale response is delivered; the stale 401 clears `account-b.jwt`).

- [ ] **Step 3: Implement** — in `core/auth.interceptor.ts`:

Change the rxjs import to `import { EMPTY, catchError, filter, throwError } from 'rxjs';`.

After the `authed` constant, add:

```ts
  const issuedForAnotherIdentity = (): boolean => isApi && tokens.token() !== token;
```

Replace `return next(authed).pipe(` … with:

```ts
  return next(authed).pipe(
    filter(() => !issuedForAnotherIdentity()),
    catchError((err) => {
      if (issuedForAnotherIdentity()) return EMPTY;
      if (err.status === 401) {
```

Inside the 401 branch, `requestUsesCurrentToken` is now `isApi && token !== null` (a stale token never reaches this line). Replace the comment and constant with:

```ts
        // Only a 401 for a request that carried a token means the session
        // expired; a signed-out request (a failed password sign-in) must not
        // resurrect a return destination.
        const requestUsesCurrentToken = isApi && token !== null;
```

Leave the rest of the 401 branch as it is (Task 3 changes it).

- [ ] **Step 4: Run the whole interceptor spec** — same command. Expected: all pass, including the existing "does not restore … after explicit logout" cases.

- [ ] **Step 5: Typecheck and commit**

```bash
docker compose exec -T frontend npm run typecheck:spec
git add frontend/src/app/core/auth.interceptor.ts frontend/src/app/core/auth.interceptor.spec.ts
git commit -m "fix(#1135): drop api responses issued for a previous identity"
```

---

### Task 3: Bind the sign-in return URL to the account

**Files:**
- Create: `frontend/src/app/core/jwt-account.ts`, `frontend/src/app/core/jwt-account.spec.ts`
- Modify: `frontend/src/app/core/reader-location.service.ts`, `frontend/src/app/core/auth.interceptor.ts`
- Test: `frontend/src/app/core/reader-location.service.spec.ts`, `frontend/src/app/core/auth.interceptor.spec.ts`, `frontend/src/app/core/auth.service.spec.ts`
- Create: `frontend/src/testing/jwt.ts`

**Interfaces:**
- Consumes: `accountSignal` (Task 1), the stale drop (Task 2).
- Produces:
  - `accountOf(token: string | null): string | null` in `core/jwt-account.ts`.
  - `ReaderLocationService.rememberSavedReaderUrlForSignIn(account: string): void` (was parameterless).
  - `consumeSignInReturnUrl(): string` now returns the URL only for the bound account (or an unbound one); otherwise `'/'`.
  - Test helper `jwtFor(username: string): string` in `src/testing/jwt.ts`.

- [ ] **Step 1: Write the helper and the failing `accountOf` tests**

`frontend/src/testing/jwt.ts`:

```ts
/** An unsigned token whose payload carries `username`, shaped like the API's. */
export function jwtFor(username: string): string {
  const payload = btoa(JSON.stringify({ username, iat: 1, exp: 2 }))
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=+$/, '');
  return `header.${payload}.signature`;
}
```

`frontend/src/app/core/jwt-account.spec.ts`:

```ts
import { jwtFor } from '../../testing/jwt';
import { accountOf } from './jwt-account';

describe('accountOf', () => {
  it('reads the username claim', () => {
    expect(accountOf(jwtFor('a@example.test'))).toBe('a@example.test');
  });

  it('decodes a base64url payload that needs padding', () => {
    expect(accountOf(jwtFor('ab@example.test'))).toBe('ab@example.test');
    expect(accountOf(jwtFor('abc@example.test'))).toBe('abc@example.test');
  });

  it.each([
    ['no token', null],
    ['an opaque string', 'not-a-jwt'],
    ['a payload that is not JSON', 'h.bm90LWpzb24.s'],
    ['a payload without username', `h.${btoa(JSON.stringify({ sub: 1 }))}.s`],
    ['an empty username', `h.${btoa(JSON.stringify({ username: '' }))}.s`],
  ])('returns null for %s', (_, token) => {
    expect(accountOf(token)).toBeNull();
  });
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `docker compose exec -T frontend npx jest src/app/core/jwt-account.spec.ts`
Expected: FAIL — module `./jwt-account` not found.

- [ ] **Step 3: Implement `core/jwt-account.ts`**

```ts
/** The account a token was issued to — its `username` claim — or null when the
 *  token is absent or unreadable. Unverified: it only tells two tokens apart. */
export function accountOf(token: string | null): string | null {
  const payload = token?.split('.')[1];
  if (!payload) return null;
  try {
    const claims: unknown = JSON.parse(atob(payload.replace(/-/g, '+').replace(/_/g, '/')));
    const username = (claims as { username?: unknown } | null)?.username;
    return typeof username === 'string' && username !== '' ? username : null;
  } catch {
    return null;
  }
}
```

Run the spec again. Expected: PASS.

- [ ] **Step 4: Write the failing `ReaderLocationService` tests**

In `core/reader-location.service.spec.ts`, add `TokenStore` and `jwtFor` imports and `localStorage.clear();` in `beforeEach`. Then add:

```ts
  describe('the sign-in return URL after an expired session', () => {
    const readerUrl = '/?tag=17&entry=42-example';

    const expiredSessionOf = (account: string): ReaderLocationService => {
      TestBed.inject(TokenStore).set(jwtFor(account));
      const service = build();
      events.next(new NavigationEnd(1, readerUrl, readerUrl));
      service.rememberSavedReaderUrlForSignIn(account);
      TestBed.inject(TokenStore).clear();
      TestBed.tick();
      return service;
    };

    it('returns the account to where it was', () => {
      const service = expiredSessionOf('a@example.test');

      TestBed.inject(TokenStore).set(jwtFor('a@example.test'));

      expect(service.consumeSignInReturnUrl()).toBe(readerUrl);
    });

    it('sends another account to the reader root', () => {
      const service = expiredSessionOf('a@example.test');

      TestBed.inject(TokenStore).set(jwtFor('b@example.test'));

      expect(service.consumeSignInReturnUrl()).toBe('/');
    });

    it('is used once, whoever signs in', () => {
      const service = expiredSessionOf('a@example.test');
      TestBed.inject(TokenStore).set(jwtFor('b@example.test'));
      service.consumeSignInReturnUrl();

      TestBed.inject(TokenStore).set(jwtFor('a@example.test'));

      expect(service.consumeSignInReturnUrl()).toBe('/');
    });
  });

  it('gives a signed-out deep link to whoever signs in', () => {
    const service = build();
    service.rememberAttemptedReaderUrl('/?tag=17');

    TestBed.inject(TokenStore).set(jwtFor('b@example.test'));

    expect(service.consumeSignInReturnUrl()).toBe('/?tag=17');
  });

  it('forgets the previous account\'s reader location on identity change', () => {
    TestBed.inject(TokenStore).set(jwtFor('a@example.test'));
    const service = build();
    events.next(new NavigationEnd(1, '/?tag=17', '/?tag=17'));

    TestBed.inject(TokenStore).clear();
    TestBed.tick();

    expect(service.savedReaderUrl()).toBe('/');
    expect(sessionStorage.getItem('sfr.reader-location.current')).toBeNull();
  });
```

Update the existing tests in this file that call `rememberSavedReaderUrlForSignIn()` with no argument: set `TestBed.inject(TokenStore).set(jwtFor('a@example.test'))` before `build()`, pass `'a@example.test'`, and consume while the same token is set. Keep their assertions.

The "restores a reader and pending sign-in URL after a reload" test stores through `rememberAttemptedReaderUrl` (unbound), so it keeps passing unchanged. The "discards invalid stored URLs" test writes a plain string under `sfr.reader-location.sign-in-return`; it must still read `'/'` (old-format values are discarded).

- [ ] **Step 5: Run to verify they fail**

Run: `docker compose exec -T frontend npx jest src/app/core/reader-location.service.spec.ts`
Expected: FAIL — type error on the argument, and "sends another account to the reader root" gets the URL.

- [ ] **Step 6: Implement in `core/reader-location.service.ts`**

Imports: add `accountSignal, onIdentityChange` from `./session-identity`, `accountOf` from `./jwt-account`, `TokenStore` from `./token.store`.

Add below the key constants:

```ts
interface SignInReturn {
  url: string;
  /** Who may use it: the expired session's account, or null for a deep link
   *  the signed-out visitor opened themselves. */
  account: string | null;
}
```

Fields and constructor become:

```ts
  private readonly router = inject(Router);
  private readonly tokens = inject(TokenStore);
  private readonly destroyRef = inject(DestroyRef);
  private readonly currentReaderUrl = accountSignal<string | null>(null);
  private readonly signInReturn = signal(this.readSignInReturn());

  constructor() {
    this.currentReaderUrl.set(this.readReaderUrl());
    const subscription = this.router.events
      .pipe(filter((event): event is NavigationEnd => event instanceof NavigationEnd))
      .subscribe((event) => this.rememberReaderUrl(event.urlAfterRedirects));
    this.destroyRef.onDestroy(() => subscription.unsubscribe());
    onIdentityChange(() => this.remove(READER_URL_KEY));
  }
```

`accountSignal(x)` resets to `x`, so the reset value is `null` and the stored URL is seeded in the constructor.

Public methods:

```ts
  rememberAttemptedReaderUrl(url: string): void {
    if (!isReaderUrl(url)) return;
    this.rememberReaderUrl(url);
    this.setSignInReturn({ url, account: null });
  }

  rememberSavedReaderUrlForSignIn(account: string): void {
    this.setSignInReturn({ url: this.savedReaderUrl(), account });
  }

  consumeSignInReturnUrl(): string {
    const stored = this.signInReturn();
    this.clearSignInReturnUrl();
    if (stored === null) return '/';
    if (stored.account !== null && stored.account !== accountOf(this.tokens.token())) return '/';
    return stored.url;
  }

  clearSignInReturnUrl(): void {
    this.signInReturn.set(null);
    this.remove(SIGN_IN_RETURN_URL_KEY);
  }
```

Private helpers (replace `setSignInReturnUrl` and `readReaderUrl(key)`):

```ts
  private setSignInReturn(value: SignInReturn): void {
    this.signInReturn.set(value);
    this.write(SIGN_IN_RETURN_URL_KEY, JSON.stringify(value));
  }

  private readReaderUrl(): string | null {
    const url = this.read(READER_URL_KEY);
    return url !== null && isReaderUrl(url) ? url : null;
  }

  private readSignInReturn(): SignInReturn | null {
    const raw = this.read(SIGN_IN_RETURN_URL_KEY);
    if (raw === null) return null;
    try {
      const value = JSON.parse(raw) as Partial<SignInReturn>;
      if (typeof value.url !== 'string' || !isReaderUrl(value.url)) return null;
      return { url: value.url, account: typeof value.account === 'string' ? value.account : null };
    } catch {
      return null;
    }
  }

  private read(key: string): string | null {
    try {
      return sessionStorage.getItem(key);
    } catch {
      return null;
    }
  }
```

- [ ] **Step 7: Wire the interceptor** — in `core/auth.interceptor.ts` import `accountOf` from `./jwt-account` and replace the 401 branch body with:

```ts
      if (err.status === 401) {
        // Recorded before the token is cleared: the identity change resets the
        // reader location it reads.
        const expiredAccount = isApi ? accountOf(token) : null;
        if (expiredAccount !== null) {
          readerLocation.rememberSavedReaderUrlForSignIn(expiredAccount);
        }
        tokens.clear();
        void router.navigate(['/login']);
        return throwError(() => err);
      }
```

Delete the now-unused `requestUsesCurrentToken` constant and its comment.

- [ ] **Step 8: Update the interceptor and auth-service specs**

In `core/auth.interceptor.spec.ts`: the tests that expect a saved destination after a 401 (e.g. "makes the saved reader URL the pending sign-in destination on 401") must use `tokens.set(jwtFor('a@example.test'))` instead of `'jwt-abc'`, and consume after `tokens.set(jwtFor('a@example.test'))`. Add one test:

```ts
  it('does not hand an expired session\'s destination to another account', () => {
    const location = TestBed.inject(ReaderLocationService);
    tokens.set(jwtFor('a@example.test'));
    events.next(new NavigationEnd(1, '/?tag=17', '/?tag=17'));

    http.get('https://api.test/api/me').subscribe({ error: () => undefined });
    ctrl
      .expectOne('https://api.test/api/me')
      .flush(null, { status: 401, statusText: 'Unauthorized' });
    tokens.set(jwtFor('b@example.test'));

    expect(location.consumeSignInReturnUrl()).toBe('/');
  });
```

In `core/auth.service.spec.ts`, the test "logout clears the pending sign-in destination but keeps the saved reader URL" becomes (behaviour change recorded in the spec, against #969):

```ts
  it('logout clears the pending sign-in destination and the reader location', () => {
    tokens.set(jwtFor('a@example.test'));
    const location = TestBed.inject(ReaderLocationService);
    events.next(new NavigationEnd(1, '/?tag=17', '/?tag=17&entry=42-example#comments'));
    location.rememberSavedReaderUrlForSignIn('a@example.test');

    svc.logout();
    TestBed.tick();

    expect(location.savedReaderUrl()).toBe('/');
    expect(location.consumeSignInReturnUrl()).toBe('/');
  });
```

Search for any other caller: `grep -rn "rememberSavedReaderUrlForSignIn\|consumeSignInReturnUrl" frontend/src --include='*.ts'` and fix each spec the same way (settings `account-section` spec and the auth components' specs, if they call it).

- [ ] **Step 9: Run the three specs, one at a time**

```bash
docker compose exec -T frontend npx jest src/app/core/reader-location.service.spec.ts
docker compose exec -T frontend npx jest src/app/core/auth.interceptor.spec.ts
docker compose exec -T frontend npx jest src/app/core/auth.service.spec.ts
```

Expected: all pass.

- [ ] **Step 10: Typecheck and commit**

```bash
docker compose exec -T frontend npm run typecheck:spec
git add frontend/src/testing/jwt.ts frontend/src/app/core/jwt-account.ts frontend/src/app/core/jwt-account.spec.ts frontend/src/app/core/reader-location.service.ts frontend/src/app/core/reader-location.service.spec.ts frontend/src/app/core/auth.interceptor.ts frontend/src/app/core/auth.interceptor.spec.ts frontend/src/app/core/auth.service.spec.ts
git commit -m "fix(#1135): bind the sign-in return url to the account it was saved for"
```

---

### Task 4: `EntriesStore` — the reported fault

**Files:**
- Modify: `frontend/src/app/reader/entries.store.ts`
- Test: `frontend/src/app/reader/entries.store.spec.ts`

**Interfaces:**
- Consumes: `accountSignal`, `onIdentityChange` from `../core/session-identity`.
- Produces: public API unchanged (`entries`, `nextCursor`, `loading`, `loadingMore`, `error`, `loadedAt`, `matchedWords`, `load`, `loadMore`, …).

- [ ] **Step 1: Write the failing tests** — in `entries.store.spec.ts` add `TokenStore` import; in `beforeEach`, before `TestBed.inject(EntriesStore)`, add `localStorage.clear();` and after configuring the module `TestBed.inject(TokenStore).set('account-a.jwt');`. Then add:

```ts
  describe('when the signed-in identity changes', () => {
    const loadAsAccountA = (): void => {
      store.load({ view: 'all' } as EntryQuery);
      ctrl.expectOne((r) => r.url === 'https://api.test/api/entries').flush({
        entries: [entry(1), entry(2)],
        nextCursor: 'c2',
        matchedWords: ['rust'],
      });
    };

    it('shows the next account a loading list, never the previous rows', () => {
      loadAsAccountA();

      TestBed.inject(TokenStore).clear();
      TestBed.tick();
      TestBed.inject(TokenStore).set('account-b.jwt');
      TestBed.tick();
      store.load({ view: 'all' } as EntryQuery);

      expect(store.entries()).toEqual([]);
      expect(store.loading()).toBe(true);
      ctrl.expectOne((r) => r.url === 'https://api.test/api/entries').flush({
        entries: [entry(9)],
        nextCursor: null,
      });
      expect(store.entries().map((e) => e.id)).toEqual([9]);
    });

    it('drops the cursor, the matched words and a pending retry', () => {
      loadAsAccountA();
      store.loadMore();
      ctrl
        .expectOne((r) => r.url === 'https://api.test/api/entries')
        .flush({ title: 'Nope', status: 500 }, { status: 500, statusText: 'Server Error' });

      TestBed.inject(TokenStore).clear();
      TestBed.tick();
      store.retry();
      store.loadMore();

      expect(store.nextCursor()).toBeNull();
      expect(store.matchedWords()).toEqual([]);
      expect(store.error()).toBeNull();
      ctrl.expectNone((r) => r.url === 'https://api.test/api/entries');
    });

    it('does not lay the previous account\'s in-flight patch over the next account\'s row', () => {
      loadAsAccountA();
      store.setState(1, { isFavorite: true });

      TestBed.inject(TokenStore).set('account-b.jwt');
      TestBed.tick();
      store.load({ view: 'all' } as EntryQuery);
      ctrl.expectOne((r) => r.url === 'https://api.test/api/entries').flush({
        entries: [entry(1)],
        nextCursor: null,
      });

      expect(store.entries()[0].isFavorite).toBe(false);
      ctrl.match((r) => r.method === 'PATCH').forEach((r) => r.flush({}));
    });
  });
```

Use the query shape and URL matcher the existing tests in this file already use (read the first `load` test and copy its `EntryQuery` literal and `expectOne` matcher exactly; adjust the two lines above to match).

- [ ] **Step 2: Run to verify they fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/entries.store.spec.ts`
Expected: FAIL — `entries()` still holds `[1, 2]`; the cursor survives; the patch overlays row 1.

- [ ] **Step 3: Implement** — in `entries.store.ts`:

- Import `accountSignal, onIdentityChange` from `'../core/session-identity'`; drop `signal` from the `@angular/core` import if unused.
- Replace the state fields:

```ts
  private readonly rawEntries = accountSignal<EntryDto[]>([]);
  private readonly inFlightPatches = new Set<InFlightPatch>();
  readonly entries = this.rawEntries.asReadonly();
  readonly nextCursor = accountSignal<string | null>(null);
  readonly loading = accountSignal(false);
  readonly loadingMore = accountSignal(false);
  readonly error = accountSignal<Problem | null>(null);
  readonly loadedAt = accountSignal<string>('');
  readonly matchedWords = accountSignal<string[]>([]);

  private readonly query = accountSignal<EntryQuery | null>(null);
  private readonly failedOperation = accountSignal<(() => void) | null>(null);
```

  Keep the existing doc comments on `matchedWords`, `failedOperation` and `loadSeq`.
- Add a constructor:

```ts
  constructor() {
    onIdentityChange(() => this.inFlightPatches.clear());
  }
```

- Rewrite every access: `this.query = query` → `this.query.set(query)`; `this.failedOperation = X` → `this.failedOperation.set(X)`; in `retry()` read `const operation = this.failedOperation();`; in `loadMore()`:

```ts
    const cursor = this.nextCursor();
    const query = this.query();
    if (!cursor || !query || this.loading() || this.loadingMore()) return;
    …
    this.api.entries(query, cursor).subscribe({
```

- [ ] **Step 4: Run the spec** — same command. Expected: all pass (new and existing).

- [ ] **Step 5: Typecheck and commit**

```bash
docker compose exec -T frontend npm run typecheck:spec
git add frontend/src/app/reader/entries.store.ts frontend/src/app/reader/entries.store.spec.ts
git commit -m "fix(#1135): start the entry list empty for a new account"
```

---

### Task 5: `TagsStore` and `SavedSearchesStore`

**Files:**
- Modify: `frontend/src/app/reader/tags.store.ts`, `frontend/src/app/reader/saved-searches.store.ts`
- Test: `frontend/src/app/reader/tags.store.spec.ts`, `frontend/src/app/reader/saved-searches.store.spec.ts`

**Interfaces:** public APIs unchanged.

- [ ] **Step 1: Write the failing tests**

`tags.store.spec.ts` — import `TokenStore`; in `beforeEach` add `localStorage.clear();` and `TestBed.inject(TokenStore).set('account-a.jwt');` before injecting the store. Add:

```ts
  it('forgets the previous account\'s tags on identity change', () => {
    store.load();
    ctrl.expectOne('https://api.test/api/tags').flush({ tags: [tag(1, 'Private')] });

    TestBed.inject(TokenStore).clear();
    TestBed.tick();

    expect(store.tags()).toEqual([]);
    expect(store.loading()).toBe(false);
    expect(store.error()).toBeNull();
  });
```

`saved-searches.store.spec.ts` — import `TokenStore`. Its `setup(api)` configures the module; add a variant used by the new tests:

```ts
  function setupSignedIn(api: Partial<ReaderApi>): { store: SavedSearchesStore; tokens: TokenStore } {
    localStorage.clear();
    TestBed.configureTestingModule({
      providers: [SavedSearchesStore, { provide: ReaderApi, useValue: api }],
    });
    const tokens = TestBed.inject(TokenStore);
    tokens.set('account-a.jwt');
    return { store: TestBed.inject(SavedSearchesStore), tokens };
  }

  describe('when the signed-in identity changes', () => {
    it('forgets the previous account\'s saved searches and read marks', () => {
      const { store, tokens } = setupSignedIn({ savedSearches: () => of({ savedSearches: rows }) });
      store.load();
      store.markEntryRead(10);

      tokens.clear();
      TestBed.tick();

      expect(store.savedSearches()).toEqual([]);
    });

    it('lets the next account reload even though a request of the previous one never answered', () => {
      const pending = new Subject<{ savedSearches: SavedSearchWire[] }>();
      const savedSearches = jest.fn(() => pending.asObservable());
      const { store, tokens } = setupSignedIn({ savedSearches });
      store.load();

      tokens.set('account-b.jwt');
      TestBed.tick();
      store.reloadIfStale();

      expect(savedSearches).toHaveBeenCalledTimes(2);
    });
  });
```

- [ ] **Step 2: Run to verify they fail** (one at a time)

```bash
docker compose exec -T frontend npx jest src/app/reader/tags.store.spec.ts
docker compose exec -T frontend npx jest src/app/reader/saved-searches.store.spec.ts
```

Expected: FAIL — tags and searches survive; `reloadIfStale` is blocked by the stale `inFlight` (called once).

- [ ] **Step 3: Implement**

`tags.store.ts`: import `accountSignal` from `'../core/session-identity'`; `tags`, `loading`, `error` become `accountSignal<TagDto[]>([])`, `accountSignal(false)`, `accountSignal<Problem | null>(null)`; drop `signal` from the import.

`saved-searches.store.ts`: import `accountSignal`; replace fields:

```ts
  private readonly loaded = accountSignal<SavedSearchWire[]>([]);
  private readonly readSinceLoad = accountSignal<ReadonlySet<number>>(new Set());
  private readonly lastLoadedAt = accountSignal(0);
  private readonly inFlight = accountSignal<Subscription | null>(null);
```

Keep the doc comments. Rewrite accesses: `this.lastLoadedAt = Date.now()` → `this.lastLoadedAt.set(Date.now())`; `this.inFlight?.unsubscribe()` → `this.inFlight()?.unsubscribe()`; `this.inFlight = null` → `this.inFlight.set(null)`; `this.inFlight = request.closed ? null : request` → `this.inFlight.set(request.closed ? null : request)`; in `reloadIfStale`: `if (this.inFlight()) return; if (!countsAreStale(this.lastLoadedAt())) return;`. Drop `signal` from the import if unused.

- [ ] **Step 4: Run both specs** — same commands. Expected: all pass.

- [ ] **Step 5: Typecheck and commit**

```bash
docker compose exec -T frontend npm run typecheck:spec
git add frontend/src/app/reader/tags.store.ts frontend/src/app/reader/tags.store.spec.ts frontend/src/app/reader/saved-searches.store.ts frontend/src/app/reader/saved-searches.store.spec.ts
git commit -m "fix(#1135): scope tags and saved searches to the signed-in account"
```

---

### Task 6: `RefreshService` and `RecommendationsService` — runs and their timers

**Files:**
- Modify: `frontend/src/app/reader/refresh.service.ts`, `frontend/src/app/reader/recommendations.service.ts`
- Test: `frontend/src/app/reader/refresh.service.spec.ts`, `frontend/src/app/reader/recommendations.service.spec.ts`

**Interfaces:** public APIs unchanged.

- [ ] **Step 1: Write the failing tests**

`refresh.service.spec.ts` — import `TokenStore`; in `beforeEach` add `localStorage.clear();` and `TestBed.inject(TokenStore).set('account-a.jwt');` before injecting the service. Add:

```ts
  describe('when the signed-in identity changes', () => {
    it('ends the previous account\'s run, so the next account can start one', () => {
      svc.run();
      ctrl.expectOne('https://api.test/api/refresh').flush(report({ status: 'partial' }));
      const abandoned = ctrl.expectOne('https://api.test/api/refresh');

      TestBed.inject(TokenStore).set('account-b.jwt');
      TestBed.tick();
      abandoned.flush(report({ status: 'partial' }));

      expect(svc.running()).toBe(false);
      expect(svc.failure()).toBeNull();
      expect(svc.progress()).toEqual({ done: 0, total: 0 });
      svc.run();
      ctrl.expectOne('https://api.test/api/refresh').flush(report({ status: 'completed', remaining: 0 }));
    });

    it('cancels a busy retry, so it never refreshes for the next account', fakeAsync(() => {
      const done = jest.fn();
      svc.run(done);
      ctrl.expectOne('https://api.test/api/refresh').flush(report({ status: 'busy' }));

      TestBed.inject(TokenStore).set('account-b.jwt');
      TestBed.tick();
      tick(60_000);

      ctrl.expectNone('https://api.test/api/refresh');
      expect(done).not.toHaveBeenCalled();
    }));
  });
```

`recommendations.service.spec.ts` — read its `beforeEach` (it provides `ToastService`, `LayoutService`, `MONOTONIC_NOW` fakes). Add `TokenStore` import and `TestBed.inject(TokenStore).set('account-a.jwt');` before the service is injected. Add, using the endpoint URLs the existing poll-loop tests in this file already use (copy them exactly):

```ts
  describe('when the signed-in identity changes', () => {
    it('forgets the previous account\'s report and failure', () => {
      svc.start();
      ctrl.expectOne(START_URL).flush(
        report({ status: 'failed', error: 'x', forYou: { itemCount: 7, generatedAt: null, newestRunId: 3 } }),
      );

      TestBed.inject(TokenStore).clear();
      TestBed.tick();

      expect(svc.report()).toBeNull();
      expect(svc.failure()).toBeNull();
      expect(svc.forYouCount()).toBe(0);
    });

    it('never polls the previous account\'s run with the next token', fakeAsync(() => {
      svc.start();
      ctrl.expectOne(START_URL).flush(report({ status: 'running', background: true }));

      expect(svc.running()).toBe(true);

      TestBed.inject(TokenStore).set('account-b.jwt');
      TestBed.tick();
      tick(120_000);

      expect(svc.running()).toBe(false);
      ctrl.expectNone(() => true);
      discardPeriodicTasks();
    }));
  });
```

`START_URL` is the URL the file's existing `start()` test expects; define it as a `const` at the top of the new `describe` if the file has none. The `background: true` report makes `onReport` use `stepLater` (the worker-owned branch) — confirm with `workerOwnsRun()` in the service and adapt the report fields if that method reads a different flag.

- [ ] **Step 2: Run to verify they fail** (one at a time)

```bash
docker compose exec -T frontend npx jest src/app/reader/refresh.service.spec.ts
docker compose exec -T frontend npx jest src/app/reader/recommendations.service.spec.ts
```

Expected: FAIL — `running()` stays true; the timers fire requests.

- [ ] **Step 3: Implement `refresh.service.ts`**

- Import `accountSignal, onIdentityChange` from `'../core/session-identity'`.
- `running`, `report`, `failure` become `accountSignal(false)`, `accountSignal<RefreshReport | null>(null)`, `accountSignal<RefreshFailure | null>(null)`. `slice` and `previousRemaining` stay (per run, reset by `run()`).
- Add `private busyRetry: ReturnType<typeof setTimeout> | null = null;` and a constructor:

```ts
  constructor() {
    onIdentityChange(() => this.cancelBusyRetry());
  }
```

- `backOffWhileBusy` keeps the handle:

```ts
    this.busyRetry = setTimeout(() => {
      this.busyRetry = null;
      this.step(busyRetries + 1, onDone, scope);
    }, BUSY_BACKOFF_MS);
```

- Add:

```ts
  private cancelBusyRetry(): void {
    if (this.busyRetry === null) return;
    clearTimeout(this.busyRetry);
    this.busyRetry = null;
  }
```

- [ ] **Step 4: Implement `recommendations.service.ts`**

- Import `accountSignal, onIdentityChange` from `'../core/session-identity'`.
- `running`, `stopping`, `report`, `failure`, `serverEtaSeconds`, `serverEtaAt`, `serverElapsedSeconds`, `serverElapsedAt`, `rateLimited` become `accountSignal(<same initial value and type parameter>)`. `frame` and `completedStamp` stay `signal` (event counters, not account data).
- Add `private pollTimer: ReturnType<typeof setTimeout> | null = null;`.
- `stepLater` becomes:

```ts
  private stepLater(attempts: PollAttempts, delayMs = BACKOFF_MS): void {
    this.pollTimer = setTimeout(() => {
      this.pollTimer = null;
      this.step(attempts);
    }, delayMs);
  }
```

- The constructor adds the side effect:

```ts
  constructor() {
    inject(DestroyRef).onDestroy(() => this.stopTicker());
    onIdentityChange(() => this.abandonRun());
  }
```

- Add:

```ts
  /** The run belongs to the account that left: its signals reset themselves,
   *  but its timers would keep polling with the next account's token. */
  private abandonRun(): void {
    if (this.pollTimer !== null) clearTimeout(this.pollTimer);
    this.pollTimer = null;
    this.stopTicker();
  }
```

- [ ] **Step 5: Run both specs** — same commands. Expected: all pass.

- [ ] **Step 6: Typecheck and commit**

```bash
docker compose exec -T frontend npm run typecheck:spec
git add frontend/src/app/reader/refresh.service.ts frontend/src/app/reader/refresh.service.spec.ts frontend/src/app/reader/recommendations.service.ts frontend/src/app/reader/recommendations.service.spec.ts
git commit -m "fix(#1135): end refresh and for-you runs with the account that started them"
```

---

### Task 7: Account caches in `core/` and a slimmer `logout()`

**Files:**
- Modify: `frontend/src/app/core/auth.service.ts`, `preferences.service.ts`, `digest.service.ts`, `ai-availability.service.ts`, `magazine-style.service.ts`
- Test: their `*.spec.ts` files and `auth.service.spec.ts`

**Interfaces:**
- Removes: `PreferencesService.reset()`, `DigestService.reset()`, `AiAvailabilityService.reset()`, `MagazineStyleService.reset()`. Before deleting, run `grep -rn "\.reset()" frontend/src/app --include='*.ts'` and confirm the only callers are `AuthService.logout()`, the services' own constructors, and specs.

- [ ] **Step 1: Write the failing tests** — for each of the four services, replace its `reset()` test(s) with an identity-change test of the same expectations. Pattern (preferences):

```ts
  it('drops the previous account\'s preference on identity change', () => {
    const tokens = TestBed.inject(TokenStore);
    tokens.set('account-a.jwt');
    const service = TestBed.inject(PreferencesService);
    service.adopt(userWith({ scrapeFallbackEnabled: true }));

    tokens.clear();
    TestBed.tick();

    expect(service.scrapeFallbackEnabled()).toBe(false);
    expect(service.saveFailed()).toBe(false);
  });
```

Use each spec's existing user factory (read the file; it builds a `CurrentUser` for `adopt`). For `MagazineStyleService` also assert `localStorage.getItem(<its key>)` is `null` and `style()` is `'boxed'` after the change, and add:

```ts
  it('keeps the cached style of the account present at start-up', () => {
    localStorage.setItem(MAGAZINE_STYLE_KEY, 'plain');
    TestBed.inject(TokenStore).set('account-a.jwt');

    const service = TestBed.inject(MagazineStyleService);
    TestBed.tick();

    expect(service.style()).toBe('plain');
  });
```

Use a valid `MagazineStyle` value other than `'boxed'` from `asMagazineStyle` (read the type) in place of `'plain'`, and the exported key constant the spec already imports.

For `DigestService` assert every field returns to its default. For `AiAvailabilityService` keep the existing identity test; delete the `reset()` test.

In `auth.service.spec.ts`: add

```ts
  it('forgets the previous account\'s user when the session expires', () => {
    tokens.set('account-a.jwt');
    svc.loadMe().subscribe();
    ctrl.expectOne('https://api.test/api/me').flush(currentUser);

    tokens.clear();
    TestBed.tick();

    expect(svc.user()).toBeNull();
  });
```

(`currentUser` = the fixture the existing `loadMe` test flushes; reuse it.) Change the three "logout resets the cached preferences / digest / drops AI availability" tests to call `TestBed.tick()` after `svc.logout()` before asserting — the reset now comes from the identity change, not from `logout()`.

- [ ] **Step 2: Run to verify they fail** (one spec at a time: `auth.service`, `preferences.service`, `digest.service`, `ai-availability.service`, `magazine-style.service`)

Expected: the new identity tests FAIL for preferences, digest, magazine style and `AuthService.user`; the `reset()` tests are gone.

- [ ] **Step 3: Implement**

- `auth.service.ts`: `readonly user = accountSignal<CurrentUser | null>(null);` (import from `./session-identity`; drop `signal` if unused). `logout()` becomes:

```ts
  logout(): void {
    this.tokens.clear();
    this.readerLocation.clearSignInReturnUrl();
    void this.router.navigate(['/login']);
  }
```

  The `preferences`, `magazineStyle`, `digest`, `ai` injections stay (used by `loadMe`).
- `preferences.service.ts`: `scrapeFallbackEnabled` and `saveFailed` become `accountSignal(false)`; delete `reset()` and its docblock.
- `digest.service.ts`: each field becomes `accountSignal(DEFAULT_…)` (keep the type parameters: `accountSignal<'daily' | 'weekly'>(DEFAULT_CADENCE)`, `accountSignal<'html' | 'text'>(DEFAULT_FORMAT)`); `saveFailed` → `accountSignal(false)`; delete `reset()`.
- `ai-availability.service.ts`: `readySignal = accountSignal(false)`, `modelSignal = accountSignal<string | null>(null)`; delete the constructor and `reset()`.
- `magazine-style.service.ts`:

```ts
  readonly style = accountSignal<MagazineStyle>('boxed');
  readonly saveFailed = accountSignal(false);

  constructor() {
    this.style.set(this.cached());
    onIdentityChange(() => localStorage.removeItem(MAGAZINE_STYLE_KEY));
  }
```

  Delete `reset()`. Move the "Cache included: … per-account" reason into a one-line comment on the `onIdentityChange` line only if the class docblock does not already say the cache is per-account.

- [ ] **Step 4: Run the five specs and the interceptor spec** (one at a time). Expected: all pass. The interceptor tests "voids per-user caches on 401" and "drops AI availability on 401" must pass unchanged.

- [ ] **Step 5: Typecheck and commit**

```bash
docker compose exec -T frontend npm run typecheck:spec
git add frontend/src/app/core
git commit -m "refactor(#1135): account caches in core reset on identity change, not in logout"
```

---

### Task 8: `MailHealthStore`, `BackupRestoreRun`, `ToastService`, `PasskeyService`

**Files:**
- Modify: `frontend/src/app/settings/admin/mail/mail-health.store.ts`, `frontend/src/app/settings/backup-restore-run.ts`, `frontend/src/app/shared/toast/toast.service.ts`, `frontend/src/app/core/passkey.service.ts`
- Test: the four sibling `*.spec.ts` files

- [ ] **Step 1: Write the failing tests** (each file: import `TokenStore`; set `'account-a.jwt'` before the service is injected — adjust `beforeEach` accordingly)

`mail-health.store.spec.ts`:

```ts
  it('forgets the failure log on identity change', () => {
    store.refresh();
    http.expectOne(ERRORS_ENDPOINT).flush({
      failures: [{ kind: 'digest', recipient: 'a@example.test', error: 'x', at: '2026-09-06T10:00:00Z' }],
    });

    TestBed.inject(TokenStore).clear();
    TestBed.tick();

    expect(store.failures()).toEqual([]);
  });
```

`backup-restore-run.spec.ts` (read its setup; it has an archive fixture and a fake `ReaderApi`): start a run that reaches `canContinue()` true or a non-null `progress()` as an existing test does, then:

```ts
    TestBed.inject(TokenStore).clear();
    TestBed.tick();

    expect(run.progress()).toBeNull();
    expect(run.canContinue()).toBe(false);
    await expect(run.continue()).rejects.toThrow('no run in progress');
```

`toast.service.spec.ts`:

```ts
  it('closes an open toast on identity change', () => {
    toast.show({ message: 'Marked 12 read', actionLabel: 'Undo', action: () => undefined });
    tick();

    TestBed.inject(TokenStore).clear();
    TestBed.tick();
    tick();

    expect(toast.visible()).toBe(false);
    expect(el()).toBeNull();
  });
```

(`tick` here is the spec's own `ApplicationRef.tick` helper.)

`passkey.service.spec.ts`: find the existing #727 identity test ("does not sweep with the previous account's handle" or similar). It must keep passing after the change; no new test needed.

- [ ] **Step 2: Run to verify they fail** (one at a time). Expected: mail-health, backup-restore-run and toast FAIL; passkey passes.

- [ ] **Step 3: Implement**

- `mail-health.store.ts`: `readonly failures = accountSignal<MailFailure[]>([]);` (import from `'../../../core/session-identity'`; drop `signal`).
- `backup-restore-run.ts`: import `onIdentityChange` from `'../core/session-identity'`; add

```ts
  constructor() {
    onIdentityChange(() => this.reset());
  }
```

- `toast.service.ts`: import `onIdentityChange` from `'../../core/session-identity'`; add

```ts
  constructor() {
    onIdentityChange(() => this.dismiss());
  }
```

- `passkey.service.ts`: `private readonly lastUserHandle = accountSignal<string | null>(null);`; delete the constructor and its comment; in `pruneStaleCredentials`:

```ts
    const handle = listing.userHandle ?? this.lastUserHandle();
    this.lastUserHandle.set(handle);
    if (handle === null) return;
    void signalAllAcceptedCredentials(listing.rpId, handle, listing.acceptedCredentialIds);
```

  Keep the doc comment on the field, but point it at `accountSignal` instead of "see constructor": `Per-account, so it must not outlive the session.`

- [ ] **Step 4: Run the four specs** (one at a time). Expected: all pass.

- [ ] **Step 5: Typecheck and commit**

```bash
docker compose exec -T frontend npm run typecheck:spec
git add frontend/src/app/settings frontend/src/app/shared/toast frontend/src/app/core/passkey.service.ts
git commit -m "fix(#1135): reset mail health, restore run, toasts and the passkey handle on identity change"
```

---

### Task 9: Move the existing resets to the new form

**Files:**
- Modify: `frontend/src/app/reader/subscriptions.store.ts`, `frontend/src/app/discover/catalog.store.ts`, `frontend/src/app/reader/entry-body.service.ts`
- Test: `subscriptions.store.spec.ts`, `catalog.store.spec.ts`, `entry-body.service.spec.ts`

**Interfaces:** public APIs unchanged; `CatalogStore.invalidate()` stays public.

- [ ] **Step 1: Run the three specs first to record the green baseline** (one at a time). Their existing identity tests are the safety net; tests that assert a *previous-account request is abandoned* now pass through the interceptor drop only when the interceptor is installed — read each such test:
  - If it uses `provideHttpClient()` without `withInterceptors([authInterceptor])`, change its provider to `provideHttpClient(withInterceptors([authInterceptor]))` and add `{ provide: Router, useValue: { events: new Subject(), navigate: jest.fn() } }` if the interceptor's dependencies are missing, so the test proves the new mechanism.
  - If it fakes `ReaderApi` with a `Subject`, replace the assertion that the late emission is ignored with the assertion that the state is empty after `TestBed.tick()` and that a new `load()` issues a request (the drop itself is covered in Task 2).

- [ ] **Step 2: Implement**

`subscriptions.store.ts`:
- Import `accountSignal` (remove `onIdentityChange` import).
- `subscriptions`, `favoritesCount`, `keptCount`, `viewedCount`, `loading`, `error`, `resolved` become `accountSignal` with their current initial values and types.
- `private readonly lastLoadedAt = accountSignal(0);` and `private readonly inFlight = accountSignal<Subscription | null>(null);`. `latestLoad` and `localEdits` stay plain.
- Delete the constructor and the private `invalidate()`.
- Rewrite accesses: `this.lastLoadedAt = Date.now()` → `.set(Date.now())`; `countsAreStale(this.lastLoadedAt)` → `countsAreStale(this.lastLoadedAt())`; `this.inFlight?.unsubscribe()` → `this.inFlight()?.unsubscribe()`; `this.inFlight = X` → `this.inFlight.set(X)`; `if (this.inFlight)` → `if (this.inFlight())`; `if (!this.resolved() || this.inFlight)` → `if (!this.resolved() || this.inFlight())`.
- Fix the `settle()` docblock: "false once a newer load has taken it over" (the logout case is now the interceptor's).

`catalog.store.ts`:
- Import `accountSignal` (remove `onIdentityChange`).
- `categories`, `loading`, `error`, `resolved` become `accountSignal` with current initial values/types.
- Delete the constructor and its comment. `invalidate()` keeps its body; its docblock drops "and whenever the signed-in identity changes".
- The `inFlight` field comment "a response issued for the previous user must never land in the next user's store" is now the interceptor's job: change it to `/** Held so that invalidate() can cancel it. */`.

`entry-body.service.ts`:
- Delete `generation`, its docblock, and both `if (generation !== this.generation) return;` guards and the `const generation` line in `fetch`.
- `clear()` becomes `this.cache.clear();` only; the constructor keeps `onIdentityChange(() => this.clear())`.

- [ ] **Step 3: Run the three specs** (one at a time). Expected: all pass. Then run the interceptor spec once more (it holds the "voids per-user caches on 401" catalog test).

- [ ] **Step 4: Typecheck and commit**

```bash
docker compose exec -T frontend npm run typecheck:spec
git add frontend/src/app/reader/subscriptions.store.ts frontend/src/app/reader/subscriptions.store.spec.ts frontend/src/app/discover/catalog.store.ts frontend/src/app/discover/catalog.store.spec.ts frontend/src/app/reader/entry-body.service.ts frontend/src/app/reader/entry-body.service.spec.ts
git commit -m "refactor(#1135): move the existing identity resets to accountSignal and the interceptor drop"
```

---

### Task 10: The guard against the next leak

**Files:**
- Create: `frontend/src/app/core/account-state.guard.spec.ts`

**Interfaces:**
- Consumes: the migrated tree (Tasks 4–9).

- [ ] **Step 1: Write the guard**

```ts
import { readFileSync, readdirSync, statSync } from 'fs';
import { join, relative } from 'path';

const APP = join(__dirname, '..');

/** Root services that hold signals without accountSignal, each with the reason:
 *  device or instance state, or account state a side effect clears. Only shrinks. */
const DECIDED_WITHOUT_ACCOUNT_SIGNAL: Record<string, string> = {
  'core/token.store.ts': 'the identity itself',
  'core/language.service.ts': 'per-device language, kept across sessions by design',
  'core/navigation-failure.ts': 'navigation health of this tab',
  'core/page-title.service.ts': 'the current page name',
  'core/reading-focus.service.ts': 'device reading preference',
  'core/version.service.ts': 'the running build, the same for every account',
  'setup/setup.service.ts': 'instance setup status',
  'theme/theme.service.ts': 'device theme',
  'theme/brightness.service.ts': 'device brightness',
  'shared/toast/toast.service.ts': 'toast visibility; the toast closes on identity change',
  'reader/reading-layout.service.ts': 'device layout preference',
  'reader/unread-filter.service.ts': 'device filter preference',
  'reader/sidebar-visibility.service.ts': 'device sidebar preference',
  'reader/pane-split.service.ts': 'device pane split',
  'reader/reader-mode.service.ts': 'open-article toggle, reset by every article load',
  'reader/audio-player.service.ts': 'player state, stopped and cleared on identity change',
  'reader/entry-body.service.ts': 'a Map of per-entry signals, cleared on identity change',
  'settings/backup-restore-run.ts': 'reset() clears every field on identity change',
};

function sourceFiles(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name);
    if (statSync(path).isDirectory()) return sourceFiles(path);
    return name.endsWith('.ts') && !name.endsWith('.spec.ts') ? [path] : [];
  });
}

function rootServicesWithPlainSignals(): string[] {
  return sourceFiles(APP)
    .filter((path) => {
      const source = readFileSync(path, 'utf8');
      return (
        source.includes("providedIn: 'root'") &&
        /[^\w.]signal[<(]/.test(source) &&
        !source.includes('accountSignal')
      );
    })
    .map((path) => relative(APP, path));
}

describe('account-scoped state', () => {
  it('every root service holding signals declares its account state or is on the list', () => {
    const undecided = rootServicesWithPlainSignals().filter(
      (path) => !(path in DECIDED_WITHOUT_ACCOUNT_SIGNAL),
    );

    expect(undecided).toEqual([]);
  });

  it('the list names no file that has since moved to accountSignal or gone', () => {
    const current = new Set(rootServicesWithPlainSignals());
    const stale = Object.keys(DECIDED_WITHOUT_ACCOUNT_SIGNAL).filter((path) => !current.has(path));

    expect(stale).toEqual([]);
  });
});
```

- [ ] **Step 2: Run it**

Run: `docker compose exec -T frontend npx jest src/app/core/account-state.guard.spec.ts`
Expected: PASS. If the first test lists a file, decide per file: per-account → migrate it (a new task-sized change: stop and report it); device/instance, or cleared by a side effect → add it with a reason. If the second test lists a file, remove it from the list.

- [ ] **Step 3: Verify the guard by breaking what it guards**

Temporarily change `tags` in `reader/tags.store.ts` back to `signal<TagDto[]>([])` and remove the `accountSignal` import usage so the file no longer contains `accountSignal`. Run the guard: expected FAIL listing `reader/tags.store.ts`. Restore the file with the Edit tool (not `git checkout --`), run the guard again: PASS.

- [ ] **Step 4: Typecheck and commit**

```bash
docker compose exec -T frontend npm run typecheck:spec
git add frontend/src/app/core/account-state.guard.spec.ts
git commit -m "test(#1135): fail on a root service holding signals without an account decision"
```

---

### Task 11: Remaining checks and the full gate

**Files:** none expected; fix only what these steps find.

- [ ] **Step 1: `firstValueFrom` callers after a dropped response.** Run `grep -rn "firstValueFrom" frontend/src/app --include='*.ts' | grep -v spec`. For each API call, confirm one of: (a) it is sent without a token (sign-in, registration, altcha) so it is never dropped; or (b) its caller is a component under a route that is destroyed at sign-out, so the `EmptyError` reaches no screen (backup section, passkey enrolment in settings). Expected: every caller is (a) or (b). Record the result in the PR description; change code only if a caller is neither.

- [ ] **Step 2: Stale-comment sweep.** `grep -rn "logout\|#263\|previous user\|previous account" frontend/src/app --include='*.ts' | grep -v spec` and fix any comment the change made wrong (e.g. `core/session-identity.ts` docblock: add that per-account *values* use `accountSignal` and `onIdentityChange` is for side effects — one line).

- [ ] **Step 3: Full gate**

```bash
docker compose exec -T frontend npm run check
```

Expected: exit 0. Fix lint/Prettier findings in the files this branch touched.

- [ ] **Step 4: Real render.** With the Docker stack up, sign in as account A in the in-app browser (the user types the passwords), open a list with rows, log out, sign in as account B, and confirm the list shows the skeleton and then only B's rows. Do not type passwords yourself — ask the user to do the sign-ins.

- [ ] **Step 5: Commit any fixes**

```bash
git add -A frontend/src
git commit -m "chore(#1135): tidy comments after the account-state migration"
```

(Skip if Steps 1–3 changed nothing.)
