// src/app/auth/register/register.component.spec.ts
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../../core/api';
import { RegisterComponent } from './register.component';
import * as altcha from '../altcha';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';

describe('RegisterComponent', () => {
  let ctrl: HttpTestingController;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [RegisterComponent, provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    }).compileComponents();
    ctrl = TestBed.inject(HttpTestingController);
    jest.spyOn(altcha, 'solveAltcha').mockResolvedValue('SOLVED');
  });

  it('solves ALTCHA and registers, then shows the pending message', async () => {
    const f = TestBed.createComponent(RegisterComponent);
    const c = f.componentInstance;
    c.form.setValue({ email: 'a@b.c', password: 'password12345' });
    const done = c.submit();
    ctrl
      .expectOne('https://api.test/api/auth/altcha-challenge')
      .flush({ algorithm: 'SHA-256', challenge: 'c', salt: 's', signature: 'x', maxnumber: 5 });
    await new Promise((r) => setTimeout(r)); // drain the challenge→solve→post microtask chain
    const reg = ctrl.expectOne('https://api.test/api/auth/register');
    expect(reg.request.body).toEqual({
      email: 'a@b.c',
      password: 'password12345',
      altcha: 'SOLVED',
    });
    reg.flush({ status: 'pending_verification' }, { status: 202, statusText: 'Accepted' });
    await done;
    expect(c.done()).toBe(true);
  });

  it('surfaces a field error from validation_error', async () => {
    const f = TestBed.createComponent(RegisterComponent);
    const c = f.componentInstance;
    c.form.setValue({ email: 'a@b.c', password: 'password12345' });
    const done = c.submit();
    ctrl
      .expectOne('https://api.test/api/auth/altcha-challenge')
      .flush({ algorithm: 'SHA-256', challenge: 'c', salt: 's', signature: 'x', maxnumber: 5 });
    await new Promise((r) => setTimeout(r)); // drain the challenge→solve→post microtask chain
    ctrl.expectOne('https://api.test/api/auth/register').flush(
      {
        type: 'validation_error',
        title: 'x',
        status: 422,
        errors: { email: ['Already registered'] },
      },
      { status: 422, statusText: 'Unprocessable Entity' },
    );
    await done;
    expect(c.error()).toContain('Already registered');
  });
});
