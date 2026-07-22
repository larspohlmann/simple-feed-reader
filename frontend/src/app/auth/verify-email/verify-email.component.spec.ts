// src/app/auth/verify-email/verify-email.component.spec.ts
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ActivatedRoute } from '@angular/router';
import { of } from 'rxjs';
import { API_BASE_URL } from '../../core/api';
import { VerifyEmailComponent } from './verify-email.component';

function setup(token: string | null) {
  TestBed.configureTestingModule({
    imports: [VerifyEmailComponent],
    providers: [
      provideHttpClient(),
      provideHttpClientTesting(),
      { provide: API_BASE_URL, useValue: 'https://api.test' },
      { provide: ActivatedRoute, useValue: { queryParamMap: of({ get: () => token }) } },
    ],
  });
  const f = TestBed.createComponent(VerifyEmailComponent);
  // Run init logic without rendering the template: the error/ok branches embed
  // <a routerLink="/login">, which would require a fully-configured Router this
  // spec deliberately does not provide. Assertions target signals only.
  f.componentInstance.ngOnInit();
  return { f, ctrl: TestBed.inject(HttpTestingController) };
}

describe('VerifyEmailComponent', () => {
  it('posts the token and reports success', () => {
    const { f, ctrl } = setup('tok-123');
    ctrl
      .expectOne((r) => r.url === 'https://api.test/api/auth/verify-email' && r.body.token === 'tok-123')
      .flush({});
    expect(f.componentInstance.state()).toBe('ok');
  });

  it('reports error when the token is missing', () => {
    const { f, ctrl } = setup(null);
    ctrl.expectNone('https://api.test/api/auth/verify-email');
    expect(f.componentInstance.state()).toBe('error');
  });
});
