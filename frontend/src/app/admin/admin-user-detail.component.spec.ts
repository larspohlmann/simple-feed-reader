// src/app/admin/admin-user-detail.component.spec.ts
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { Dialog } from '@angular/cdk/dialog';
import { ActivatedRoute, provideRouter } from '@angular/router';
import { Subject, of } from 'rxjs';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { API_BASE_URL } from '../core/api';
import { AuthService } from '../core/auth.service';
import { Lang } from '../core/language';
import { LanguageService } from '../core/language.service';
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
    expect(accountDds[3]?.textContent?.trim()).toBe('January 1, 2026');
    expect(accountDds[4]?.textContent?.trim()).toBe('January 2, 2026');
    expect(accountDds[5]?.textContent?.trim()).toBe('google');

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
    expect(el.textContent).toContain('No per-user limits set');

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

  it('formats every date in the active UI language via Intl, not a fixed locale', () => {
    const f = mount(99, '7', 'de');
    ctrl.expectOne('https://api.test/api/admin/users/7').flush(detail);
    f.detectChanges();
    const el = f.nativeElement as HTMLElement;

    const accountDds = el.querySelectorAll('.card.account dd');
    expect(accountDds[3]?.textContent?.trim()).toBe('1. Januar 2026');
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

  it('offers Approve and Reject for a pending account, and reloads after approving', () => {
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users/7').flush({
      ...detail,
      user: { ...detail.user, status: 'pending_approval' },
    });
    f.detectChanges();

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
});
