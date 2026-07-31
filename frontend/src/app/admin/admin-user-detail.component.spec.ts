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
    lastRefreshAt: '2026-07-30T06:00:00+00:00',
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
      tags: [{ id: 3, name: 'Tech', color: '#112233' }],
    },
  ],
};

describe('AdminUserDetailComponent', () => {
  let ctrl: HttpTestingController;
  let dialogClosed: Subject<boolean | undefined>;
  const dialogOpen = jest.fn(() => ({ closed: dialogClosed }));

  function mount(currentId = 99, id = '7') {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: AuthService, useValue: { user: () => ({ id: currentId }) } },
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

  it('renders the account, its footprint and both lists', () => {
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users/7').flush(detail);
    f.detectChanges();

    const text = f.nativeElement.textContent as string;
    expect(text).toContain('detailed@example.com');
    expect(text).toContain('Ars Technica');
    expect(text).toContain('Tech');
    expect(text).toContain('500');
  });

  it('renders empty states when the account has no tags and no feeds', () => {
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users/7').flush({
      ...detail,
      footprint: { ...detail.footprint, feedsCount: 0, tagsCount: 0, lastRefreshAt: null },
      tags: [],
      subscriptions: [],
    });
    f.detectChanges();

    const text = f.nativeElement.textContent as string;
    expect(text).toContain('This account has no tags.');
    expect(text).toContain('This account has no feeds.');
  });

  it('renders "never" for an account that has never signed in or been refreshed', () => {
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users/7').flush({
      ...detail,
      user: { ...detail.user, lastLoginAt: null },
      footprint: { ...detail.footprint, lastRefreshAt: null },
    });
    f.detectChanges();

    const text = f.nativeElement.textContent as string;
    expect(text).toContain('never');
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

    f.componentInstance.act('approve');
    ctrl.expectOne('https://api.test/api/admin/users/7/approve').flush({ status: 'active' });
    ctrl.expectOne('https://api.test/api/admin/users/7').flush(detail);
  });

  it('suspends only after the confirm dialog is confirmed, and hides the action on the admin’s own row', () => {
    const f = mount(7); // currentId === detail.user.id
    ctrl.expectOne('https://api.test/api/admin/users/7').flush(detail);
    f.detectChanges();

    expect(f.componentInstance.canSuspend()).toBe(false);
  });

  it('suspends after confirmation for another account', () => {
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users/7').flush(detail);
    f.detectChanges();

    f.componentInstance.confirmThenAct('suspend');
    expect(dialogOpen).toHaveBeenCalled();
    ctrl.expectNone('https://api.test/api/admin/users/7/suspend');

    dialogClosed.next(true);
    ctrl.expectOne('https://api.test/api/admin/users/7/suspend').flush({ status: 'suspended' });
    ctrl.expectOne('https://api.test/api/admin/users/7').flush(detail);
  });
});
