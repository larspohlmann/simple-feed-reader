import { TestBed } from '@angular/core/testing';
import { WritableSignal, signal } from '@angular/core';
import { of, throwError } from 'rxjs';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { AuthService, CurrentUser } from '../core/auth.service';
import { DigestService } from '../core/digest.service';
import { DigestTestMailResult } from '../core/digest-writer';
import { SavedSearchesStore } from '../reader/saved-searches.store';
import { SavedSearchDto } from '../reader/models';
import { EmailSectionComponent } from './email-section.component';
import en from '../../../public/i18n/en.json';

interface SavedSearchesStoreStub {
  savedSearches: WritableSignal<readonly SavedSearchDto[]>;
  load: jest.Mock;
  setIncludeInDigest: jest.Mock;
}

function search(overrides: Partial<SavedSearchDto> = {}): SavedSearchDto {
  return {
    id: 1,
    term: 'kubernetes',
    wholeWord: false,
    phrase: false,
    position: 0,
    unreadCount: 0,
    includeInDigest: false,
    ...overrides,
  };
}

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
      digest: {
        enabled: false,
        cadence: 'daily',
        sendHour: 8,
        weekday: 1,
        format: 'html',
        timezone: 'Europe/Berlin',
      },
      passkeyOfferAnswered: true,
      magazineStyle: 'boxed',
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
  let searchesStub: SavedSearchesStoreStub;

  function mount(u: CurrentUser, searches: readonly SavedSearchDto[] = []) {
    TestBed.resetTestingModule();
    resendVerification = jest.fn().mockReturnValue(of(undefined));
    searchesStub = {
      savedSearches: signal(searches),
      load: jest.fn(),
      setIncludeInDigest: jest.fn(),
    };
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        { provide: AuthService, useValue: { user: signal(u), resendVerification } },
        { provide: SavedSearchesStore, useValue: searchesStub },
      ],
    });
    digest = TestBed.inject(DigestService);
    digest.adopt(u);
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

    it('renders each send-hour option as a zero-padded clock time', () => {
      const f = mount(user());
      const el = f.nativeElement as HTMLElement;

      const sendHour = el.querySelector('[data-testid="digest-send-hour"]') as HTMLSelectElement;
      const eightAm = Array.from(sendHour.options).find((option) => option.value === '8');

      expect(eightAm?.textContent?.trim()).toBe('08:00');
    });

    it('shows the instance timezone adopted from the account next to the send hour', () => {
      const f = mount(user());
      const el = f.nativeElement as HTMLElement;

      expect(el.textContent).toContain('Europe/Berlin');
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

  describe('included saved searches', () => {
    it('shows the empty state when the user has no saved searches', () => {
      const f = mount(user(), []);
      const el = f.nativeElement as HTMLElement;

      expect(el.textContent).toContain(en.settings.email.includedSearchesEmpty);
    });

    it('lists each saved search with a toggle bound to includeInDigest', () => {
      const f = mount(user(), [
        search({ id: 1, term: 'kubernetes', includeInDigest: true }),
        search({ id: 2, term: 'rust', includeInDigest: false }),
      ]);
      const el = f.nativeElement as HTMLElement;

      expect(el.textContent).toContain('kubernetes');
      expect(el.textContent).toContain('rust');
      const toggles = Array.from(
        el.querySelectorAll('app-toggle input[type="checkbox"]'),
      ) as HTMLInputElement[];
      // one master digest toggle + one per saved search
      expect(toggles.length).toBe(3);
      expect(toggles[1].checked).toBe(true);
      expect(toggles[2].checked).toBe(false);
    });

    it('writes a toggle through setIncludeInDigest', () => {
      const f = mount(user(), [search({ id: 7, term: 'rust', includeInDigest: false })]);
      const el = f.nativeElement as HTMLElement;
      const toggles = Array.from(
        el.querySelectorAll('app-toggle input[type="checkbox"]'),
      ) as HTMLInputElement[];

      toggles[1].click();
      f.detectChanges();

      expect(searchesStub.setIncludeInDigest).toHaveBeenCalledWith(7, true);
    });

    it('loads the store on mount', () => {
      mount(user(), []);

      expect(searchesStub.load).toHaveBeenCalledTimes(1);
    });
  });

  describe('test-mail row', () => {
    function daysInput(el: HTMLElement): HTMLInputElement {
      return el.querySelector('[data-testid="test-mail-days"]') as HTMLInputElement;
    }

    function sendButton(el: HTMLElement): HTMLButtonElement {
      return el.querySelector('[data-testid="test-mail-send"] button') as HTMLButtonElement;
    }

    it('defaults the days input to 7, with a 1-30 range', () => {
      const f = mount(user(), [search({ includeInDigest: true })]);
      const el = f.nativeElement as HTMLElement;
      const input = daysInput(el);

      expect(input.value).toBe('7');
      expect(input.min).toBe('1');
      expect(input.max).toBe('30');
    });

    it('disables the send button when no saved search is included', () => {
      const f = mount(user(), [search({ includeInDigest: false })]);
      const el = f.nativeElement as HTMLElement;

      expect(sendButton(el).disabled).toBe(true);
    });

    it('enables the send button once a search is included', () => {
      const f = mount(user(), [search({ includeInDigest: true })]);
      const el = f.nativeElement as HTMLElement;

      expect(sendButton(el).disabled).toBe(false);
    });

    it('sends the chosen number of days', () => {
      const f = mount(user(), [search({ includeInDigest: true })]);
      const el = f.nativeElement as HTMLElement;
      const sendTest = jest.spyOn(digest, 'sendTest').mockReturnValue(of('sent'));

      const input = daysInput(el);
      input.value = '14';
      input.dispatchEvent(new Event('change'));
      f.detectChanges();
      sendButton(el).click();
      f.detectChanges();

      expect(sendTest).toHaveBeenCalledWith(14);
    });

    it('flags an out-of-range day count and blocks the send', () => {
      const f = mount(user(), [search({ includeInDigest: true })]);
      const el = f.nativeElement as HTMLElement;

      const input = daysInput(el);
      input.value = '60';
      input.dispatchEvent(new Event('change'));
      f.detectChanges();

      expect(input.getAttribute('aria-invalid')).toBe('true');
      expect(sendButton(el).disabled).toBe(true);
      expect(el.textContent).toContain('Choose between');
    });

    it('clears the error and re-enables the send once the day count is back in range', () => {
      const f = mount(user(), [search({ includeInDigest: true })]);
      const el = f.nativeElement as HTMLElement;
      const input = daysInput(el);

      input.value = '60';
      input.dispatchEvent(new Event('change'));
      f.detectChanges();
      input.value = '20';
      input.dispatchEvent(new Event('change'));
      f.detectChanges();

      expect(input.getAttribute('aria-invalid')).toBe('false');
      expect(sendButton(el).disabled).toBe(false);
    });

    it('shows a confirmation on a successful send', () => {
      const f = mount(user(), [search({ includeInDigest: true })]);
      jest.spyOn(digest, 'sendTest').mockReturnValue(of('sent' as DigestTestMailResult));
      const el = f.nativeElement as HTMLElement;

      sendButton(el).click();
      f.detectChanges();

      expect(el.textContent).toContain(en.settings.email.testMailSent);
    });

    it('shows the nothing-to-send message when the result is empty', () => {
      const f = mount(user(), [search({ includeInDigest: true })]);
      jest.spyOn(digest, 'sendTest').mockReturnValue(of('empty' as DigestTestMailResult));
      const el = f.nativeElement as HTMLElement;

      sendButton(el).click();
      f.detectChanges();

      expect(el.textContent).toContain(en.settings.email.testMailEmpty);
    });

    it('shows the rate-limit message on a 429', () => {
      const f = mount(user(), [search({ includeInDigest: true })]);
      jest.spyOn(digest, 'sendTest').mockReturnValue(of('rateLimited' as DigestTestMailResult));
      const el = f.nativeElement as HTMLElement;

      sendButton(el).click();
      f.detectChanges();

      expect(el.textContent).toContain(en.settings.email.testMailRateLimited);
    });

    it('never throws when the caller observable errors', () => {
      const f = mount(user(), [search({ includeInDigest: true })]);
      jest.spyOn(digest, 'sendTest').mockReturnValue(throwError(() => new Error('boom')));
      const el = f.nativeElement as HTMLElement;

      expect(() => {
        sendButton(el).click();
        f.detectChanges();
      }).not.toThrow();
    });

    it('styles a failed send as an error, not the neutral info callout', () => {
      const f = mount(user(), [search({ includeInDigest: true })]);
      jest.spyOn(digest, 'sendTest').mockReturnValue(of('failed' as DigestTestMailResult));
      const el = f.nativeElement as HTMLElement;

      sendButton(el).click();
      f.detectChanges();

      const result = el.querySelector('[data-testid="test-mail-result"]') as HTMLElement;
      expect(result.classList).toContain('callout--error');
      expect(result.classList).not.toContain('callout--success');
    });

    it('styles a successful send as success', () => {
      const f = mount(user(), [search({ includeInDigest: true })]);
      jest.spyOn(digest, 'sendTest').mockReturnValue(of('sent' as DigestTestMailResult));
      const el = f.nativeElement as HTMLElement;

      sendButton(el).click();
      f.detectChanges();

      const result = el.querySelector('[data-testid="test-mail-result"]') as HTMLElement;
      expect(result.classList).toContain('callout--success');
      expect(result.classList).not.toContain('callout--error');
    });
  });
});
