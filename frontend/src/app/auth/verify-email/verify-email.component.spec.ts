import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ActivatedRoute } from '@angular/router';
import { of } from 'rxjs';
import { API_BASE_URL } from '../../core/api';
import { VerifyEmailComponent } from './verify-email.component';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';

function setup(token: string | null) {
  TestBed.configureTestingModule({
    imports: [VerifyEmailComponent, provideTranslocoTesting()],
    providers: [
      provideHttpClient(),
      provideHttpClientTesting(),
      { provide: API_BASE_URL, useValue: 'https://api.test' },
      { provide: ActivatedRoute, useValue: { queryParamMap: of({ get: () => token }) } },
    ],
  });
  const fixture = TestBed.createComponent(VerifyEmailComponent);
  // Run init logic without rendering the template: the error/ok branches embed
  // <a routerLink="/login">, which would require a fully-configured Router this
  // spec deliberately does not provide. Assertions target signals only.
  fixture.componentInstance.ngOnInit();
  return { fixture, controller: TestBed.inject(HttpTestingController) };
}

describe('VerifyEmailComponent', () => {
  it('posts the token and reports success', () => {
    const { fixture, controller } = setup('tok-123');
    controller
      .expectOne(
        (request) =>
          request.url === 'https://api.test/api/auth/verify-email' &&
          request.body.token === 'tok-123',
      )
      .flush({});
    expect(fixture.componentInstance.state()).toBe('ok');
  });

  it('reports error when the token is missing', () => {
    const { fixture, controller } = setup(null);
    controller.expectNone('https://api.test/api/auth/verify-email');
    expect(fixture.componentInstance.state()).toBe('error');
  });
});
