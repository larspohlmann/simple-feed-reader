# HTTP only through API files — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans (inline). Steps use checkbox (`- [ ]`) syntax.

**Goal:** No component or store injects `HttpClient`, and the `no-restricted-imports` rule from #1297 becomes `error` (#1300).

**Architecture:** The six auth components' endpoints move into one `AuthApi` (`auth/auth-api.ts`), and the mail-failure fetch moves into `MailApi` (`settings/admin/mail/mail-api.ts`). Components keep their own flow, error mapping and async style; only the transport moves. The existing component specs drive the endpoints through `HttpTestingController`, so they keep covering the same behaviour and need no new API spec.

**Out of scope (lean):**
- the four `core/http-*-writer.ts` files (the seam is real and each file is 10 lines)
- the `${this.base}/api/` prefix repeated 120 times
- problem-type constants

A note on #1300 records why.

## Global Constraints

- Starts after #1299 has merged. Branch `refactor/1300-http-through-api-files` off `develop`. Commit format `type(#1300): summary`. First commit copies this plan to `docs/superpowers/plans/`.
- Gate: `docker compose exec -T frontend npm run check`.

---

### Task 1: AuthApi

**Files:**
- Create: `frontend/src/app/auth/auth-api.ts`
- Modify: `auth/login/login.component.ts` (~79, ~137), `auth/oauth-callback/oauth-callback.component.ts` (~53), `auth/register/register.component.ts` (~67), `auth/reset-password/reset-password.component.ts` (~63), `auth/reset-request/reset-request.component.ts` (~64), `auth/verify-email/verify-email.component.ts` (~28)

- [ ] **Step 1: Create `auth/auth-api.ts`**

```ts
import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { API_BASE_URL } from '../core/api';

export interface Registration {
  readonly email: string;
  readonly password: string;
  readonly altcha: string;
  readonly locale: string;
}

@Injectable({ providedIn: 'root' })
export class AuthApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  oauthProviders(): Observable<string[]> {
    return this.http
      .get<{ providers: string[] | null }>(`${this.base}/api/auth/oauth/providers`)
      .pipe(map((response) => response.providers ?? []));
  }

  oauthStartUrl(provider: string): string {
    return `${this.base}/api/auth/oauth/${provider}`;
  }

  /** Credentialed: the flow cookie is the other half of the one-time code. */
  exchangeOAuthCode(code: string): Observable<string> {
    return this.http
      .post<{ token: string }>(`${this.base}/api/auth/oauth/exchange`, { code }, { withCredentials: true })
      .pipe(map((response) => response.token));
  }

  register(registration: Registration): Observable<string> {
    return this.http
      .post<{ status: string }>(`${this.base}/api/auth/register`, registration)
      .pipe(map((response) => response.status));
  }

  requestPasswordReset(email: string, altcha: string): Observable<unknown> {
    return this.http.post(`${this.base}/api/auth/password-reset-request`, { email, altcha });
  }

  resetPassword(token: string, password: string): Observable<unknown> {
    return this.http.post(`${this.base}/api/auth/password-reset`, { token, password });
  }

  verifyEmail(token: string): Observable<unknown> {
    return this.http.post(`${this.base}/api/auth/verify-email`, { token });
  }
}
```

`solveAltcha()` returns the solution as a `string`.

- [ ] **Step 2: Rewire each component.** In each one, replace `inject(HttpClient)`/`inject(API_BASE_URL)` with `private readonly authApi = inject(AuthApi);`. Remove `HttpClient` from the `@angular/common/http` import but keep `HttpErrorResponse` where it's used. Remove `API_BASE_URL` if it's now unused.
  - login: `this.authApi.oauthProviders().subscribe({ next: (providers) => this.providers.set(providers), error: () => this.providers.set([]) });` and `return this.authApi.oauthStartUrl(provider);`
  - oauth-callback: `this.authApi.exchangeOAuthCode(code).subscribe({ next: (token) => { this.tokens.set(token); this.auth.finishSignIn(); }, error: … unchanged })`. The two-line CREDENTIALED comment moves into `AuthApi` (already done in Step 1); delete it here.
  - register: `const status = await firstValueFrom(this.authApi.register({ email, password, altcha: solution, locale: this.i18n.getActiveLang() })); this.resultStatus.set(status);`. Delete the "Tell the backend…" comment; the field name says it.
  - reset-request: `await firstValueFrom(this.authApi.requestPasswordReset(this.form.getRawValue().email, solution));`
  - reset-password: `this.authApi.resetPassword(token, this.form.getRawValue().password).subscribe({ … unchanged })`
  - verify-email: `this.authApi.verifyEmail(token).subscribe({ … unchanged })`

- [ ] **Step 3:** Run the auth specs: `docker compose exec -T frontend npx jest src/app/auth`. They should pass unchanged, because the URLs and bodies are identical. If a spec fails only because it provided a stub where HttpClient used to be, fix the spec. Never change an expected URL or body.

- [ ] **Step 4: Commit** `refactor(#1300): auth endpoints live in AuthApi`.

### Task 2: MailApi, and flip the rule

**Files:**
- Create: `frontend/src/app/settings/admin/mail/mail-api.ts`
- Modify: `settings/admin/mail/mail-health.store.ts`, `frontend/eslint.config.js`

- [ ] **Step 1: Create `mail-api.ts`.** Move the `MailFailure` interface here from the store. Re-export it from the store (`export type { MailFailure } from './mail-api';`) only if other files import it from the store; check with `grep -rn "MailFailure" frontend/src`. Otherwise update those imports.

```ts
import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { API_BASE_URL } from '../../../core/api';

export interface MailFailure {
  readonly kind: 'digest' | 'account' | 'test';
  readonly recipient: string;
  readonly error: string;
  readonly at: string;
}

@Injectable({ providedIn: 'root' })
export class MailApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  failures(): Observable<MailFailure[]> {
    return this.http
      .get<{ failures: MailFailure[] }>(`${this.base}/api/admin/mail/errors`)
      .pipe(map((response) => response.failures));
  }
}
```

- [ ] **Step 2: Store.** Inject `MailApi` and use `this.mailApi.failures().pipe(finalize(…)).subscribe({ next: (failures) => this.failures.set(failures), error: … unchanged })`. Delete `MailErrorsResponse`, `HttpClient` and `API_BASE_URL` from the store.

- [ ] **Step 3: Flip the rule.** In `frontend/eslint.config.js`, set the `no-restricted-imports` entry of the `*.component.ts`/`*.store.ts` block to `"error"`. Verify:
  - `grep -rln "HttpClient\b" frontend/src/app --include=*.component.ts --include=*.store.ts | xargs grep -l "inject(HttpClient)"` prints nothing.
  - Break-test: add `inject(HttpClient)` to `verify-email.component.ts` and expect a lint error. Then remove it by editing.

- [ ] **Step 4:** Run the gate, commit `refactor(#1300): mail failures through MailApi; HttpClient banned in components and stores`, open the PR (`Closes #1300`) and merge when green.
