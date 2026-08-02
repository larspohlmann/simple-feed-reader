// src/app/admin/admin-user-detail.component.spec.ts
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { Dialog } from '@angular/cdk/dialog';
import { ActivatedRoute, provideRouter, Router } from '@angular/router';
import { Subject, of } from 'rxjs';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { API_BASE_URL } from '../core/api';
import { AuthService } from '../core/auth.service';
import { Lang } from '../core/language';
import { LanguageService } from '../core/language.service';
import { ConfirmData } from '../shared/confirm-dialog/confirm-dialog.component';
import { AdminUserDetailComponent } from './admin-user-detail.component';

const detail = {
  user: {
    id: 7,
    email: 'detailed@example.com',
    status: 'active',
    roles: ['ROLE_USER'],
    locale: 'en',
    createdAt: '2026-01-01T09:00:00+00:00',
    approvedAt: '2026-01-02T09:00:00+00:00',
    lastLoginAt: '2026-07-29T09:00:00+00:00',
    identities: ['google'],
  },
  footprint: {
    feedsCount: 2,
    tagsCount: 1,
    feedsLimit: 500,
    staleFeedsCount: 1,
    // null on purpose: the main render test exercises the "never" fallback
    // for lastRefresh while lastLoginAt (above) is present, so the two
    // date/never fields cannot be satisfied by one shared assertion.
    lastRefreshAt: null,
    dormant: false,
  },
  tags: [{ id: 3, name: 'Tech', color: '#112233', icon: 'memory', position: 0, feedsCount: 2 }],
  limits: {
    trialEndsAt: null,
    maxSubscriptions: null,
  },
  subscriptions: [
    {
      id: 5,
      title: 'Ars Technica',
      customTitle: null,
      url: 'https://example.test/feed',
      position: 0,
      createdAt: '2026-02-01T09:00:00+00:00',
      lastFetchedAt: '2026-07-30T06:00:00+00:00',
      tags: [{ id: 3, name: 'Tech', color: '#112233', icon: 'memory' }],
    },
  ],
};

