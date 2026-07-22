// src/app/core/auth.service.spec.ts
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Router } from '@angular/router';
import { API_BASE_URL } from './api';
import { TokenStore } from './token.store';
import { AuthService } from './auth.service';

describe('AuthService', () => {
  let svc: AuthService;
  let ctrl: HttpTestingController;
  let tokens: TokenStore;
  const navigate = jest.fn();

  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: Router, useValue: { navigate } },
      ],
    });
    svc = TestBed.inject(AuthService);
    ctrl = TestBed.inject(HttpTestingController);
    tokens = TestBed.inject(TokenStore);
  });
  afterEach(() => ctrl.verify());

  it('login stores the returned JWT', () => {
    svc.login('a@b.c', 'password12345').subscribe();
    const req = ctrl.expectOne('https://api.test/api/auth/login');
    expect(req.request.body).toEqual({ email: 'a@b.c', password: 'password12345' });
    req.flush({ token: 'jwt-xyz' });
    expect(tokens.token()).toBe('jwt-xyz');
  });

  it('loadMe populates the current-user signal', () => {
    svc.loadMe().subscribe();
    ctrl.expectOne('https://api.test/api/me').flush({
      id: 1,
      email: 'a@b.c',
      roles: ['ROLE_USER'],
      status: 'active',
      createdAt: '2026-07-01T00:00:00+00:00',
    });
    expect(svc.user()?.email).toBe('a@b.c');
  });

  it('logout clears token and user and routes to /login', () => {
    tokens.set('jwt');
    svc.logout();
    expect(tokens.token()).toBeNull();
    expect(svc.user()).toBeNull();
    expect(navigate).toHaveBeenCalledWith(['/login']);
  });
});
