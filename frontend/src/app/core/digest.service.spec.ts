import { TestBed } from '@angular/core/testing';
import { Observable, of } from 'rxjs';
import { CurrentUser } from './auth.service';
import { DIGEST_WRITER, DigestConfig, DigestTestMailResult, DigestWriter } from './digest-writer';
import { DigestService } from './digest.service';

class FakeWriter implements DigestWriter {
  written: DigestConfig[] = [];
  result = true;
  testMailDaysRequested: number[] = [];
  testMailResult: DigestTestMailResult = 'sent';

  write(config: DigestConfig): Observable<boolean> {
    this.written.push(config);
    return of(this.result);
  }

  sendTest(days: number): Observable<DigestTestMailResult> {
    this.testMailDaysRequested.push(days);
    return of(this.testMailResult);
  }
}

const user = (digest: DigestConfig, timezone = 'UTC'): CurrentUser => ({
  id: 1,
  email: 'a@example.com',
  roles: [],
  status: 'active',
  createdAt: '2026-08-02T10:00:00+00:00',
  locale: 'en',
  trialEndsAt: null,
  preferences: {
    scrapeFallbackEnabled: false,
    digest: { ...digest, timezone },
    passkeyOfferAnswered: true,
    magazineStyle: 'boxed',
  },
  ai: { ready: false, model: null },
  mail: { enabled: true },
  emailVerified: true,
});

describe('DigestService', () => {
  let writer: FakeWriter;

  const service = (): DigestService => TestBed.inject(DigestService);

  beforeEach(() => {
    writer = new FakeWriter();
    TestBed.configureTestingModule({
      providers: [{ provide: DIGEST_WRITER, useValue: writer }],
    });
  });

  it('defaults to disabled, daily, 8am, Monday, UTC, html', () => {
    const s = service();

    expect(s.enabled()).toBe(false);
    expect(s.cadence()).toBe('daily');
    expect(s.sendHour()).toBe(8);
    expect(s.weekday()).toBe(1);
    expect(s.timezone()).toBe('UTC');
    expect(s.format()).toBe('html');
  });

  it('applies a changed field locally and writes the full config through', () => {
    const s = service();

    s.setEnabled(true);

    expect(s.enabled()).toBe(true);
    expect(writer.written).toEqual([
      { enabled: true, cadence: 'daily', sendHour: 8, weekday: 1, format: 'html' },
    ]);
    expect(s.saveFailed()).toBe(false);
  });

  it('writes the full config for each field, not just the one that changed', () => {
    const s = service();

    s.setEnabled(true);
    s.setCadence('weekly');
    s.setSendHour(20);
    s.setWeekday(5);
    s.setFormat('text');

    expect(writer.written).toEqual([
      { enabled: true, cadence: 'daily', sendHour: 8, weekday: 1, format: 'html' },
      { enabled: true, cadence: 'weekly', sendHour: 8, weekday: 1, format: 'html' },
      { enabled: true, cadence: 'weekly', sendHour: 20, weekday: 1, format: 'html' },
      { enabled: true, cadence: 'weekly', sendHour: 20, weekday: 5, format: 'html' },
      { enabled: true, cadence: 'weekly', sendHour: 20, weekday: 5, format: 'text' },
    ]);
  });

  it('writes the full config when only the format changes', () => {
    const s = service();

    s.setFormat('text');

    expect(s.format()).toBe('text');
    expect(writer.written).toEqual([
      { enabled: false, cadence: 'daily', sendHour: 8, weekday: 1, format: 'text' },
    ]);
  });

  it('flags a failed write without reverting the local value', () => {
    writer.result = false;
    const s = service();

    s.setEnabled(true);

    expect(s.enabled()).toBe(true);
    expect(s.saveFailed()).toBe(true);
  });

  it('adopts the account values without writing them back', () => {
    const s = service();

    s.adopt(
      user(
        { enabled: true, cadence: 'weekly', sendHour: 20, weekday: 5, format: 'text' },
        'Europe/Berlin',
      ),
    );

    expect(s.enabled()).toBe(true);
    expect(s.cadence()).toBe('weekly');
    expect(s.sendHour()).toBe(20);
    expect(s.weekday()).toBe(5);
    expect(s.timezone()).toBe('Europe/Berlin');
    expect(s.format()).toBe('text');
    expect(writer.written).toEqual([]);
  });

  it('adopts defaults without throwing when preferences.digest is missing', () => {
    const s = service();
    const malformedUser = {
      ...user(
        { enabled: true, cadence: 'weekly', sendHour: 20, weekday: 5, format: 'text' },
        'Europe/Berlin',
      ),
      preferences: { scrapeFallbackEnabled: false } as unknown as CurrentUser['preferences'],
    };

    expect(() => s.adopt(malformedUser)).not.toThrow();

    expect(s.enabled()).toBe(false);
    expect(s.cadence()).toBe('daily');
    expect(s.sendHour()).toBe(8);
    expect(s.weekday()).toBe(1);
    expect(s.timezone()).toBe('UTC');
    expect(s.format()).toBe('html');
    expect(writer.written).toEqual([]);
  });

  it('resets to defaults', () => {
    const s = service();
    s.adopt(
      user(
        { enabled: true, cadence: 'weekly', sendHour: 20, weekday: 5, format: 'text' },
        'Europe/Berlin',
      ),
    );

    s.reset();

    expect(s.enabled()).toBe(false);
    expect(s.cadence()).toBe('daily');
    expect(s.sendHour()).toBe(8);
    expect(s.weekday()).toBe(1);
    expect(s.timezone()).toBe('UTC');
    expect(s.format()).toBe('html');
    expect(s.saveFailed()).toBe(false);
  });

  it('forwards sendTest to the writer with the requested days', () => {
    writer.testMailResult = 'empty';
    const s = service();

    let result: DigestTestMailResult | undefined;
    s.sendTest(14).subscribe((r) => (result = r));

    expect(writer.testMailDaysRequested).toEqual([14]);
    expect(result).toBe('empty');
  });
});
