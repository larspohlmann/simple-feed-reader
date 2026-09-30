import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../../core/api';
import { RegisterComponent } from './register.component';
import * as altcha from '../altcha';
import { provideRouter } from '@angular/router';
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
    const fixture = TestBed.createComponent(RegisterComponent);
    const component = fixture.componentInstance;
    component.form.setValue({ email: 'a@b.c', password: 'password12345' });
    const done = component.submit();
    ctrl
      .expectOne('https://api.test/api/auth/altcha-challenge')
      .flush({ algorithm: 'SHA-256', challenge: 'c', salt: 's', signature: 'x', maxnumber: 5 });
    await new Promise((resolve) => setTimeout(resolve)); // drain the challenge→solve→post microtask chain
    const reg = ctrl.expectOne('https://api.test/api/auth/register');
    expect(reg.request.body).toEqual({
      email: 'a@b.c',
      password: 'password12345',
      altcha: 'SOLVED',
      locale: 'en',
    });
    reg.flush({ status: 'pending_verification' }, { status: 202, statusText: 'Accepted' });
    await done;
    expect(component.resultStatus()).toBe('pending_verification');
  });

  it('surfaces a field error from validation_error', async () => {
    const fixture = TestBed.createComponent(RegisterComponent);
    const component = fixture.componentInstance;
    component.form.setValue({ email: 'a@b.c', password: 'password12345' });
    const done = component.submit();
    ctrl
      .expectOne('https://api.test/api/auth/altcha-challenge')
      .flush({ algorithm: 'SHA-256', challenge: 'c', salt: 's', signature: 'x', maxnumber: 5 });
    await new Promise((resolve) => setTimeout(resolve)); // drain the challenge→solve→post microtask chain
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
    expect(component.error()).toContain('Already registered');
  });
});

describe('RegisterComponent — a submit that cannot proceed must say so', () => {
  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [RegisterComponent, provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        // These two render the template, which carries routerLinks.
        provideRouter([]),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    }).compileComponents();
    jest.spyOn(altcha, 'solveAltcha').mockResolvedValue('SOLVED');
  });

  it('reports an invalid form instead of doing nothing', async () => {
    const fixture = TestBed.createComponent(RegisterComponent);
    const component = fixture.componentInstance;
    fixture.detectChanges();
    component.form.setValue({ email: 'not-an-email', password: 'short' });

    await component.submit();

    // The bug this replaces: submit() returned silently, so the button looked
    // broken. No spinner, no message, no request.
    expect(component.error()).toBeTruthy();
    expect(component.loading()).toBe(false);
    TestBed.inject(HttpTestingController).expectNone('https://api.test/api/auth/register');
  });

  // A password manager can fill the inputs without dispatching the event
  // Angular binds to. The user then sees their credentials on screen while the
  // form model is empty, and the form is invalid for no visible reason.
  it('adopts values a password manager wrote straight into the inputs', async () => {
    const fixture = TestBed.createComponent(RegisterComponent);
    const component = fixture.componentInstance;
    fixture.detectChanges();

    const host = fixture.nativeElement as HTMLElement;
    host.querySelector<HTMLInputElement>('input[type="email"]')!.value = 'filled@example.com';
    host.querySelector<HTMLInputElement>('input[type="password"]')!.value = 'autofilled-secret';
    // Deliberately no input event: that is the whole point.
    expect(component.form.value.email).toBe('');

    const done = component.submit();
    const ctrl = TestBed.inject(HttpTestingController);
    ctrl
      .expectOne('https://api.test/api/auth/altcha-challenge')
      .flush({ algorithm: 'SHA-256', challenge: 'c', salt: 's', signature: 'x', maxnumber: 5 });
    await new Promise((resolve) => setTimeout(resolve));
    const testRequest = ctrl.expectOne('https://api.test/api/auth/register');
    expect(testRequest.request.body.email).toBe('filled@example.com');
    expect(testRequest.request.body.password).toBe('autofilled-secret');
    testRequest.flush({ status: 'pending_verification' }, { status: 202, statusText: 'Accepted' });
    await done;

    expect(component.resultStatus()).toBe('pending_verification');
  });

  it('shows the check-email message for a pending_verification result', async () => {
    const fixture = TestBed.createComponent(RegisterComponent);
    const component = fixture.componentInstance;
    fixture.detectChanges();
    const ctrl = TestBed.inject(HttpTestingController);
    component.form.setValue({ email: 'a@b.c', password: 'password12345' });
    const done = component.submit();
    ctrl
      .expectOne('https://api.test/api/auth/altcha-challenge')
      .flush({ algorithm: 'SHA-256', challenge: 'c', salt: 's', signature: 'x', maxnumber: 5 });
    await new Promise((resolve) => setTimeout(resolve));
    ctrl
      .expectOne('https://api.test/api/auth/register')
      .flush({ status: 'pending_verification' }, { status: 202, statusText: 'Accepted' });
    await done;
    fixture.detectChanges();

    expect(component.resultStatus()).toBe('pending_verification');
    expect((fixture.nativeElement as HTMLElement).textContent).toContain(
      'Check your email for a confirmation link.',
    );
  });

  it('shows the ready-now message for an active result', async () => {
    const fixture = TestBed.createComponent(RegisterComponent);
    const component = fixture.componentInstance;
    fixture.detectChanges();
    const ctrl = TestBed.inject(HttpTestingController);
    component.form.setValue({ email: 'a@b.c', password: 'password12345' });
    const done = component.submit();
    ctrl
      .expectOne('https://api.test/api/auth/altcha-challenge')
      .flush({ algorithm: 'SHA-256', challenge: 'c', salt: 's', signature: 'x', maxnumber: 5 });
    await new Promise((resolve) => setTimeout(resolve));
    ctrl
      .expectOne('https://api.test/api/auth/register')
      .flush({ status: 'active' }, { status: 202, statusText: 'Accepted' });
    await done;
    fixture.detectChanges();

    expect(component.resultStatus()).toBe('active');
    expect((fixture.nativeElement as HTMLElement).textContent).toContain('You can sign in now.');
  });
});