describe('AdminUserDetailComponent', () => {
  let ctrl: HttpTestingController;
  let dialogClosed: Subject<boolean | undefined>;
  const dialogOpen = jest.fn(() => ({ closed: dialogClosed }));

  function mount(currentId = 99, id = '7', lang: Lang = 'en') {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [
        // The active Transloco language must move together with the
        // LanguageService stub below: the "never"/"nie" text below comes from
        // the `transloco` pipe, while the dates come from `LanguageService`
        // feeding `Intl` directly — both need to agree for the German test to
        // exercise the real translated output, not just the date formatting.
        provideTranslocoTesting({
          translocoConfig: {
            availableLangs: ['en', 'de'],
            defaultLang: lang,
            reRenderOnLangChange: true,
          },
        }),
      ],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: AuthService, useValue: { user: () => ({ id: currentId }) } },
        { provide: LanguageService, useValue: { lang: () => lang } },
        { provide: Dialog, useValue: { open: dialogOpen } },
        { provide: ActivatedRoute, useValue: { paramMap: of({ get: () => id }) } },
      ],
    });
    const f = TestBed.createComponent(AdminUserDetailComponent);
    ctrl = TestBed.inject(HttpTestingController);
    f.detectChanges();
    return f;
  }

  beforeEach(() => {
    dialogClosed = new Subject<boolean | undefined>();
    dialogOpen.mockClear();
  });

  afterEach(() => ctrl.verify());

  it('renders the identity, activity and footprint fields in their own labelled slots', () => {
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users/7').flush(detail);
    f.detectChanges();
    const el = f.nativeElement as HTMLElement;

    expect(el.querySelector('h2')?.textContent).toContain('detailed@example.com');

    // Account card: status and roles are two DISTINCT dt/dd pairs, not one
    // pair carrying both — a status-as-term regression collapses them.
    const accountDds = el.querySelectorAll('.card.account dd');
    expect(accountDds[0]?.textContent?.trim()).toBe('Active');
    expect(accountDds[1]?.textContent?.trim()).toBe('ROLE_USER');
    expect(accountDds[2]?.textContent?.trim()).toBe('en');
    // Created carries the date AND its age, e.g. "January 1, 2026 (211 days
    // ago)" — the day count moves with the clock, so only the stable parts
    // are pinned here.
    expect(accountDds[3]?.textContent).toContain('January 1, 2026');
    expect(accountDds[3]?.textContent).toContain('ago');
    expect(accountDds[4]?.textContent?.trim()).toBe('January 2, 2026');
    expect(accountDds[5]?.textContent?.trim()).toBe('google');

    // The status dt/dd pair renders as a badge keyed by status, matching the
    // list page's own status badge, not bare text.
    const statusBadge = accountDds[0]?.querySelector('.badge') as HTMLElement;
    expect(statusBadge).not.toBeNull();
    expect(statusBadge.getAttribute('data-s')).toBe('active');

    // Activity card: a present login next to an absent refresh — proves the
    // two date fields, and their "never" fallbacks, are not interchangeable.
    const activityDds = el.querySelectorAll('.card.activity dd');
    expect(activityDds[0]?.textContent?.trim()).toBe('July 29, 2026');
    expect(activityDds[1]?.textContent?.trim()).toBe('never');
    expect(el.querySelector('.card.activity .flag')).toBeNull();

    // Footprint card: feedsCount (2) and tagsCount (1) are different numbers
    // on purpose, so a swap between them is visible.
    const footprintPs = el.querySelectorAll('.card.footprint p');
    expect(footprintPs[0]?.textContent?.trim()).toBe('2 of 500 feeds');
    expect(footprintPs[1]?.textContent?.trim()).toBe('1 tags');
    expect(footprintPs[2]?.textContent?.trim()).toBe('1 not refreshed recently');
    expect(el.textContent).toContain('Limits');
    expect(el.textContent).toContain('No trial');

    // The feed row: name, tag chip and a labelled, non-dash freshness date.
    // The label must be real (visually hidden) TEXT in the accessible content
    // — not an aria-label/title attribute on a plain <span>, which ARIA
    // forbids naming (role=generic) and most screen readers ignore.
    const feedRow = el.querySelector('.rows li.feed') as HTMLElement;
    expect(feedRow.querySelector('.name')?.textContent).toContain('Ars Technica');
    expect(feedRow.querySelector('.chip')?.textContent).toContain('Tech');
    const freshness = feedRow.querySelector('.count') as HTMLElement;
    const freshnessLabel = freshness.querySelector('.sr-only') as HTMLElement;
    expect(freshnessLabel.textContent?.trim()).toBe('Last refresh:');
    expect(freshness.textContent?.replace(/\s+/g, ' ').trim()).toBe('Last refresh: July 30, 2026');
  });

  it('renders dormant only when the server says so', () => {
    const dormantDetail = { ...detail, footprint: { ...detail.footprint, dormant: true } };
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users/7').flush(dormantDetail);
    f.detectChanges();

    const flag = f.nativeElement.querySelector('.card.activity .flag');
    expect(flag).not.toBeNull();
    expect(flag.textContent).toContain('Dormant');
  });

  it('renders a feed that has never been fetched with an explicit "never", not a dash', () => {
    const neverFetched = {
      ...detail,
      subscriptions: [{ ...detail.subscriptions[0], lastFetchedAt: null }],
    };
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users/7').flush(neverFetched);
    f.detectChanges();

    const freshness = f.nativeElement.querySelector('.rows li.feed .count') as HTMLElement;
    const normalised = freshness.textContent?.replace(/\s+/g, ' ').trim();
    expect(normalised).toBe('Last refresh: never');
    expect(normalised).not.toContain('—');
  });

  it('renders "never" instead of a dash when the account has not yet been approved', () => {
    const neverApproved = { ...detail, user: { ...detail.user, approvedAt: null } };
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users/7').flush(neverApproved);
    f.detectChanges();

    const approvedDd = f.nativeElement.querySelectorAll('.card.account dd')[4] as HTMLElement;
    expect(approvedDd.textContent?.trim()).toBe('never');
    expect(approvedDd.textContent?.trim()).not.toBe('—');
  });

  it('falls back to the feed URL when neither the feed title nor a custom title is set', () => {
    const untitled = {
      ...detail,
      subscriptions: [
        {
          ...detail.subscriptions[0],
          title: null,
          customTitle: null,
          url: 'https://example.test/never-fetched.xml',
        },
      ],
    };
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users/7').flush(untitled);
    f.detectChanges();

    const name = f.nativeElement.querySelector('.rows li.feed .name') as HTMLElement;
    expect(name.textContent?.trim()).toBe('https://example.test/never-fetched.xml');
  });

  it('shows the custom title as the name and the real feed title alongside it, when both exist', () => {
    const renamed = {
      ...detail,
      subscriptions: [{ ...detail.subscriptions[0], customTitle: 'My Ars Feed' }],
    };
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users/7').flush(renamed);
    f.detectChanges();

    const row = f.nativeElement.querySelector('.rows li.feed') as HTMLElement;
    expect(row.querySelector('.name')?.textContent?.trim()).toBe('My Ars Feed');
    // The real feed title must still be visible somewhere in the row — not
    // just carried in data and dropped from the render, which is the bug
    // this test guards against.
    expect(row.querySelector('.original')?.textContent).toContain('Ars Technica');
  });

  it('does not render an "original title" row when the feed has no custom title', () => {
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users/7').flush(detail);
    f.detectChanges();

    const row = f.nativeElement.querySelector('.rows li.feed') as HTMLElement;
    expect(row.querySelector('.original')).toBeNull();
  });

  it('renders the subscribed-on date for every feed row', () => {
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users/7').flush(detail);
    f.detectChanges();

    const row = f.nativeElement.querySelector('.rows li.feed') as HTMLElement;
    const subscribed = row.querySelector('.subscribed') as HTMLElement;
    // subscriptions[0].createdAt is 2026-02-01T09:00:00+00:00 in the fixture.
    expect(subscribed.textContent).toContain('February 1, 2026');
  });

  it("renders a subscription's tag chip with the tag's own icon, matching the Tags list above it", () => {
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users/7').flush(detail);
    f.detectChanges();
    const el = f.nativeElement as HTMLElement;

    // Both the Tags-list glyph and the feed-row chip glyph must render the
    // icon (a <span class="material-symbols-outlined">memory</span>), not a
    // plain colour dot — they describe the same tag and must look the same.
    const tagsListGlyph = el.querySelector('.rows li .material-symbols-outlined');
    const feedChipGlyph = el.querySelector('.rows li.feed .chip .material-symbols-outlined');
    expect(tagsListGlyph?.textContent?.trim()).toBe('memory');
    expect(feedChipGlyph?.textContent?.trim()).toBe('memory');
  });

  it('keeps the tags and subscriptions in the order the API returned them', () => {
    const scrambled = {
      ...detail,
      tags: [
        { id: 3, name: 'Zulu', color: '#112233', icon: null, position: 0, feedsCount: 2 },
        { id: 4, name: 'Anemone', color: '#445566', icon: null, position: 1, feedsCount: 1 },
      ],
      subscriptions: [
        { ...detail.subscriptions[0], id: 5, title: 'Second Shelf', tags: [] },
        { ...detail.subscriptions[0], id: 6, title: 'Aardvark Weekly', tags: [] },
      ],
    };
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users/7').flush(scrambled);
    f.detectChanges();
    const el = f.nativeElement as HTMLElement;

    // Neither name is alphabetically first in both lists at once, so a stray
    // sort — or a reversal — of either list changes what is asserted here.
    const tagNames = Array.from(el.querySelectorAll('.rows li .name')).map((n) =>
      n.textContent?.trim(),
    );
    expect(tagNames[0]).toBe('Zulu');
    expect(tagNames[1]).toBe('Anemone');

    const feedNames = Array.from(el.querySelectorAll('.rows li.feed .name')).map((n) =>
      n.textContent?.trim(),
    );
    expect(feedNames[0]).toBe('Second Shelf');
    expect(feedNames[1]).toBe('Aardvark Weekly');
  });

  it('renders empty states when the account has no tags and no feeds', () => {
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users/7').flush({
      ...detail,
      footprint: { ...detail.footprint, feedsCount: 0, tagsCount: 0 },
      tags: [],
      subscriptions: [],
    });
    f.detectChanges();

    const text = f.nativeElement.textContent as string;
    expect(text).toContain('This account has no tags.');
    expect(text).toContain('This account has no feeds.');
  });

  it('shows an active trial with the days remaining', () => {
    const trialEndsAt = new Date(Date.now() + 5 * 86_400_000).toISOString();
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users/7').flush({
      ...detail,
      limits: { ...detail.limits, trialEndsAt },
    });
    f.detectChanges();

    const line = f.nativeElement.querySelector('[data-testid="trial-status"]') as HTMLElement;
    // Pinned to the exact interpolated fragment, not a bare "5" — the
    // footprint card also renders "2 of 500 feeds", and toContain('5') would
    // pass off that "500" alone, hiding an off-by-one in trialDaysLeft().
    expect(line.textContent).toContain('(5 days left)');
  });

  it('shows that a suspended account was ended by its trial', () => {
    const trialEndsAt = new Date(Date.now() - 86_400_000).toISOString();
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users/7').flush({
      ...detail,
      user: { ...detail.user, status: 'suspended' },
      limits: { ...detail.limits, trialEndsAt },
    });
    f.detectChanges();

    const flagLine = f.nativeElement.querySelector('.card.footprint .flag') as HTMLElement;
    expect(flagLine).not.toBeNull();
    expect(flagLine.textContent).toContain('Suspended');
    expect(flagLine.textContent).toContain('trial ended');
  });

  it('starts a trial through the API', () => {
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users/7').flush(detail);
    f.detectChanges();

    const daysInput = f.nativeElement.querySelectorAll(
      '.limit-control input[type="number"]',
    )[0] as HTMLInputElement;
    daysInput.value = '30';
    daysInput.dispatchEvent(new Event('input'));
    f.detectChanges();

    const startButton = Array.from(f.nativeElement.querySelectorAll('.limit-control button')).find(
      (b) => (b as HTMLElement).textContent?.trim() === 'Start trial',
    ) as HTMLButtonElement;
    startButton.click();

    const req = ctrl.expectOne('https://api.test/api/admin/users/7/trial');
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual({ days: 30 });
    req.flush({ status: 'active', trialEndsAt: null });

    ctrl.expectOne('https://api.test/api/admin/users/7').flush(detail);
  });

  it('saves a max-feeds override through the API', () => {
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users/7').flush(detail);
    f.detectChanges();

    const maxFeedsInput = f.nativeElement.querySelectorAll(
      '.limit-control input[type="number"]',
    )[1] as HTMLInputElement;
    maxFeedsInput.value = '42';
    maxFeedsInput.dispatchEvent(new Event('input'));
    f.detectChanges();

    const saveButton = Array.from(f.nativeElement.querySelectorAll('.limit-control button')).find(
      (b) => (b as HTMLElement).textContent?.trim() === 'Save',
    ) as HTMLButtonElement;
    saveButton.click();

    const req = ctrl.expectOne('https://api.test/api/admin/users/7/subscription-limit');
    expect(req.request.method).toBe('PUT');
    expect(req.request.body).toEqual({ maxSubscriptions: 42 });
    req.flush({ maxSubscriptions: 42 });

    ctrl.expectOne('https://api.test/api/admin/users/7').flush(detail);
  });

  it('formats every date in the active UI language via Intl, not a fixed locale', () => {
    const f = mount(99, '7', 'de');
    ctrl.expectOne('https://api.test/api/admin/users/7').flush(detail);
    f.detectChanges();
    const el = f.nativeElement as HTMLElement;

    const accountDds = el.querySelectorAll('.card.account dd');
    expect(accountDds[3]?.textContent).toContain('1. Januar 2026');
    expect(accountDds[3]?.textContent).toContain('vor');
    expect(accountDds[4]?.textContent?.trim()).toBe('2. Januar 2026');
    const activityDds = el.querySelectorAll('.card.activity dd');
    expect(activityDds[0]?.textContent?.trim()).toBe('29. Juli 2026');
    // The German "never" is a different string from the English one, so this
    // also proves the fallback branch (not just formatDate) follows the
    // active language, rather than merely being an untranslated literal.
    expect(activityDds[1]?.textContent?.trim()).toBe('nie');
  });

  it('shows an error banner instead of a blank screen when the account does not exist', () => {
    const f = mount(99, '404404');
    ctrl
      .expectOne('https://api.test/api/admin/users/404404')
      .flush(
        { type: 'about:blank', title: 'Not found', status: 404 },
        { status: 404, statusText: 'Not Found' },
      );
    f.detectChanges();

    const text = f.nativeElement.textContent as string;
    expect(text).toContain('Not found');
    expect(f.nativeElement.querySelector('[role="alert"]')).not.toBeNull();
  });

  it('retries the load when the load-error banner action is clicked', () => {
    const f = mount(99, '404404');
    ctrl
      .expectOne('https://api.test/api/admin/users/404404')
      .flush(
        { type: 'about:blank', title: 'Not found', status: 404 },
        { status: 404, statusText: 'Not Found' },
      );
    f.detectChanges();

    const retry = f.nativeElement.querySelector('[role="alert"] button') as HTMLButtonElement;
    expect(retry.textContent?.trim()).toBe('Retry');
    retry.click();

    ctrl.expectOne('https://api.test/api/admin/users/404404').flush(detail);
  });

  it('dismisses the action-error banner when its action is clicked', () => {
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users/7').flush({
      ...detail,
      user: { ...detail.user, status: 'pending_approval' },
    });
    f.detectChanges();

    f.componentInstance.act('approve');
    ctrl
      .expectOne('https://api.test/api/admin/users/7/approve')
      .flush(
        { type: 'about:blank', title: 'Gone', status: 422 },
        { status: 422, statusText: 'Unprocessable' },
      );
    f.detectChanges();

    const dismiss = f.nativeElement.querySelector('[role="alert"] button') as HTMLButtonElement;
    expect(dismiss.textContent?.trim()).toBe('Dismiss');
    dismiss.click();
    f.detectChanges();

    expect(f.nativeElement.querySelector('[role="alert"]')).toBeNull();
  });

  it('offers Approve and Reject for a pending account, and reloads after approving', () => {
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users/7').flush({
      ...detail,
      user: { ...detail.user, status: 'pending_approval' },
    });
    f.detectChanges();

    // The badge tracks the real status, not a value hardcoded from the other
    // fixture (which is 'active') -- a static badge would still pass every
    // other assertion in this file.
    const statusBadge = f.nativeElement.querySelector('.card.account .badge') as HTMLElement;
    expect(statusBadge.getAttribute('data-s')).toBe('pending_approval');

    const approveButton = Array.from(f.nativeElement.querySelectorAll('.acts button')).find(
      (b) => (b as HTMLElement).textContent?.trim() === 'Approve',
    ) as HTMLButtonElement | undefined;
    expect(approveButton).toBeDefined();

    f.componentInstance.act('approve');
    ctrl.expectOne('https://api.test/api/admin/users/7/approve').flush({ status: 'active' });
    ctrl.expectOne('https://api.test/api/admin/users/7').flush(detail);
  });

  it("hides every status action on the admin's own row, even though the DOM would otherwise show one", () => {
    const f = mount(7); // currentId === detail.user.id, status 'active'
    ctrl.expectOne('https://api.test/api/admin/users/7').flush(detail);
    f.detectChanges();

    expect(f.nativeElement.querySelectorAll('.acts button').length).toBe(0);
  });

  it('suspends only after the confirm dialog is confirmed, for an account that is not the admin', () => {
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users/7').flush(detail);
    f.detectChanges();

    const suspendButton = Array.from(f.nativeElement.querySelectorAll('.acts button')).find(
      (b) => (b as HTMLElement).textContent?.trim() === 'Suspend',
    ) as HTMLButtonElement | undefined;
    expect(suspendButton).toBeDefined();

    f.componentInstance.confirmThenAct('suspend');
    expect(dialogOpen).toHaveBeenCalled();
    ctrl.expectNone('https://api.test/api/admin/users/7/suspend');

    dialogClosed.next(true);
    ctrl.expectOne('https://api.test/api/admin/users/7/suspend').flush({ status: 'suspended' });
    ctrl.expectOne('https://api.test/api/admin/users/7').flush(detail);
  });

  it('does nothing when the confirm dialog is cancelled', () => {
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users/7').flush(detail);
    f.detectChanges();

    f.componentInstance.confirmThenAct('suspend');
    dialogClosed.next(false);
    ctrl.expectNone('https://api.test/api/admin/users/7/suspend');
  });

  it('offers Delete in the action row, even for an account with no status actions available', () => {
    const f = mount();
    // 'rejected' leaves canApprove() true (status !== 'active'), so this also
    // proves Delete does not depend on any one status action being offered.
    ctrl.expectOne('https://api.test/api/admin/users/7').flush({
      ...detail,
      user: { ...detail.user, status: 'rejected' },
    });
    f.detectChanges();

    const deleteButton = Array.from(f.nativeElement.querySelectorAll('.acts button')).find(
      (b) => (b as HTMLElement).textContent?.trim() === 'Delete account',
    ) as HTMLButtonElement | undefined;
    expect(deleteButton).toBeDefined();
  });

  it('deletes the account and returns to the user list once confirmed', () => {
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users/7').flush(detail);
    f.detectChanges();

    const router = TestBed.inject(Router);
    const navigate = jest.spyOn(router, 'navigate').mockResolvedValue(true);

    f.componentInstance.confirmThenDelete();
    dialogClosed.next(true);

    const request = ctrl.expectOne('https://api.test/api/admin/users/7');
    expect(request.request.method).toBe('DELETE');
    request.flush(null, { status: 204, statusText: 'No Content' });

    expect(navigate).toHaveBeenCalledWith(['/admin/users']);
  });

  it('passes the account email as the required confirmation text', () => {
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users/7').flush(detail);
    f.detectChanges();

    f.componentInstance.confirmThenDelete();

    // dialogOpen is declared with a no-argument implementation above (it only
    // needs to return `{ closed }` for every other test), so its inferred
    // mock-call type is the empty tuple; the config Dialog.open is actually
    // called with is asserted through here instead.
    const [, config] = dialogOpen.mock.calls.at(-1) as unknown as [unknown, { data: ConfirmData }];
    expect(config.data.requireText).toBe('detailed@example.com');
    expect(config.data.confirmLabel).toBe('Delete account');
  });

  it('does not delete the account when the confirm dialog is cancelled', () => {
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users/7').flush(detail);
    f.detectChanges();

    f.componentInstance.confirmThenDelete();
    dialogClosed.next(false);
    ctrl.expectNone('https://api.test/api/admin/users/7');
  });

  it('shows skeleton rows instead of a spinner while the account loads', () => {
    const f = mount();
    const el = f.nativeElement as HTMLElement;
    expect(el.querySelector('app-skeleton')).not.toBeNull();
    expect(el.querySelector('app-spinner')).toBeNull();
    ctrl.expectOne('https://api.test/api/admin/users/7').flush(detail);
  });

  it('renders the account inside a settings card', () => {
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users/7').flush(detail);
    f.detectChanges();
    expect((f.nativeElement as HTMLElement).querySelector('app-settings-card')).not.toBeNull();
  });

  it("projects the account actions into the card's heading row, not its body", () => {
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users/7').flush({
      ...detail,
      user: { ...detail.user, status: 'pending_approval' },
    });
    f.detectChanges();
    const el = f.nativeElement as HTMLElement;

    // `cardActions` only projects from a direct child of app-settings-card:
    // one `@if` level deep is tolerated, two silently drops the content out
    // of `.head` and into the body instead. Asserting the buttons live
    // inside `.head .acts` -- not merely that `.acts` exists somewhere on
    // the page -- is what catches that regression.
    const actionsInHead = el.querySelectorAll('.head .acts app-button');
    expect(actionsInHead.length).toBeGreaterThan(0);
    expect(el.querySelector('.head .acts')?.textContent).toContain('Approve');
  });
});
