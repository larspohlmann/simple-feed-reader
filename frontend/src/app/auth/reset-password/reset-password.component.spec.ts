// src/app/auth/reset-password/reset-password.component.spec.ts
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ActivatedRoute, Router } from '@angular/router';
import { of } from 'rxjs';
import { API_BASE_URL } from '../../core/api';
import { ResetPasswordComponent } from './reset-password.component';

describe('ResetPasswordComponent', () => {
  const navigate = jest.fn();
  function setup(token: string) {
    TestBed.configureTestingModule({
      imports: [ResetPasswordComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: Router, useValue: { navigate } },
        { provide: ActivatedRoute, useValue: { queryParamMap: of({ get: () => token }) } },
      ],
    });
    const f = TestBed.createComponent(ResetPasswordComponent);
    f.detectChanges();
    return { f, ctrl: TestBed.inject(HttpTestingController) };
  }

  it('posts token+password and navigates to login on success', () => {
    navigate.mockReset();
    const { f, ctrl } = setup('tok-9');
    f.componentInstance.form.setValue({ password: 'newpassword12' });
    f.componentInstance.submit();
    const req = ctrl.expectOne('https://api.test/api/auth/password-reset');
    expect(req.request.body).toEqual({ token: 'tok-9', password: 'newpassword12' });
    req.flush({});
    expect(navigate).toHaveBeenCalledWith(['/login'], { queryParams: { reset: '1' } });
  });
});
