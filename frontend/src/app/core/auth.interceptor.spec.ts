import { TestBed } from '@angular/core/testing';
import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Router } from '@angular/router';
import { API_BASE_URL } from './api';
import { TokenStore } from './token.store';
import { authInterceptor } from './auth.interceptor';
import { CatalogStore } from '../discover/catalog.store';
import { AiAvailabilityService } from './ai-availability.service';
import { CurrentUser } from './auth.service';

describe('authInterceptor', () => {
  let http: HttpClient;
  let ctrl: HttpTestingController;
  let tokens: TokenStore;
  const navigate = jest.fn();

  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([authInterceptor])),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: Router, useValue: { navigate } },
      ],
    });
    http = TestBed.inject(HttpClient);
    ctrl = TestBed.inject(HttpTestingController);
    tokens = TestBed.inject(TokenStore);
  });
  afterEach(() => ctrl.verify());

  it('attaches the bearer header to API requests when a token exists', () => {
    tokens.set('jwt-abc');
    http.get('https://api.test/api/me').subscribe();
    const req = ctrl.expectOne('https://api.test/api/me');
    expect(req.request.headers.get('Authorization')).toBe('Bearer jwt-abc');
    req.flush({});
  });

  it('does not attach a header when there is no token', () => {
    http.get('https://api.test/api/me').subscribe();
    const req = ctrl.expectOne('https://api.test/api/me');
    expect(req.request.headers.has('Authorization')).toBe(false);
    req.flush({});
  });

  it('clears the token and routes to /login on 401', () => {
    tokens.set('jwt-abc');
    http.get('https://api.test/api/me').subscribe({ error: () => undefined });
    ctrl
      .expectOne('https://api.test/api/me')
      .flush(null, { status: 401, statusText: 'Unauthorized' });
    expect(tokens.token()).toBeNull();
    expect(navigate).toHaveBeenCalledWith(['/login']);
  });

  // This path never calls AuthService.logout(), so a per-user cache that reset
  // itself there alone would survive an expired session into the next one
  // (#263). The token is the trigger, so this path is covered too.
  it('voids per-user caches on 401, the same as an explicit logout', () => {
    tokens.set('jwt-abc');
    const catalog = TestBed.inject(CatalogStore);
    catalog.load();
    ctrl.expectOne('https://api.test/api/catalog').flush({ categories: [] });
    expect(catalog.resolved()).toBe(true);

    http.get('https://api.test/api/me').subscribe({ error: () => undefined });
    ctrl
      .expectOne('https://api.test/api/me')
      .flush(null, { status: 401, statusText: 'Unauthorized' });
    TestBed.tick();

    expect(catalog.resolved()).toBe(false);
  });

  // Same hole, second cache: an expired session that never reaches logout()
  // would otherwise offer the next account AI on the previous account's model,
  // and keep offering it if that account's own /api/me never resolves (#263).
  it('drops AI availability on 401, so an expired session cannot hand it on', () => {
    tokens.set('jwt-abc');
    const ai = TestBed.inject(AiAvailabilityService);
    ai.adopt({ ai: { ready: true, model: 'gpt-4o' } } as CurrentUser);
    expect(ai.ready()).toBe(true);

    http.get('https://api.test/api/me').subscribe({ error: () => undefined });
    ctrl
      .expectOne('https://api.test/api/me')
      .flush(null, { status: 401, statusText: 'Unauthorized' });
    TestBed.tick();

    expect(ai.ready()).toBe(false);
    expect(ai.model()).toBeNull();
  });
});
