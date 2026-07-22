// src/app/auth/reset-request/reset-request.component.spec.ts
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../../core/api';
import { ResetRequestComponent } from './reset-request.component';
import * as altcha from '../altcha';

describe('ResetRequestComponent', () => {
  let ctrl: HttpTestingController;
  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [ResetRequestComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    }).compileComponents();
    ctrl = TestBed.inject(HttpTestingController);
    jest.spyOn(altcha, 'solveAltcha').mockResolvedValue('SOLVED');
  });

  it('solves ALTCHA, posts the request, and shows a neutral confirmation', async () => {
    const f = TestBed.createComponent(ResetRequestComponent);
    const c = f.componentInstance;
    c.form.setValue({ email: 'a@b.c' });
    const done = c.submit();
    ctrl
      .expectOne('https://api.test/api/auth/altcha-challenge')
      .flush({ algorithm: 'SHA-256', challenge: 'c', salt: 's', signature: 'x', maxnumber: 5 });
    await new Promise((r) => setTimeout(r)); // drain the challenge→solve→post microtask chain
    const req = ctrl.expectOne('https://api.test/api/auth/password-reset-request');
    expect(req.request.body).toEqual({ email: 'a@b.c', altcha: 'SOLVED' });
    req.flush({});
    await done;
    expect(c.done()).toBe(true);
  });
});
