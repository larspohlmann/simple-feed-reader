import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { API_BASE_URL } from '../../core/api';
import { ConfirmData } from '../../shared/confirm-dialog/confirm-dialog.component';
import { ConfirmService } from '../../shared/confirm-dialog/confirm.service';
import { CONFIRMATION_DURATION_MS, ToastService } from '../../shared/toast/toast.service';
import { SocialAccount } from './social-accounts.service';
import { SocialSectionComponent } from './social-section.component';

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

describe('SocialSectionComponent', () => {
  let http: HttpTestingController;
  const toastStub = { show: jest.fn() };
  const confirmStub = {
    confirmThen: jest.fn((_data: ConfirmData, action: () => void) => action()),
  };

  function mount(accounts: SocialAccount[]): ComponentFixture<SocialSectionComponent> {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [SocialSectionComponent, provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: BASE },
        { provide: ToastService, useValue: toastStub },
        { provide: ConfirmService, useValue: confirmStub },
      ],
    });
    http = TestBed.inject(HttpTestingController);
    const fixture = TestBed.createComponent(SocialSectionComponent);
    fixture.detectChanges();
    http.expectOne(ENDPOINT).flush({ accounts });
    fixture.detectChanges();
    return fixture;
  }

  const root = (fixture: ComponentFixture<SocialSectionComponent>): HTMLElement =>
    fixture.nativeElement;

  const byTestId = <T extends HTMLElement>(
    fixture: ComponentFixture<SocialSectionComponent>,
    testId: string,
  ): T | null => root(fixture).querySelector<T>(`[data-testid="${testId}"]`);

  function type(
    fixture: ComponentFixture<SocialSectionComponent>,
    testId: string,
    value: string,
  ): void {
    const input = byTestId<HTMLInputElement>(fixture, testId) as HTMLInputElement;
    input.value = value;
    input.dispatchEvent(new Event('input'));
    fixture.detectChanges();
  }

  function press(fixture: ComponentFixture<SocialSectionComponent>, testId: string): void {
    byTestId<HTMLElement>(fixture, testId)?.querySelector('button')?.click();
    fixture.detectChanges();
  }

  const problem = (type: string, status: number, extra: object): object => ({
    type,
    title: 'x',
    status,
    ...extra,
  });

  beforeEach(() => {
    toastStub.show.mockReset();
    confirmStub.confirmThen.mockClear();
  });

  afterEach(() => http.verify());

  it('renders a Bluesky and a Mastodon group with their accounts', () => {
    const fixture = mount([
      account(),
      account({ id: 2, network: 'mastodon', handle: 'alice@example.social', isDefault: true }),
    ]);

    const groups = root(fixture).querySelectorAll('app-settings-group');
    expect(groups).toHaveLength(2);
    expect(groups[0].textContent).toContain('alice.bsky.social');
    expect(groups[0].textContent).not.toContain('alice@example.social');
    expect(groups[1].textContent).toContain('alice@example.social');
  });

  it('says so when a network has no account', () => {
    const fixture = mount([]);

    expect(byTestId(fixture, 'social-empty-bluesky')?.textContent).toContain(
      'No account connected.',
    );
  });

  it('marks the default account and offers "Make default" only on the others', () => {
    const fixture = mount([
      account(),
      account({ id: 2, handle: 'bob.bsky.social', isDefault: false }),
    ]);

    expect(byTestId(fixture, 'social-default-1')?.textContent).toContain('Default');
    expect(byTestId(fixture, 'social-make-default-1')).toBeNull();
    expect(byTestId(fixture, 'social-default-2')).toBeNull();
    expect(byTestId(fixture, 'social-make-default-2')).not.toBeNull();
  });

  it('makes an account the default through the API', () => {
    const fixture = mount([
      account(),
      account({ id: 2, handle: 'bob.bsky.social', isDefault: false }),
    ]);

    press(fixture, 'social-make-default-2');

    const put = http.expectOne(`${ENDPOINT}/2/default`);
    expect(put.request.method).toBe('PUT');
    put.flush({ accounts: [account({ isDefault: false }), account({ id: 2, isDefault: true })] });
    fixture.detectChanges();
    expect(byTestId(fixture, 'social-default-2')).not.toBeNull();
  });

  it('flags a needs_reconnect account and shows a reconnect field only for it', () => {
    const fixture = mount([
      account({ status: 'needs_reconnect' }),
      account({ id: 2, handle: 'bob' }),
    ]);

    expect(byTestId(fixture, 'social-needs-reconnect-1')?.textContent).toContain(
      'Needs reconnecting',
    );
    expect(byTestId(fixture, 'social-reconnect-secret-1')).not.toBeNull();
    expect(byTestId(fixture, 'social-needs-reconnect-2')).toBeNull();
    expect(byTestId(fixture, 'social-reconnect-secret-2')).toBeNull();
  });

  it('reconnects with the typed secret and clears the field', () => {
    const fixture = mount([account({ status: 'needs_reconnect' })]);

    type(fixture, 'social-reconnect-secret-1', 'fresh-secret');
    press(fixture, 'social-reconnect-1');

    const put = http.expectOne(`${ENDPOINT}/1/credential`);
    expect(put.request.body).toEqual({ secret: 'fresh-secret' });
    put.flush(account({ status: 'connected' }));
    fixture.detectChanges();
    expect(byTestId(fixture, 'social-reconnect-secret-1')).toBeNull();
    expect(toastStub.show).toHaveBeenCalledWith({
      message: 'Account connected',
      durationMs: CONFIRMATION_DURATION_MS,
    });
  });

  it('connects a Bluesky account with the typed values and clears the password', () => {
    const fixture = mount([]);

    type(fixture, 'social-bluesky-handle', 'bob.bsky.social');
    type(fixture, 'social-bluesky-password', 'abcd-efgh-ijkl-mnop');
    press(fixture, 'social-bluesky-connect');

    const post = http.expectOne(`${ENDPOINT}/bluesky`);
    expect(post.request.body).toEqual({
      handle: 'bob.bsky.social',
      appPassword: 'abcd-efgh-ijkl-mnop',
    });
    post.flush(account({ handle: 'bob.bsky.social' }));
    fixture.detectChanges();
    expect(byTestId<HTMLInputElement>(fixture, 'social-bluesky-password')?.value).toBe('');
    expect(byTestId<HTMLInputElement>(fixture, 'social-bluesky-handle')?.value).toBe('');
    expect(root(fixture).textContent).toContain('bob.bsky.social');
    expect(toastStub.show).toHaveBeenCalledWith({
      message: 'Account connected',
      durationMs: CONFIRMATION_DURATION_MS,
    });
  });

  it('describes how to get a Bluesky app password, with a link to the right page', () => {
    const fixture = mount([]);

    const row = byTestId(fixture, 'social-bluesky-password')?.closest('app-settings-row');
    expect(row?.textContent).toContain('Settings → Privacy and security → App passwords');
    expect(row?.textContent).toContain('xxxx-xxxx-xxxx-xxxx');
    const link = byTestId<HTMLAnchorElement>(fixture, 'social-bluesky-link');
    expect(link?.getAttribute('href')).toBe('https://bsky.app/settings/app-passwords');
    expect(link?.getAttribute('target')).toBe('_blank');
    expect(link?.getAttribute('rel')).toBe('noopener');
  });

  it('describes how to get a Mastodon token and links to the instance only once a host is typed', () => {
    const fixture = mount([]);

    const row = byTestId(fixture, 'social-mastodon-token')?.closest('app-settings-row');
    expect(row?.textContent).toContain('Preferences → Development → New application');
    expect(row?.textContent).toContain('write:favourites');
    expect(byTestId(fixture, 'social-mastodon-link')).toBeNull();

    type(fixture, 'social-mastodon-instance', 'https://example.social/');

    const link = byTestId<HTMLAnchorElement>(fixture, 'social-mastodon-link');
    expect(link?.textContent).toContain('Open on example.social');
    expect(link?.getAttribute('href')).toBe('https://example.social/settings/applications/new');
    expect(link?.getAttribute('target')).toBe('_blank');
    expect(link?.getAttribute('rel')).toBe('noopener');
  });

  it('shows no instance link for text that is not a host', () => {
    const fixture = mount([]);

    type(fixture, 'social-mastodon-instance', 'exa mple');

    expect(byTestId(fixture, 'social-mastodon-link')).toBeNull();
  });

  it('connects a Mastodon account with the typed instance and token', () => {
    const fixture = mount([]);

    type(fixture, 'social-mastodon-instance', 'https://example.social');
    type(fixture, 'social-mastodon-token', 'the-token');
    press(fixture, 'social-mastodon-connect');

    const post = http.expectOne(`${ENDPOINT}/mastodon`);
    expect(post.request.body).toEqual({
      instanceUrl: 'https://example.social',
      accessToken: 'the-token',
    });
    post.flush(account({ id: 5, network: 'mastodon', handle: 'bob@example.social' }));
    fixture.detectChanges();
    expect(byTestId<HTMLInputElement>(fixture, 'social-mastodon-token')?.value).toBe('');
  });

  it('shows the field message of a 422 in the banner and keeps the typed password', () => {
    const fixture = mount([]);
    type(fixture, 'social-bluesky-handle', 'bob.bsky.social');
    type(fixture, 'social-bluesky-password', 'main-password');
    press(fixture, 'social-bluesky-connect');

    http.expectOne(`${ENDPOINT}/bluesky`).flush(
      problem('validation_error', 422, {
        detail: 'One or more fields are invalid.',
        errors: { appPassword: ['Use an app password, not your main password.'] },
      }),
      { status: 422, statusText: 'Unprocessable Entity' },
    );
    fixture.detectChanges();

    expect(root(fixture).querySelector('app-error-banner')?.textContent).toContain(
      'Use an app password, not your main password.',
    );
    expect(byTestId<HTMLInputElement>(fixture, 'social-bluesky-password')?.value).toBe(
      'main-password',
    );
    expect(toastStub.show).not.toHaveBeenCalled();
  });

  it('disconnects only after confirmation, as a danger confirm', () => {
    const fixture = mount([account()]);

    press(fixture, 'social-disconnect-1');

    expect(confirmStub.confirmThen).toHaveBeenCalledTimes(1);
    const data = confirmStub.confirmThen.mock.calls[0][0];
    expect(data.danger).toBe(true);
    expect(data.title).toBe('Disconnect alice.bsky.social?');
    const del = http.expectOne(`${ENDPOINT}/1`);
    expect(del.request.method).toBe('DELETE');
    del.flush({ accounts: [] });
    fixture.detectChanges();
    expect(byTestId(fixture, 'social-empty-bluesky')).not.toBeNull();
  });

  it('does not delete when the confirmation is declined', () => {
    confirmStub.confirmThen.mockImplementationOnce(() => undefined);
    const fixture = mount([account()]);

    press(fixture, 'social-disconnect-1');

    http.expectNone(`${ENDPOINT}/1`);
  });
});
