import { TestBed } from '@angular/core/testing';
import { Observable, of } from 'rxjs';
import { PREFERENCES_WRITER, PreferencesWriter } from './preferences-writer';
import { PreferencesService } from './preferences.service';

class FakeWriter implements PreferencesWriter {
  written: boolean[] = [];
  result = true;

  write(enabled: boolean): Observable<boolean> {
    this.written.push(enabled);
    return of(this.result);
  }
}

describe('PreferencesService', () => {
  let writer: FakeWriter;

  const service = (): PreferencesService => TestBed.inject(PreferencesService);

  beforeEach(() => {
    writer = new FakeWriter();
    TestBed.configureTestingModule({
      providers: [{ provide: PREFERENCES_WRITER, useValue: writer }],
    });
  });

  it('defaults to scraping disabled', () => {
    expect(service().scrapeFallbackEnabled()).toBe(false);
  });

  it('applies the value locally and writes it through', () => {
    const s = service();

    s.setScrapeFallbackEnabled(true);

    expect(s.scrapeFallbackEnabled()).toBe(true);
    expect(writer.written).toEqual([true]);
    expect(s.saveFailed()).toBe(false);
  });

  it('flags a failed write without reverting the local value', () => {
    writer.result = false;
    const s = service();

    s.setScrapeFallbackEnabled(true);

    expect(s.scrapeFallbackEnabled()).toBe(true);
    expect(s.saveFailed()).toBe(true);
  });

  it('adopts the account value without writing it back', () => {
    const s = service();

    s.adopt({
      id: 1,
      email: 'a@example.com',
      roles: [],
      status: 'active',
      createdAt: '2026-08-02T10:00:00+00:00',
      locale: 'en',
      trialEndsAt: null,
      preferences: {
        scrapeFallbackEnabled: true,
        digest: {
          enabled: false,
          cadence: 'daily',
          sendHour: 8,
          weekday: 1,
          format: 'html',
          timezone: 'UTC',
        },
        passkeyOfferAnswered: true,
        magazineStyle: 'boxed',
      },
      ai: { ready: false, model: null },
      mail: { enabled: true },
      emailVerified: true,
    });

    expect(s.scrapeFallbackEnabled()).toBe(true);
    expect(writer.written).toEqual([]);
  });
});
