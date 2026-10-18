import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { API_BASE_URL } from '../../core/api';
import { SubscriptionsStore } from '../../reader/state/subscriptions.store';
import { ConfirmData } from '../../shared/confirm-dialog/confirm-dialog.component';
import { ConfirmService } from '../../shared/confirm-dialog/confirm.service';
import { CONFIRMATION_DURATION_MS, ToastService } from '../../shared/toast/toast.service';
import { SocialAccount } from './social-accounts.service';
import { SocialFeedsPicker } from '../../reader/feeds/social-picker/social-feeds-picker.service';
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
    sync: { checkedAt: null, skipped: null, changes: [] },
    ...over,
  };
}

describe('SocialSectionComponent', () => {
  let http: HttpTestingController;
  const toastStub = { show: jest.fn() };
  const confirmStub = {
    confirmThen: jest.fn((_data: ConfirmData, action: () => void) => action()),
  };

  const pickerStub = { open: jest.fn(() => of(undefined)) };

  function mount(accounts: SocialAccount[]): ComponentFixture<SocialSectionComponent> {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [SocialSectionComponent, provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: BASE },
        { provide: SubscriptionsStore, useValue: { load: jest.fn() } },
        { provide: ToastService, useValue: toastStub },
        { provide: ConfirmService, useValue: confirmStub },
        { provide: SocialFeedsPicker, useValue: pickerStub },
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
    pickerStub.open.mockClear();
  });

  afterEach(() => http.verify());

  it('offers "Manage feeds…" per account in the import section, disabled when reconnecting is needed', () => {
    const fixture = mount([
      account(),
      account({ id: 2, handle: 'bob.bsky.social', isDefault: false, status: 'needs_reconnect' }),
    ]);

    const section = byTestId(fixture, 'social-import-section') as HTMLElement;
    const button = (id: number) =>
      section.querySelector(`[data-testid="social-import-${id}"] button`) as HTMLButtonElement;
    expect(button(1).disabled).toBe(false);
    expect(button(1).textContent).toContain('Manage feeds…');
    expect(button(2).disabled).toBe(true);
    const row = (id: number) => button(id).closest('app-settings-row')?.textContent ?? '';
    expect(row(1)).toContain('alice.bsky.social');
    expect(row(1)).toContain('Bluesky');
    expect(row(1)).not.toContain('Needs reconnecting');
    expect(row(2)).toContain('Needs reconnecting');
  });

  it('keeps the import out of the account rows', () => {
    const fixture = mount([account()]);

    const accountRow = byTestId(fixture, 'social-connected-1')?.closest('app-settings-row');
    expect(accountRow?.querySelector('[data-testid="social-import-1"]')).toBeNull();
  });

  describe('last check and changes', () => {
    const fiveMinutesAgo = (): string => new Date(Date.now() - 5 * 60_000).toISOString();
    const description = (fixture: ComponentFixture<SocialSectionComponent>): string =>
      byTestId(fixture, 'social-import-1')?.closest('.row-desc')?.textContent ??
      byTestId(fixture, 'social-import-1')?.closest('app-settings-row')?.querySelector('.row-desc')
        ?.textContent ??
      '';

    it('says when the lists and feeds were last checked and lists the changes', () => {
      const at = fiveMinutesAgo();
      const fixture = mount([
        account({
          sync: {
            checkedAt: at,
            skipped: null,
            changes: [{ kind: 'list', name: 'News', reason: 'saved', at }],
          },
        }),
      ]);

      expect(description(fixture)).toContain('Lists and feeds last checked');
      expect(byTestId(fixture, 'social-sync-changes-1')?.textContent).toContain(
        'Added News (newly saved)',
      );
    });

    it('names the skip reason', () => {
      const fixture = mount([
        account({ sync: { checkedAt: fiveMinutesAgo(), skipped: 'unreachable', changes: [] } }),
      ]);

      expect(description(fixture)).toContain('Bluesky did not answer');
      expect(byTestId(fixture, 'social-sync-changes-1')).toBeNull();
    });

    it('says nothing was changed when the answer was incomplete', () => {
      const fixture = mount([
        account({ sync: { checkedAt: fiveMinutesAgo(), skipped: 'incomplete', changes: [] } }),
      ]);

      expect(description(fixture)).toContain('so nothing was changed');
    });

    it('says the account needs reconnecting when the check was skipped for it', () => {
      const fixture = mount([
        account({ sync: { checkedAt: fiveMinutesAgo(), skipped: 'needs_reconnect', changes: [] } }),
      ]);

      expect(description(fixture)).toContain('the account needs reconnecting');
    });

    it.each([
      ['saved', 'Added News (newly saved)'],
      ['created', 'Added News (new list)'],
      ['unsaved', 'Removed News (no longer saved)'],
      ['deleted', 'Removed News (deleted on the network)'],
    ] as const)('words the %s change', (reason, expected) => {
      const at = fiveMinutesAgo();
      const fixture = mount([
        account({
          sync: {
            checkedAt: at,
            skipped: null,
            changes: [{ kind: 'list', name: 'News', reason, at }],
          },
        }),
      ]);

      expect(byTestId(fixture, 'social-sync-changes-1')?.textContent).toContain(expected);
    });

    it('lists two changes with the same name and second', () => {
      const at = fiveMinutesAgo();
      const fixture = mount([
        account({
          sync: {
            checkedAt: at,
            skipped: null,
            changes: [
              { kind: 'list', name: 'Tech', reason: 'deleted', at },
              { kind: 'list', name: 'Tech', reason: 'created', at },
            ],
          },
        }),
      ]);

      expect(byTestId(fixture, 'social-sync-changes-1')?.querySelectorAll('li')).toHaveLength(2);
    });

    it('says a failed check is tried again', () => {
      const fixture = mount([
        account({ sync: { checkedAt: fiveMinutesAgo(), skipped: 'failed', changes: [] } }),
      ]);

      expect(description(fixture)).toContain('the check failed');
    });

    it('says an account never checked has not been', () => {
      const fixture = mount([account()]);

      expect(description(fixture)).toContain('Not checked yet');
    });
  });

  it('explains the import in plain words, naming where the feeds appear', () => {
    const fixture = mount([account()]);

    const section = byTestId(fixture, 'social-import-section');
    expect(section?.textContent).toContain('Manage feeds');
    expect(section?.textContent).toContain('A tick means you are subscribed');
    expect(section?.textContent).toContain('under the Feeds row');
  });

  it('hides the import section while no account is connected', () => {
    const fixture = mount([]);

    expect(byTestId(fixture, 'social-import-section')).toBeNull();
    expect(root(fixture).querySelectorAll('app-settings-group')).toHaveLength(2);
  });

  it('opens the picker for the account and reloads the accounts when it closes', () => {
    const fixture = mount([account()]);

    press(fixture, 'social-import-1');

    expect(pickerStub.open).toHaveBeenCalledWith(expect.objectContaining({ id: 1 }));
    http.expectOne(ENDPOINT).flush({ accounts: [account({ status: 'needs_reconnect' })] });
    fixture.detectChanges();
    expect(byTestId(fixture, 'social-needs-reconnect-1')).not.toBeNull();
  });

  it('reloads the accounts when the picker closes without a result', () => {
    const fixture = mount([account()]);
    pickerStub.open.mockReturnValueOnce(of(undefined));

    press(fixture, 'social-import-1');

    http.expectOne(ENDPOINT).flush({ accounts: [account()] });
  });

  it('renders a Bluesky and a Mastodon group with their accounts, then the import group', () => {
    const fixture = mount([
      account(),
      account({ id: 2, network: 'mastodon', handle: 'alice@example.social', isDefault: true }),
    ]);

    const groups = root(fixture).querySelectorAll('app-settings-group');
    expect(groups).toHaveLength(3);
    expect(groups[0].textContent).toContain('alice.bsky.social');
    expect(groups[0].textContent).not.toContain('alice@example.social');
    expect(groups[1].textContent).toContain('alice@example.social');
    expect(groups[2].textContent).toContain('alice.bsky.social');
    expect(groups[2].textContent).toContain('alice@example.social');
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

  it('renders a needs_reconnect account with a warning chip, a secret field and a reconnect button', () => {
    const fixture = mount([account({ status: 'needs_reconnect' })]);

    expect(
      byTestId(fixture, 'social-needs-reconnect-1')?.querySelector('app-icon')?.textContent,
    ).toContain('warning');
    expect(byTestId(fixture, 'social-connected-1')).toBeNull();
    expect(byTestId<HTMLInputElement>(fixture, 'social-reconnect-secret-1')?.type).toBe('password');
    expect(byTestId(fixture, 'social-reconnect-1')?.textContent).toContain('Reconnect');
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
    press(fixture, 'social-bluesky-add');
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

  it('shows a positive Connected chip with a check icon on a connected account', () => {
    const fixture = mount([account()]);

    const chip = byTestId(fixture, 'social-connected-1');
    expect(chip?.textContent).toContain('Connected');
    expect(chip?.querySelector('app-icon')?.textContent).toContain('check_circle');
    expect(byTestId(fixture, 'social-needs-reconnect-1')).toBeNull();
  });

  it('shows the warning chip instead of the Connected chip when reconnecting is needed', () => {
    const fixture = mount([account({ status: 'needs_reconnect' })]);

    expect(byTestId(fixture, 'social-connected-1')).toBeNull();
    expect(byTestId(fixture, 'social-needs-reconnect-1')).not.toBeNull();
  });

  it('shows since when the account was connected', () => {
    const fixture = mount([account({ connectedAt: '2026-10-12T08:00:00+00:00' })]);

    expect(
      byTestId(fixture, 'social-connected-1')?.closest('app-settings-row')?.textContent,
    ).toContain('Connected since October 12, 2026');
  });

  it('collapses the connect form behind a button once the network has an account', () => {
    const fixture = mount([account()]);

    expect(byTestId(fixture, 'social-bluesky-handle')).toBeNull();
    expect(byTestId(fixture, 'social-bluesky-connect')).toBeNull();
    expect(byTestId(fixture, 'social-bluesky-link')).toBeNull();
    expect(byTestId(fixture, 'social-bluesky-add')?.textContent).toContain(
      'Connect another account',
    );
    expect(byTestId(fixture, 'social-mastodon-instance')).not.toBeNull();
    expect(byTestId(fixture, 'social-mastodon-add')).toBeNull();
  });

  it('shows the form directly when the network has no account', () => {
    const fixture = mount([]);

    expect(byTestId(fixture, 'social-bluesky-handle')).not.toBeNull();
    expect(byTestId(fixture, 'social-bluesky-add')).toBeNull();
  });

  it('expands the form on click and collapses it again after a successful connect', () => {
    const fixture = mount([account()]);

    press(fixture, 'social-bluesky-add');
    expect(byTestId(fixture, 'social-bluesky-handle')).not.toBeNull();
    expect(byTestId(fixture, 'social-bluesky-add')).toBeNull();

    type(fixture, 'social-bluesky-handle', 'bob.bsky.social');
    type(fixture, 'social-bluesky-password', 'abcd-efgh-ijkl-mnop');
    press(fixture, 'social-bluesky-connect');
    http.expectOne(`${ENDPOINT}/bluesky`).flush(account({ id: 2, handle: 'bob.bsky.social' }));
    fixture.detectChanges();

    expect(byTestId(fixture, 'social-bluesky-handle')).toBeNull();
    expect(byTestId(fixture, 'social-bluesky-add')).not.toBeNull();
  });

  it('cancels an opened Bluesky form: clears what was typed and collapses it', () => {
    const fixture = mount([account()]);
    press(fixture, 'social-bluesky-add');
    type(fixture, 'social-bluesky-handle', 'bob.bsky.social');
    type(fixture, 'social-bluesky-password', 'abcd-efgh-ijkl-mnop');

    expect(byTestId(fixture, 'social-bluesky-cancel')?.textContent).toContain('Cancel');
    press(fixture, 'social-bluesky-cancel');

    expect(byTestId(fixture, 'social-bluesky-handle')).toBeNull();
    press(fixture, 'social-bluesky-add');
    expect(byTestId<HTMLInputElement>(fixture, 'social-bluesky-handle')?.value).toBe('');
    expect(byTestId<HTMLInputElement>(fixture, 'social-bluesky-password')?.value).toBe('');
    http.expectNone(`${ENDPOINT}/bluesky`);
  });

  it('cancels an opened Mastodon form: clears what was typed and collapses it', () => {
    const fixture = mount([account({ network: 'mastodon', handle: 'alice@example.social' })]);
    press(fixture, 'social-mastodon-add');
    type(fixture, 'social-mastodon-instance', 'example.social');
    type(fixture, 'social-mastodon-token', 'the-token');

    press(fixture, 'social-mastodon-cancel');

    expect(byTestId(fixture, 'social-mastodon-instance')).toBeNull();
    press(fixture, 'social-mastodon-add');
    expect(byTestId<HTMLInputElement>(fixture, 'social-mastodon-instance')?.value).toBe('');
    expect(byTestId<HTMLInputElement>(fixture, 'social-mastodon-token')?.value).toBe('');
  });

  it('offers no cancel on the form a network without accounts shows directly', () => {
    const fixture = mount([]);

    expect(byTestId(fixture, 'social-bluesky-cancel')).toBeNull();
    expect(byTestId(fixture, 'social-mastodon-cancel')).toBeNull();
  });

  it('says what the Mastodon instance field wants', () => {
    const fixture = mount([]);

    const row = byTestId(fixture, 'social-mastodon-instance')?.closest('app-settings-row');
    expect(row?.textContent).toContain('address of your Mastodon server');
    expect(row?.textContent).toContain('mastodon.social');
  });

  it('keeps a non-default port in the instance link', () => {
    const fixture = mount([]);

    type(fixture, 'social-mastodon-instance', 'example.social:8443');

    const link = byTestId<HTMLAnchorElement>(fixture, 'social-mastodon-link');
    expect(link?.textContent).toContain('Open on example.social:8443');
    expect(link?.getAttribute('href')).toBe(
      'https://example.social:8443/settings/applications/new',
    );
  });

  it('forgets the typed reconnect secret of a disconnected account', () => {
    const fixture = mount([account({ status: 'needs_reconnect' })]);
    type(fixture, 'social-reconnect-secret-1', 'stale-secret');

    press(fixture, 'social-disconnect-1');
    http.expectOne(`${ENDPOINT}/1`).flush({ accounts: [] });
    fixture.detectChanges();
    type(fixture, 'social-bluesky-handle', 'alice.bsky.social');
    type(fixture, 'social-bluesky-password', 'abcd-efgh-ijkl-mnop');
    press(fixture, 'social-bluesky-connect');
    http.expectOne(`${ENDPOINT}/bluesky`).flush(account({ status: 'needs_reconnect' }));
    fixture.detectChanges();

    expect(byTestId<HTMLInputElement>(fixture, 'social-reconnect-secret-1')?.value).toBe('');
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
    press(fixture, 'social-mastodon-add');
    expect(byTestId<HTMLInputElement>(fixture, 'social-mastodon-token')?.value).toBe('');
  });

  it('shows the app-password refusal in the banner and keeps the typed password', () => {
    const fixture = mount([]);
    type(fixture, 'social-bluesky-handle', 'bob.bsky.social');
    type(fixture, 'social-bluesky-password', 'main-password');
    press(fixture, 'social-bluesky-connect');

    http.expectOne(`${ENDPOINT}/bluesky`).flush(
      problem('social_app_password_required', 422, {
        detail: 'Use an app password, not your account password.',
      }),
      { status: 422, statusText: 'Unprocessable Entity' },
    );
    fixture.detectChanges();

    expect(root(fixture).querySelector('app-error-banner')?.textContent).toContain(
      'Use an app password, not your account password.',
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
    expect(data.message).toContain('Favourites and kept posts move to Saved from Bluesky.');
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
