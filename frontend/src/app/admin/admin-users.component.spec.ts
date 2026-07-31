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
    const f = TestBed.createComponent(AdminUsersComponent);
    f.detectChanges(); // ngOnInit → initial list
    ctrl = TestBed.inject(HttpTestingController);
    return f;
  }

  beforeEach(() => {
    dialogClosed = new Subject<boolean | undefined>();
    dialogOpen.mockClear();
  });

  afterEach(() => ctrl.verify());

  it('loads all users on init and renders rows', () => {
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users').flush({ users: [user(1), user(2)] });
    f.detectChanges();
    expect((f.nativeElement as HTMLElement).textContent).toContain('u1@x');
    expect((f.nativeElement as HTMLElement).textContent).toContain('u2@x');
  });

  it('offers Approve+Reject for a pending user, and re-fetches after an action', () => {
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users').flush({ users: [user(1)] });
    f.detectChanges();
    const c = f.componentInstance;
    expect(c.canApprove(user(1))).toBe(true);
    expect(c.canReject(user(1))).toBe(true);
    expect(c.canSuspend(user(1))).toBe(false);

    c.act(user(1), 'approve');
    ctrl.expectOne('https://api.test/api/admin/users/1/approve').flush({ status: 'active' });
    // action triggers a reload of the current filter:
    ctrl.expectOne('https://api.test/api/admin/users').flush({ users: [] });
  });

  it('keeps the loaded list and shows an inline error when an action fails', () => {
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users').flush({ users: [user(1)] });
    f.detectChanges();
    const c = f.componentInstance;

    c.act(user(1), 'approve');
    ctrl
      .expectOne('https://api.test/api/admin/users/1/approve')
      .flush(
        { type: 'about:blank', title: 'Gone', status: 422 },
        { status: 422, statusText: 'Unprocessable' },
      );

    // The failure surfaces on actionError (inline), NOT on error (which would
    // replace the whole list), and the rows survive.
    expect(c.actionError()?.title).toBe('Gone');
    expect(c.error()).toBeNull();
    expect(c.users().length).toBe(1);

    f.detectChanges();
    const dismiss = f.nativeElement.querySelector('[role="alert"] button') as HTMLButtonElement;
    expect(dismiss.textContent?.trim()).toBe('Dismiss');
    dismiss.click();
    expect(c.actionError()).toBeNull();
  });

  it('retries the load when the load-error banner action is clicked', () => {
    const f = mount();
    ctrl
      .expectOne('https://api.test/api/admin/users')
      .flush(
        { type: 'about:blank', title: 'Down', status: 500 },
        { status: 500, statusText: 'Server Error' },
      );
    f.detectChanges();

    const retry = f.nativeElement.querySelector('[role="alert"] button') as HTMLButtonElement;
    expect(retry.textContent?.trim()).toBe('Retry');
    retry.click();

    ctrl.expectOne('https://api.test/api/admin/users').flush({ users: [] });
  });

  it('offers only Suspend for an active user', () => {
    const c = mount().componentInstance;
    ctrl.expectOne('https://api.test/api/admin/users').flush({ users: [] });
    const active = user(1, { status: 'active' });
    expect(c.canApprove(active)).toBe(false);
    expect(c.canSuspend(active)).toBe(true);
    expect(c.canReject(active)).toBe(false);
  });

  it('hides Reject/Suspend on the current admin’s own row', () => {
    const c = mount(1).componentInstance;
    ctrl.expectOne('https://api.test/api/admin/users').flush({ users: [] });
    const self = user(1, { status: 'active' });
    expect(c.canSuspend(self)).toBe(false);
    expect(c.canReject(user(1, { status: 'pending_approval' }))).toBe(false); // id 1 == self
  });

  it('changing the filter refetches with the status param', () => {
    const c = mount().componentInstance;
    ctrl.expectOne('https://api.test/api/admin/users').flush({ users: [] });
    c.setFilter('suspended');
    ctrl
      .expectOne(
        (r) =>
          r.url === 'https://api.test/api/admin/users' && r.params.get('status') === 'suspended',
      )
      .flush({ users: [] });
  });

  it('suspends only after the confirm dialog is confirmed', () => {
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users').flush({
      users: [user(1, { status: 'active' })],
    });
    f.detectChanges();

    f.componentInstance.confirmThenAct(user(1, { status: 'active' }), 'suspend');
    expect(dialogOpen).toHaveBeenCalled();
    ctrl.expectNone('https://api.test/api/admin/users/1/suspend');

    dialogClosed.next(true);
    ctrl.expectOne('https://api.test/api/admin/users/1/suspend').flush({});
    ctrl.expectOne('https://api.test/api/admin/users').flush({ users: [] });
  });

  it('does nothing when the confirm dialog is cancelled', () => {
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users').flush({
      users: [user(1, { status: 'active' })],
    });
    f.detectChanges();

    f.componentInstance.confirmThenAct(user(1, { status: 'active' }), 'suspend');
    dialogClosed.next(false);
    ctrl.expectNone('https://api.test/api/admin/users/1/suspend');
  });

  it('shows the footprint counts and links each row to the detail page', () => {
    const f = mount();
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
    f.detectChanges();

    // Full rendered substrings, not bare numbers: a dropped or typo'd i18n key
    // (e.g. `admin.feedsLabel` → `admin.zzzA`) must fail this test.
    const text = f.nativeElement.textContent as string;
    expect(text).toContain('12 feeds');
    expect(text).toContain('3 tags');

    const link = f.nativeElement.querySelector('a[href="/settings/admin/users/1"]');
    expect(link).not.toBeNull();
  });

  it('renders an account that never signed in as never', () => {
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users').flush({
      users: [user(1, { status: 'active', feedsCount: 0, tagsCount: 0, lastLoginAt: null })],
    });
    f.detectChanges();

    expect(f.nativeElement.textContent).toContain('never');
  });

  it('does not run adjacent counts together in the rendered text', () => {
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users').flush({
      users: [user(1, { status: 'active', feedsCount: 12, tagsCount: 3 })],
    });
    f.detectChanges();

    const text = f.nativeElement.textContent as string;
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
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users').flush({ users: [user(1)] });
    f.detectChanges();

    const link = f.nativeElement.querySelector('a[href="/settings/admin/users/1"]');
    const label = link.getAttribute('aria-label') as string;
    expect(label).toContain('u1@x');
    expect(label).toContain('View details');
  });

  it('shows skeleton rows instead of a spinner while the list loads', () => {
    const f = mount();
    const el = f.nativeElement as HTMLElement;
    expect(el.querySelector('app-skeleton')).not.toBeNull();
    expect(el.querySelector('app-spinner')).toBeNull();
    ctrl.expectOne('https://api.test/api/admin/users').flush({ users: [] });
  });

  it('renders the list inside a settings card', () => {
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users').flush({ users: [user(1)] });
    f.detectChanges();
    expect((f.nativeElement as HTMLElement).querySelector('app-settings-card')).not.toBeNull();
  });
});
