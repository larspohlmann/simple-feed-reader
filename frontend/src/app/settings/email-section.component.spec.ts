// src/app/settings/email-section.component.spec.ts
import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { of } from 'rxjs';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { AuthService, CurrentUser } from '../core/auth.service';
import { DigestService } from '../core/digest.service';
import { EmailSectionComponent } from './email-section.component';
import en from '../../../public/i18n/en.json';

function user(overrides: Partial<CurrentUser> = {}): CurrentUser {
  return {
    id: 1,
    email: 'me@x',
    roles: ['ROLE_USER'],
    status: 'active',
    createdAt: '2026-01-01T00:00:00Z',
    locale: 'en',
    trialEndsAt: null,
    preferences: {
      scrapeFallbackEnabled: false,
      digest: { enabled: false, cadence: 'daily', sendHour: 8, weekday: 1 },
    },
    ai: { ready: false, model: null },
    mail: { enabled: true },
    emailVerified: true,
    ...overrides,
  };
}

describe('EmailSectionComponent', () => {
  let resendVerification: jest.Mock;
  let digest: DigestService;

  function mount(u: CurrentUser) {
    TestBed.resetTestingModule();
    resendVerification = jest.fn().mockReturnValue(of(undefined));
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [{ provide: AuthService, useValue: { user: signal(u), resendVerification } }],
    });
    digest = TestBed.inject(DigestService);
    const f = TestBed.createComponent(EmailSectionComponent);
    f.detectChanges();
    return f;
  }

  describe('mail disabled on this instance', () => {
    it('shows the disabled box and no interactive controls', () => {
      const f = mount(user({ mail: { enabled: false } }));
      const el = f.nativeElement as HTMLElement;

      expect(el.textContent).toContain(en.settings.email.mailDisabled);
      expect(el.querySelector('app-toggle')).toBeNull();
      expect(el.querySelector('select')).toBeNull();
      expect(el.querySelector('button')).toBeNull();
    });
  });

  describe('unverified address', () => {
    it('shows the resend button and disabled controls', () => {
      const f = mount(user({ mail: { enabled: true }, emailVerified: false }));
      const el = f.nativeElement as HTMLElement;

      expect(el.textContent).toContain(en.settings.email.unverified);
      const toggle = el.querySelector('app-toggle input[type="checkbox"]') as HTMLInputElement;
      expect(toggle.disabled).toBe(true);
      const selects = Array.from(el.querySelectorAll('select')) as HTMLSelectElement[];
      expect(selects.length).toBeGreaterThan(0);
      for (const select of selects) expect(select.disabled).toBe(true);
    });

    it('calls the resend API exactly once when the resend button is clicked', () => {
      const f = mount(user({ mail: { enabled: true }, emailVerified: false }));
      const el = f.nativeElement as HTMLElement;
      const buttons = Array.from(el.querySelectorAll('button'));
      const resend = buttons.find((b) => b.textContent?.includes(en.settings.email.resend));

      (resend as HTMLButtonElement).click();

      expect(resendVerification).toHaveBeenCalledTimes(1);
    });
  });

  describe('ready', () => {
    it('shows no box and an enabled master toggle, cadence and hour', () => {
      const f = mount(user());
      const el = f.nativeElement as HTMLElement;

      expect(el.textContent).not.toContain(en.settings.email.mailDisabled);
      expect(el.textContent).not.toContain(en.settings.email.unverified);

      const toggle = el.querySelector('app-toggle input[type="checkbox"]') as HTMLInputElement;
      expect(toggle.disabled).toBe(false);

      const cadence = el.querySelector('[data-testid="digest-cadence"]') as HTMLSelectElement;
      const sendHour = el.querySelector('[data-testid="digest-send-hour"]') as HTMLSelectElement;
      expect(cadence.disabled).toBe(false);
      expect(sendHour.disabled).toBe(false);
    });

    it('shows no weekday selector while cadence is daily', () => {
      const f = mount(user());
      const el = f.nativeElement as HTMLElement;

      expect(el.querySelector('[data-testid="digest-weekday"]')).toBeNull();
    });

    it('shows the weekday selector once cadence is weekly', () => {
      const f = mount(user());
      digest.setCadence('weekly');
      f.detectChanges();

      const el = f.nativeElement as HTMLElement;
      expect(el.querySelector('[data-testid="digest-weekday"]')).not.toBeNull();
    });

    it('writes the master toggle through DigestService', () => {
      const f = mount(user());
      const el = f.nativeElement as HTMLElement;
      const toggle = el.querySelector('app-toggle input[type="checkbox"]') as HTMLInputElement;

      toggle.click();
      f.detectChanges();

      expect(digest.enabled()).toBe(true);
    });

    it('writes the cadence when changed', () => {
      const f = mount(user());
      const el = f.nativeElement as HTMLElement;
      const cadence = el.querySelector('[data-testid="digest-cadence"]') as HTMLSelectElement;

      cadence.value = 'weekly';
      cadence.dispatchEvent(new Event('change'));
      f.detectChanges();

      expect(digest.cadence()).toBe('weekly');
    });

    it('writes the send hour when changed', () => {
      const f = mount(user());
      const el = f.nativeElement as HTMLElement;
      const sendHour = el.querySelector('[data-testid="digest-send-hour"]') as HTMLSelectElement;

      sendHour.value = '20';
      sendHour.dispatchEvent(new Event('change'));
      f.detectChanges();

      expect(digest.sendHour()).toBe(20);
    });

    it('writes the weekday when changed', () => {
      const f = mount(user());
      digest.setCadence('weekly');
      f.detectChanges();

      const el = f.nativeElement as HTMLElement;
      const weekday = el.querySelector('[data-testid="digest-weekday"]') as HTMLSelectElement;
      weekday.value = '5';
      weekday.dispatchEvent(new Event('change'));
      f.detectChanges();

      expect(digest.weekday()).toBe(5);
    });
  });
});
