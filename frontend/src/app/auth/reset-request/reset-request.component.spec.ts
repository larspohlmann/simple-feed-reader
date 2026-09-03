import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { API_BASE_URL } from '../../core/api';
import { ResetRequestComponent } from './reset-request.component';
import * as altcha from '../altcha';
import { SetupService } from '../../setup/setup.service';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';

describe('ResetRequestComponent', () => {
  let ctrl: HttpTestingController;
  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [ResetRequestComponent, provideTranslocoTesting()],
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

describe('ResetRequestComponent — mailless instance', () => {
  function create(mailEnabled: boolean | null) {
    TestBed.configureTestingModule({
      imports: [ResetRequestComponent, provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: SetupService, useValue: { mailEnabled: signal(mailEnabled) } },
      ],
    }).compileComponents();
    const f = TestBed.createComponent(ResetRequestComponent);
    f.detectChanges();
    return f;
  }

  function emailInput(f: ReturnType<typeof create>) {
    return (f.nativeElement as HTMLElement).querySelector('input[type="email"]');
  }

  function unavailableMessage(f: ReturnType<typeof create>) {
    return Array.from((f.nativeElement as HTMLElement).querySelectorAll('p')).find((p) =>
      p.textContent?.includes('unavailable'),
    );
  }

  it('hides the form and shows an unavailable message when mail is disabled', () => {
    const f = create(false);
    expect(emailInput(f)).toBeNull();
    expect(unavailableMessage(f)).toBeDefined();
  });

  it('shows the form when mail is enabled', () => {
    const f = create(true);
    expect(emailInput(f)).not.toBeNull();
    expect(unavailableMessage(f)).toBeUndefined();
  });

  it('shows the form while mail capability is still unknown', () => {
    const f = create(null);
    expect(emailInput(f)).not.toBeNull();
    expect(unavailableMessage(f)).toBeUndefined();
  });
});
