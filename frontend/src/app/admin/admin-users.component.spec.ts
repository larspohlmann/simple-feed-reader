import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { API_BASE_URL } from '../core/api';
import { AuthService } from '../core/auth.service';
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
  ...over,
});

describe('AdminUsersComponent', () => {
  let ctrl: HttpTestingController;

  function mount(currentId = 99) {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: AuthService, useValue: { user: () => ({ id: currentId }) } },
      ],
    });
    const f = TestBed.createComponent(AdminUsersComponent);
    f.detectChanges(); // ngOnInit → initial list
    ctrl = TestBed.inject(HttpTestingController);
    return f;
  }

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
});
