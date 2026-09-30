import { TestBed } from '@angular/core/testing';
import { Dialog } from '@angular/cdk/dialog';
import { Subject } from 'rxjs';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { API_BASE_URL } from '../core/api';
import { AuthService } from '../core/auth.service';
import { Lang } from '../core/language';
import { LanguageService } from '../core/language.service';
import { AdminUsersComponent } from './admin-users.component';
import { AdminUserDto } from './admin.models';

const user = (id: number, over: Partial<AdminUserDto> = {}): AdminUserDto => ({
  id,
  email: `u${id}@x`,
  status: 'pending_approval',
  roles: ['ROLE_USER'],
  createdAt: 'x',
  approvedAt: null,
  identities: [],
  feedsCount: 0,
  tagsCount: 0,
  lastLoginAt: null,
  trialEndsAt: null,
  maxSubscriptions: null,
  ...over,
});

describe('AdminUsersComponent', () => {
  let ctrl: HttpTestingController;
  let dialogClosed: Subject<boolean | undefined>;
  const dialogOpen = jest.fn(() => ({ closed: dialogClosed }));

  function mount(currentId = 99, lang: Lang = 'en') {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: AuthService, useValue: { user: () => ({ id: currentId }) } },
        { provide: LanguageService, useValue: { lang: () => lang } },
        { provide: Dialog, useValue: { open: dialogOpen } },
      ],
    });
    const fixture = TestBed.createComponent(AdminUsersComponent);
    fixture.detectChanges(); // ngOnInit → initial list
    ctrl = TestBed.inject(HttpTestingController);
    return fixture;
  }

  beforeEach(() => {
    dialogClosed = new Subject<boolean | undefined>();
    dialogOpen.mockClear();
  });

  afterEach(() => ctrl.verify());

  it('loads all users on init and renders rows', () => {
    const fixture = mount();
    ctrl.expectOne('https://api.test/api/admin/users').flush({ users: [user(1), user(2)] });
    fixture.detectChanges();
    expect((fixture.nativeElement as HTMLElement).textContent).toContain('u1@x');
    expect((fixture.nativeElement as HTMLElement).textContent).toContain('u2@x');
  });

  it('offers Approve+Reject for a pending user, and re-fetches after an action', () => {
    const fixture = mount();
    ctrl.expectOne('https://api.test/api/admin/users').flush({ users: [user(1)] });
    fixture.detectChanges();
    const component = fixture.componentInstance;
    expect(component.canApprove(user(1))).toBe(true);
    expect(component.canReject(user(1))).toBe(true);
    expect(component.canSuspend(user(1))).toBe(false);

    component.act(user(1), 'approve');
    ctrl.expectOne('https://api.test/api/admin/users/1/approve').flush({ status: 'active' });
    // action triggers a reload of the current filter:
    ctrl.expectOne('https://api.test/api/admin/users').flush({ users: [] });
  });

  it('keeps the loaded list and shows an inline error when an action fails', () => {
    const fixture = mount();
    ctrl.expectOne('https://api.test/api/admin/users').flush({ users: [user(1)] });
    fixture.detectChanges();
    const component = fixture.componentInstance;

    component.act(user(1), 'approve');
    ctrl
      .expectOne('https://api.test/api/admin/users/1/approve')
      .flush(
        { type: 'about:blank', title: 'Gone', status: 422 },
        { status: 422, statusText: 'Unprocessable' },
      );

    // The failure surfaces on actionError (inline), NOT on error (which would
    // replace the whole list), and the rows survive.
    expect(component.actionError()?.title).toBe('Gone');
    expect(component.error()).toBeNull();
    expect(component.users().length).toBe(1);

    fixture.detectChanges();
    const dismiss = fixture.nativeElement.querySelector(
      '[role="alert"] button',
    ) as HTMLButtonElement;
    expect(dismiss.textContent?.trim()).toBe('Dismiss');
    dismiss.click();
    expect(component.actionError()).toBeNull();
  });

  it('retries the load when the load-error banner action is clicked', () => {
    const fixture = mount();
    ctrl
      .expectOne('https://api.test/api/admin/users')
      .flush(
        { type: 'about:blank', title: 'Down', status: 500 },
        { status: 500, statusText: 'Server Error' },
      );
    fixture.detectChanges();

    const retry = fixture.nativeElement.querySelector('[role="alert"] button') as HTMLButtonElement;
    expect(retry.textContent?.trim()).toBe('Retry');
    retry.click();

    ctrl.expectOne('https://api.test/api/admin/users').flush({ users: [] });
  });

  it('offers only Suspend for an active user', () => {
    const component = mount().componentInstance;
    ctrl.expectOne('https://api.test/api/admin/users').flush({ users: [] });
    const active = user(1, { status: 'active' });
    expect(component.canApprove(active)).toBe(false);
    expect(component.canSuspend(active)).toBe(true);
    expect(component.canReject(active)).toBe(false);
  });

  it('hides Reject/Suspend on the current admin’s own row', () => {
    const component = mount(1).componentInstance;
    ctrl.expectOne('https://api.test/api/admin/users').flush({ users: [] });
    const self = user(1, { status: 'active' });
    expect(component.canSuspend(self)).toBe(false);
    expect(component.canReject(user(1, { status: 'pending_approval' }))).toBe(false); // id 1 == self
  });

  it('changing the filter refetches with the status param', () => {
    const component = mount().componentInstance;
    ctrl.expectOne('https://api.test/api/admin/users').flush({ users: [] });
    component.setFilter('suspended');
    ctrl
      .expectOne(
        (request) =>
          request.url === 'https://api.test/api/admin/users' &&
          request.params.get('status') === 'suspended',
      )
      .flush({ users: [] });
  });

  it('suspends only after the confirm dialog is confirmed', () => {
    const fixture = mount();
    ctrl.expectOne('https://api.test/api/admin/users').flush({
      users: [user(1, { status: 'active' })],
    });
    fixture.detectChanges();

    fixture.componentInstance.confirmThenAct(user(1, { status: 'active' }), 'suspend');
    expect(dialogOpen).toHaveBeenCalled();
    ctrl.expectNone('https://api.test/api/admin/users/1/suspend');

    dialogClosed.next(true);
    ctrl.expectOne('https://api.test/api/admin/users/1/suspend').flush({});
    ctrl.expectOne('https://api.test/api/admin/users').flush({ users: [] });
  });

  it('does nothing when the confirm dialog is cancelled', () => {
    const fixture = mount();
    ctrl.expectOne('https://api.test/api/admin/users').flush({
      users: [user(1, { status: 'active' })],
    });
    fixture.detectChanges();

    fixture.componentInstance.confirmThenAct(user(1, { status: 'active' }), 'suspend');
    dialogClosed.next(false);
    ctrl.expectNone('https://api.test/api/admin/users/1/suspend');
  });

  it('shows the footprint counts and links each row to the detail page', () => {
    const fixture = mount();
    ctrl.expectOne('https://api.test/api/admin/users').flush({
      users: [
        user(1, {
          status: 'active',
          feedsCount: 12,
          tagsCount: 3,
          lastLoginAt: '2026-07-29T09:00:00+00:00',
        }),
      ],
    });
    fixture.detectChanges();

    // Full rendered substrings, not bare numbers: a dropped or typo'd i18n key
    // (e.g. `admin.feedsLabel` → `admin.zzzA`) must fail this test.
    const text = fixture.nativeElement.textContent as string;
    expect(text).toContain('12 feeds');
    expect(text).toContain('3 tags');

    const link = fixture.nativeElement.querySelector('a[href="/settings/admin/users/1"]');
    expect(link).not.toBeNull();
  });

  it('renders an account that never signed in as never', () => {
    const fixture = mount();
    ctrl.expectOne('https://api.test/api/admin/users').flush({
      users: [user(1, { status: 'active', feedsCount: 0, tagsCount: 0, lastLoginAt: null })],
    });
    fixture.detectChanges();

    expect(fixture.nativeElement.textContent).toContain('never');
  });

  it('does not run adjacent counts together in the rendered text', () => {
    const fixture = mount();
    ctrl.expectOne('https://api.test/api/admin/users').flush({
      users: [user(1, { status: 'active', feedsCount: 12, tagsCount: 3 })],
    });
    fixture.detectChanges();

    const text = fixture.nativeElement.textContent as string;
    expect(text).not.toContain('feeds3');
  });

  it('formats the last-login date in the active UI language via Intl, not a fixed locale', () => {
    const en = mount(99, 'en');
    ctrl.expectOne('https://api.test/api/admin/users').flush({
      users: [user(1, { status: 'active', lastLoginAt: '2026-07-29T09:00:00+00:00' })],
    });
    en.detectChanges();
    const enText = en.nativeElement.textContent as string;
    expect(enText).toContain('July 29, 2026');

    const de = mount(99, 'de');
    ctrl.expectOne('https://api.test/api/admin/users').flush({
      users: [user(1, { status: 'active', lastLoginAt: '2026-07-29T09:00:00+00:00' })],
    });
    de.detectChanges();
    const deText = de.nativeElement.textContent as string;
    expect(deText).toContain('29. Juli 2026');

    // The two locales must actually render differently — this is what a static
    // LOCALE_ID (which DatePipe reads, and which this app never sets) cannot do.
    expect(enText).not.toBe(deText);
  });

  it("uses the openDetail translation as the row link's accessible name, alongside the email", () => {
    const fixture = mount();
    ctrl.expectOne('https://api.test/api/admin/users').flush({ users: [user(1)] });
    fixture.detectChanges();

    const link = fixture.nativeElement.querySelector('a[href="/settings/admin/users/1"]');
    const label = link.getAttribute('aria-label') as string;
    expect(label).toContain('u1@x');
    expect(label).toContain('View details');
  });

  it('shows skeleton rows instead of a spinner while the list loads', () => {
    const fixture = mount();
    const element = fixture.nativeElement as HTMLElement;
    expect(element.querySelector('app-skeleton')).not.toBeNull();
    expect(element.querySelector('app-spinner')).toBeNull();
    ctrl.expectOne('https://api.test/api/admin/users').flush({ users: [] });
  });

  it('renders the queue as a settings group with its filters in the header', () => {
    const fixture = mount();
    ctrl.expectOne('https://api.test/api/admin/users').flush({ users: [user(1)] });
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;

    expect(element.querySelector('app-settings-group')).not.toBeNull();
    expect(element.querySelector('.g-head .filters')).not.toBeNull();
  });

  it('flags a row whose trial has expired', () => {
    const fixture = mount();
    ctrl.expectOne('https://api.test/api/admin/users').flush({
      users: [
        user(1, { trialEndsAt: new Date(Date.now() - 86_400_000).toISOString() }),
        user(2, { trialEndsAt: null }),
      ],
    });
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelectorAll('.trial-expired').length).toBe(1);
  });
});
