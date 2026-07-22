import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../core/api';
import { AdminApi } from './admin-api';

describe('AdminApi', () => {
  let api: AdminApi;
  let ctrl: HttpTestingController;
  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    api = TestBed.inject(AdminApi);
    ctrl = TestBed.inject(HttpTestingController);
  });
  afterEach(() => ctrl.verify());

  it('lists all users with no status param', () => {
    api.listUsers().subscribe();
    const req = ctrl.expectOne('https://api.test/api/admin/users');
    expect(req.request.method).toBe('GET');
    req.flush({ users: [] });
  });

  it('lists users filtered by status', () => {
    api.listUsers('pending_approval').subscribe();
    const req = ctrl.expectOne(
      (r) => r.url === 'https://api.test/api/admin/users' && r.params.get('status') === 'pending_approval',
    );
    req.flush({ users: [] });
  });

  it('POSTs an approve action', () => {
    api.act(7, 'approve').subscribe();
    const req = ctrl.expectOne('https://api.test/api/admin/users/7/approve');
    expect(req.request.method).toBe('POST');
    req.flush({ status: 'active' });
  });
});
