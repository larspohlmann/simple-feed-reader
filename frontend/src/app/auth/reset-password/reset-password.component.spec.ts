import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ActivatedRoute, Router } from '@angular/router';
import { of } from 'rxjs';
import { API_BASE_URL } from '../../core/api';
import { ResetPasswordComponent } from './reset-password.component';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';

describe('ResetPasswordComponent', () => {
  const navigate = jest.fn();
  function setup(token: string) {
    TestBed.configureTestingModule({
      imports: [ResetPasswordComponent, provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: Router, useValue: { navigate } },
        { provide: ActivatedRoute, useValue: { queryParamMap: of({ get: () => token }) } },
      ],
    });
    const fixture = TestBed.createComponent(ResetPasswordComponent);
    fixture.detectChanges();
    return { fixture, controller: TestBed.inject(HttpTestingController) };
  }

  it('posts token+password and navigates to login on success', () => {
    navigate.mockReset();
    const { fixture, controller } = setup('tok-9');
    fixture.componentInstance.form.setValue({ password: 'newpassword12' });
    fixture.componentInstance.submit();
    const testRequest = controller.expectOne('https://api.test/api/auth/password-reset');
    expect(testRequest.request.body).toEqual({ token: 'tok-9', password: 'newpassword12' });
    testRequest.flush({});
    expect(navigate).toHaveBeenCalledWith(['/login'], { queryParams: { reset: '1' } });
  });
});
