// src/app/auth/login/login.component.spec.ts
import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Router, provideRouter } from '@angular/router';
import { API_BASE_URL } from '../../core/api';
import { LoginComponent } from './login.component';
import { SetupService } from '../../setup/setup.service';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';

describe('LoginComponent', () => {
  let ctrl: HttpTestingController;
  let navigate: jest.SpyInstance;

  beforeEach(async () => {
    localStorage.clear();
    await TestBed.configureTestingModule({
      imports: [LoginComponent, provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    }).compileComponents();
    ctrl = TestBed.inject(HttpTestingController);
    navigate = jest.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
  });

  function create() {
    const f = TestBed.createComponent(LoginComponent);
    f.detectChanges(); // triggers ngOnInit → providers GET
    ctrl.expectOne('https://api.test/api/auth/oauth/providers').flush({ providers: ['google'] });
    return f;
  }

  it('lists OAuth providers and builds provider URLs', () => {
    const f = create();
    expect(f.componentInstance.providers()).toEqual(['google']);
    expect(f.componentInstance.oauthUrl('google')).toBe('https://api.test/api/auth/oauth/google');
  });

  it('logs in, loads the user, and navigates home', () => {
    const f = create();
    f.componentInstance.form.setValue({ email: 'a@b.c', password: 'password12345' });
    f.componentInstance.submit();
    ctrl.expectOne('https://api.test/api/auth/login').flush({ token: 'jwt' });
    ctrl.expectOne('https://api.test/api/me').flush({
      id: 1,
      email: 'a@b.c',
      roles: [],
      status: 'active',
      createdAt: 'x',
      locale: 'de',
    });
    expect(navigate).toHaveBeenCalledWith(['/']);
    // Proves the account's locale, not the cached one, drives the UI after login.
    expect(document.documentElement.lang).toBe('de');
    expect(localStorage.getItem('sfr.lang')).toBe('de');
  });

  it('renders the problem detail on a failed login', () => {
    const f = create();
    f.componentInstance.form.setValue({ email: 'a@b.c', password: 'wrongpass1234' });
    f.componentInstance.submit();
    ctrl.expectOne('https://api.test/api/auth/login').flush(
      {
        type: 'invalid_credentials',
        title: 'x',
        status: 401,
        detail: 'Email address or password is incorrect.',
      },
      { status: 401, statusText: 'Unauthorized' },
    );
    expect(f.componentInstance.error()).toBe('Email address or password is incorrect.');
  });
});

describe('LoginComponent — forgot-password link visibility', () => {
  let ctrl: HttpTestingController;

  function create(mailEnabled: boolean | null) {
    TestBed.configureTestingModule({
      imports: [LoginComponent, provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: SetupService, useValue: { mailEnabled: signal(mailEnabled) } },
      ],
    }).compileComponents();
    ctrl = TestBed.inject(HttpTestingController);
    const f = TestBed.createComponent(LoginComponent);
    f.detectChanges();
    ctrl.expectOne('https://api.test/api/auth/oauth/providers').flush({ providers: [] });
    f.detectChanges();
    return f;
  }

  function resetLink(f: ReturnType<typeof create>) {
    const anchors = Array.from(
      (f.nativeElement as HTMLElement).querySelectorAll<HTMLAnchorElement>('a'),
    );
    return anchors.find((a) => a.getAttribute('routerLink') === '/reset-password-request');
  }

  it('hides the reset link when mail is disabled', () => {
    const f = create(false);
    expect(resetLink(f)).toBeUndefined();
  });

  it('shows the reset link when mail is enabled', () => {
    const f = create(true);
    expect(resetLink(f)).toBeDefined();
  });

  it('shows the reset link while mail capability is still unknown', () => {
    const f = create(null);
    expect(resetLink(f)).toBeDefined();
  });
});
