import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { API_BASE_URL } from '../../core/api';
import { ResetRequestComponent } from './reset-request.component';
import * as altcha from '../altcha';
import { SetupService } from '../../core/setup/setup.service';
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
    const fixture = TestBed.createComponent(ResetRequestComponent);
    const component = fixture.componentInstance;
    component.form.setValue({ email: 'a@b.c' });
    const done = component.submit();
    ctrl
      .expectOne('https://api.test/api/auth/altcha-challenge')
      .flush({ algorithm: 'SHA-256', challenge: 'c', salt: 's', signature: 'x', maxnumber: 5 });
    await new Promise((resolve) => setTimeout(resolve)); // drain the challenge→solve→post microtask chain
    const testRequest = ctrl.expectOne('https://api.test/api/auth/password-reset-request');
    expect(testRequest.request.body).toEqual({ email: 'a@b.c', altcha: 'SOLVED' });
    testRequest.flush({});
    await done;
    expect(component.done()).toBe(true);
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
    const fixture = TestBed.createComponent(ResetRequestComponent);
    fixture.detectChanges();
    return fixture;
  }

  function emailInput(fixture: ReturnType<typeof create>) {
    return (fixture.nativeElement as HTMLElement).querySelector('input[type="email"]');
  }

  function unavailableMessage(fixture: ReturnType<typeof create>) {
    return Array.from((fixture.nativeElement as HTMLElement).querySelectorAll('p')).find(
      (paragraph) => paragraph.textContent?.includes('unavailable'),
    );
  }

  it('hides the form and shows an unavailable message when mail is disabled', () => {
    const fixture = create(false);
    expect(emailInput(fixture)).toBeNull();
    expect(unavailableMessage(fixture)).toBeDefined();
  });

  it('shows the form when mail is enabled', () => {
    const fixture = create(true);
    expect(emailInput(fixture)).not.toBeNull();
    expect(unavailableMessage(fixture)).toBeUndefined();
  });

  it('shows the form while mail capability is still unknown', () => {
    const fixture = create(null);
    expect(emailInput(fixture)).not.toBeNull();
    expect(unavailableMessage(fixture)).toBeUndefined();
  });
});
