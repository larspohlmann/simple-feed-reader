// src/app/auth/login/login.component.spec.ts
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Router, provideRouter } from '@angular/router';
import { API_BASE_URL } from '../../core/api';
import { LoginComponent } from './login.component';
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
    ctrl
      .expectOne('https://api.test/api/me')
      .flush({ id: 1, email: 'a@b.c', roles: [], status: 'active', createdAt: 'x' });
    expect(navigate).toHaveBeenCalledWith(['/']);
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
