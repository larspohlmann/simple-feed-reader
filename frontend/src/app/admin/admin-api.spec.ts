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
    const testRequest = ctrl.expectOne('https://api.test/api/admin/users');
    expect(testRequest.request.method).toBe('GET');
    testRequest.flush({ users: [] });
  });

  it('lists users filtered by status', () => {
    api.listUsers('pending_approval').subscribe();
    const testRequest = ctrl.expectOne(
      (request) =>
        request.url === 'https://api.test/api/admin/users' &&
        request.params.get('status') === 'pending_approval',
    );
    testRequest.flush({ users: [] });
  });

  it('POSTs an approve action', () => {
    api.act(7, 'approve').subscribe();
    const testRequest = ctrl.expectOne('https://api.test/api/admin/users/7/approve');
    expect(testRequest.request.method).toBe('POST');
    testRequest.flush({ status: 'active' });
  });

  it('reads one user from the detail endpoint', () => {
    let received: unknown = null;
    api.userDetail(7).subscribe((detail) => (received = detail));

    const testRequest = ctrl.expectOne('https://api.test/api/admin/users/7');
    expect(testRequest.request.method).toBe('GET');
    testRequest.flush({ user: { id: 7 }, footprint: {}, tags: [], subscriptions: [] });

    expect(received).toEqual({ user: { id: 7 }, footprint: {}, tags: [], subscriptions: [] });
  });

  it('POSTs to start a trial', () => {
    api.startTrial(7, 14).subscribe();
    const testRequest = ctrl.expectOne('https://api.test/api/admin/users/7/trial');
    expect(testRequest.request.method).toBe('POST');
    expect(testRequest.request.body).toEqual({ days: 14 });
    testRequest.flush({ status: 'active', trialEndsAt: '2026-08-01T00:00:00+00:00' });
  });

  it('DELETEs to clear a trial', () => {
    api.clearTrial(7).subscribe();
    const testRequest = ctrl.expectOne('https://api.test/api/admin/users/7/trial');
    expect(testRequest.request.method).toBe('DELETE');
    testRequest.flush({ status: 'active', trialEndsAt: null });
  });

  it('PUTs the subscription limit', () => {
    api.setSubscriptionLimit(7, 42).subscribe();
    const testRequest = ctrl.expectOne('https://api.test/api/admin/users/7/subscription-limit');
    expect(testRequest.request.method).toBe('PUT');
    expect(testRequest.request.body).toEqual({ maxSubscriptions: 42 });
    testRequest.flush({ maxSubscriptions: 42 });
  });

  it('PUTs a null subscription limit to clear it', () => {
    api.setSubscriptionLimit(7, null).subscribe();
    const testRequest = ctrl.expectOne('https://api.test/api/admin/users/7/subscription-limit');
    expect(testRequest.request.method).toBe('PUT');
    expect(testRequest.request.body).toEqual({ maxSubscriptions: null });
    testRequest.flush({ maxSubscriptions: null });
  });
});
