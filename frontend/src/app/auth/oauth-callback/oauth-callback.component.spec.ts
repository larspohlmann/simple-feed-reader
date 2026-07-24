// src/app/auth/oauth-callback/oauth-callback.component.spec.ts
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ActivatedRoute, Router } from '@angular/router';
import { of } from 'rxjs';
import { API_BASE_URL } from '../../core/api';
import { TokenStore } from '../../core/token.store';
import { OAuthCallbackComponent } from './oauth-callback.component';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';

function setup(params: Record<string, string | null>) {
  const navigate = jest.fn();
  TestBed.configureTestingModule({
    imports: [OAuthCallbackComponent, provideTranslocoTesting()],
    providers: [
      provideHttpClient(),
      provideHttpClientTesting(),
      { provide: API_BASE_URL, useValue: 'https://api.test' },
      { provide: Router, useValue: { navigate } },
      {
        provide: ActivatedRoute,
        useValue: { queryParamMap: of({ get: (k: string) => params[k] ?? null }) },
      },
    ],
  });
  localStorage.clear();
  const f = TestBed.createComponent(OAuthCallbackComponent);
  // Run init logic without rendering the template: the error branch embeds
  // <a routerLink="/login">, which would require a fully-configured Router this
  // spec deliberately stubs with only { navigate }. Assertions target the
  // state() signal and the navigate spy only.
  f.componentInstance.ngOnInit();
  return {
    f,
    ctrl: TestBed.inject(HttpTestingController),
    navigate,
    tokens: TestBed.inject(TokenStore),
  };
}

describe('OAuthCallbackComponent', () => {
  it('exchanges the code CREDENTIALED, stores the token, loads me, and navigates home', () => {
    const { ctrl, navigate, tokens } = setup({ code: 'one-time' });
    const req = ctrl.expectOne('https://api.test/api/auth/oauth/exchange');
    expect(req.request.withCredentials).toBe(true);
    expect(req.request.body).toEqual({ code: 'one-time' });
    req.flush({ token: 'jwt-oauth' });
    expect(tokens.token()).toBe('jwt-oauth');
    ctrl
      .expectOne('https://api.test/api/me')
      .flush({ id: 1, email: 'a@b.c', roles: [], status: 'active', createdAt: 'x' });
    expect(navigate).toHaveBeenCalledWith(['/']);
  });

  it('shows the error and does not call exchange when the provider returned ?error', () => {
    const { f, ctrl } = setup({ error: 'access_denied' });
    ctrl.expectNone('https://api.test/api/auth/oauth/exchange');
    expect(f.componentInstance.state()).toBe('error');
  });
});
