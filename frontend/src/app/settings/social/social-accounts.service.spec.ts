import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { API_BASE_URL } from '../../core/api';
import { SocialAccount, SocialAccountsService } from './social-accounts.service';

const BASE = 'https://api.test';
const ENDPOINT = `${BASE}/api/me/social-accounts`;

function account(over: Partial<SocialAccount> = {}): SocialAccount {
  return {
    id: 1,
    network: 'bluesky',
    handle: 'alice.bsky.social',
    serviceUrl: 'https://bsky.social',
    status: 'connected',
    isDefault: true,
    credentialHint: 'abcd',
    connectedAt: '2026-10-12T08:00:00+00:00',
    ...over,
  };
}

describe('SocialAccountsService', () => {
  let service: SocialAccountsService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        SocialAccountsService,
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: BASE },
      ],
    });
    service = TestBed.inject(SocialAccountsService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  function loadAccounts(accounts: SocialAccount[]): void {
    service.load();
    http.expectOne(ENDPOINT).flush({ accounts });
  }

  it('load() GETs the accounts and sets them', () => {
    loadAccounts([account()]);

    expect(service.accounts()).toEqual([account()]);
  });

  it('leaves accounts null until the first answer', () => {
    expect(service.accounts()).toBeNull();
  });

  it('connectBluesky() POSTs the handle and app password and appends the answer', () => {
    loadAccounts([account()]);
    const added = account({ id: 2, handle: 'bob.bsky.social', isDefault: false });

    service.connectBluesky('bob.bsky.social', 'xxxx-xxxx').subscribe();

    const post = http.expectOne(`${ENDPOINT}/bluesky`);
    expect(post.request.method).toBe('POST');
    expect(post.request.body).toEqual({ handle: 'bob.bsky.social', appPassword: 'xxxx-xxxx' });
    post.flush(added);
    expect(service.accounts()).toEqual([account(), added]);
    expect(service.busy()).toBe(false);
  });

  it('connectMastodon() POSTs the instance and token', () => {
    loadAccounts([account()]);
    const added = account({ id: 3, network: 'mastodon', handle: 'alice@example.social' });

    service.connectMastodon('https://example.social', 'token').subscribe();

    const post = http.expectOne(`${ENDPOINT}/mastodon`);
    expect(post.request.body).toEqual({
      instanceUrl: 'https://example.social',
      accessToken: 'token',
    });
    post.flush(added);
    expect(service.accounts()).toEqual([account(), added]);
  });

  it('reconnect() PUTs the secret and replaces that row in place', () => {
    loadAccounts([
      account({ id: 1, status: 'needs_reconnect' }),
      account({ id: 2, isDefault: false }),
    ]);
    const healed = account({ id: 1, status: 'connected' });

    service.reconnect(1, 'new-secret').subscribe();

    const put = http.expectOne(`${ENDPOINT}/1/credential`);
    expect(put.request.method).toBe('PUT');
    expect(put.request.body).toEqual({ secret: 'new-secret' });
    put.flush(healed);
    expect(service.accounts()).toEqual([healed, account({ id: 2, isDefault: false })]);
  });

  it('makeDefault() PUTs and replaces the list', () => {
    loadAccounts([account({ id: 1 }), account({ id: 2, isDefault: false })]);
    const swapped = [account({ id: 1, isDefault: false }), account({ id: 2, isDefault: true })];

    service.makeDefault(2);

    const put = http.expectOne(`${ENDPOINT}/2/default`);
    expect(put.request.method).toBe('PUT');
    put.flush({ accounts: swapped });
    expect(service.accounts()).toEqual(swapped);
  });

  it('disconnect() DELETEs and replaces the list', () => {
    loadAccounts([account({ id: 1 }), account({ id: 2, isDefault: false })]);

    service.disconnect(1);

    const del = http.expectOne(`${ENDPOINT}/1`);
    expect(del.request.method).toBe('DELETE');
    del.flush({ accounts: [account({ id: 2 })] });
    expect(service.accounts()).toEqual([account({ id: 2 })]);
  });

  it('sets failure from the problem detail', () => {
    loadAccounts([]);

    service.connectBluesky('bob', 'pw').subscribe({ error: () => undefined });

    http.expectOne(`${ENDPOINT}/bluesky`).flush(
      {
        type: 'social_credentials_rejected',
        title: 'Refused',
        status: 422,
        detail: 'Bluesky refused these credentials.',
      },
      { status: 422, statusText: 'Unprocessable Entity' },
    );
    expect(service.failure()).toBe('Bluesky refused these credentials.');
    expect(service.busy()).toBe(false);
  });

  it('prefers the first field message of a validation problem over its generic detail', () => {
    loadAccounts([]);

    service.connectBluesky('bob', 'pw').subscribe({ error: () => undefined });

    http.expectOne(`${ENDPOINT}/bluesky`).flush(
      {
        type: 'validation_error',
        title: 'Validation failed',
        status: 422,
        detail: 'One or more fields are invalid.',
        errors: { appPassword: ['Use an app password, not your main password.'] },
      },
      { status: 422, statusText: 'Unprocessable Entity' },
    );
    expect(service.failure()).toBe('Use an app password, not your main password.');
  });

  it('clears a previous failure when the next write starts', () => {
    loadAccounts([account()]);
    service.disconnect(1);
    http.expectOne(`${ENDPOINT}/1`).flush(
      {
        type: 'not_found',
        title: 'x',
        status: 404,
        detail: 'No such social account for this account.',
      },
      { status: 404, statusText: 'Not Found' },
    );
    expect(service.failure()).not.toBeNull();

    service.makeDefault(1);

    expect(service.failure()).toBeNull();
    http.expectOne(`${ENDPOINT}/1/default`).flush({ accounts: [] });
  });
});
