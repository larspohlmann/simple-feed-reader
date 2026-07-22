// src/app/auth/altcha.service.spec.ts
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../core/api';
import { AltchaService } from './altcha.service';

describe('AltchaService', () => {
  it('fetches a challenge from the backend', () => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    const svc = TestBed.inject(AltchaService);
    const ctrl = TestBed.inject(HttpTestingController);
    let got: unknown;
    svc.challenge().subscribe((c) => (got = c));
    ctrl
      .expectOne('https://api.test/api/auth/altcha-challenge')
      .flush({ algorithm: 'SHA-256', challenge: 'c', salt: 's', signature: 'x', maxnumber: 5 });
    expect(got).toEqual({
      algorithm: 'SHA-256',
      challenge: 'c',
      salt: 's',
      signature: 'x',
      maxnumber: 5,
    });
  });
});
