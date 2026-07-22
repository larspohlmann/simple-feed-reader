// src/app/core/auth.interceptor.spec.ts
import { TestBed } from '@angular/core/testing';
import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Router } from '@angular/router';
import { API_BASE_URL } from './api';
import { TokenStore } from './token.store';
import { authInterceptor } from './auth.interceptor';

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
});
