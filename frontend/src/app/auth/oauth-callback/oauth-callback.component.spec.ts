// src/app/auth/oauth-callback/oauth-callback.component.spec.ts
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ActivatedRoute, Router, provideRouter } from '@angular/router';
import { of } from 'rxjs';
import { API_BASE_URL } from '../../core/api';
import { TokenStore } from '../../core/token.store';
import { OAuthCallbackComponent } from './oauth-callback.component';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';

const EXCHANGE = 'https://api.test/api/auth/oauth/exchange';

function setup(params: Record<string, string | null>) {
  TestBed.configureTestingModule({
    imports: [OAuthCallbackComponent, provideTranslocoTesting()],
    providers: [
      provideHttpClient(),
      provideHttpClientTesting(),
      // A real Router, not a { navigate } stub. The blocked and error branches
      // both embed <a routerLink="/login">, so rendering them — which is the
      // only way to assert the message a user actually reads — needs a
      // configured router. navigate is spied rather than stubbed away.
      provideRouter([]),
      { provide: API_BASE_URL, useValue: 'https://api.test' },
      {
        provide: ActivatedRoute,
        useValue: { queryParamMap: of({ get: (k: string) => params[k] ?? null }) },
      },
    ],
  });
  localStorage.clear();
  const navigate = jest.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
  const f = TestBed.createComponent(OAuthCallbackComponent);
  // detectChanges runs ngOnInit and renders, so the specs below assert the
  // strings on screen rather than only the signals behind them.
  f.detectChanges();
  const text = (): string => (f.nativeElement as HTMLElement).textContent ?? '';
  return { f, text, ctrl: TestBed.inject(HttpTestingController), navigate };
}

/** Blocks the exchange the way the API does for an account that may not sign in. */
function blockWith(accountStatus: string) {
  const { f, text, ctrl } = setup({ code: 'one-time' });
  ctrl.expectOne(EXCHANGE).flush(
    {
      type: 'account_not_active',
      title: 'Account not active',
      status: 403,
      detail: 'An administrator has not approved this account yet.',
      accountStatus,
    },
    { status: 403, statusText: 'Forbidden' },
  );
  f.detectChanges();
  return text();
}

describe('OAuthCallbackComponent', () => {
  it('exchanges the code CREDENTIALED, stores the token, loads me, and navigates home', () => {
    const { ctrl, navigate } = setup({ code: 'one-time' });
    const req = ctrl.expectOne(EXCHANGE);
    expect(req.request.withCredentials).toBe(true);
    expect(req.request.body).toEqual({ code: 'one-time' });
    req.flush({ token: 'jwt-oauth' });
    expect(TestBed.inject(TokenStore).token()).toBe('jwt-oauth');
    ctrl.expectOne('https://api.test/api/me').flush({
      id: 1,
      email: 'a@b.c',
      roles: [],
      status: 'active',
      createdAt: 'x',
      locale: 'de',
    });
    expect(navigate).toHaveBeenCalledWith(['/']);
    // Proves the account's locale, not the cached one, drives the UI after login.
    expect(document.documentElement.lang).toBe('de');
    expect(localStorage.getItem('sfr.lang')).toBe('de');
  });

  it('shows the error and does not call exchange when the provider returned ?error', () => {
    const { text, ctrl } = setup({ error: 'access_denied' });
    ctrl.expectNone(EXCHANGE);
    expect(text()).toContain('Sign-in did not complete');
  });

  /**
   * The bug in #247. A first-time Google user lands in the approval queue, and
   * the API says so precisely — 403 account_not_active carrying
   * accountStatus: pending_approval. The screen used to answer "Sign-in did not
   * complete. Please try again.", which is wrong twice: nothing is retryable,
   * and the account was in fact created.
   */
  it('tells a new user their account awaits approval instead of showing a retry error', () => {
    const rendered = blockWith('pending_approval');
    expect(rendered).toContain('administrator');
    expect(rendered).toContain('approve');
    expect(rendered).not.toContain('Please try again');
  });

  it.each([
    ['pending_verification', 'Confirm your email address'],
    ['suspended', 'suspended'],
    ['rejected', 'rejected'],
    // A status this build has never heard of still gets a real sentence rather
    // than a retry prompt for something no retry can fix.
    ['some_future_status', 'cannot sign in'],
  ])('explains the blocked account when accountStatus is %s', (accountStatus, expected) => {
    const rendered = blockWith(accountStatus);
    expect(rendered).toContain(expected);
    expect(rendered).not.toContain('Please try again');
  });

  it('offers the way back to sign in from a blocked account', () => {
    const { f, ctrl } = setup({ code: 'one-time' });
    ctrl
      .expectOne(EXCHANGE)
      .flush(
        { type: 'account_not_active', title: 'x', status: 403, accountStatus: 'pending_approval' },
        { status: 403, statusText: 'Forbidden' },
      );
    f.detectChanges();
    const link = (f.nativeElement as HTMLElement).querySelector('a');
    expect(link?.getAttribute('href')).toBe('/login');
  });

  /**
   * A failure with no accountStatus is a genuine failure — a spent or expired
   * code, a missing flow cookie, a network error — and those ARE worth
   * retrying. The status-aware branch must not swallow them.
   */
  it('keeps the generic retry message for a failure that carries no account status', () => {
    const { f, text, ctrl } = setup({ code: 'one-time' });
    ctrl
      .expectOne(EXCHANGE)
      .flush(
        { type: 'invalid_token', title: 'Invalid token', status: 400 },
        { status: 400, statusText: 'Bad Request' },
      );
    f.detectChanges();
    expect(text()).toContain('Sign-in did not complete');
  });
});
