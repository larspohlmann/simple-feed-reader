import { TestBed } from '@angular/core/testing';
import { Observable, of } from 'rxjs';
import { CurrentUser } from '../auth/auth.service';
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
  ai: { ready: false, model: null, capabilities: null },
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
    const digestService = service();

    expect(digestService.enabled()).toBe(false);
    expect(digestService.cadence()).toBe('daily');
    expect(digestService.sendHour()).toBe(8);
    expect(digestService.weekday()).toBe(1);
    expect(digestService.timezone()).toBe('UTC');
    expect(digestService.format()).toBe('html');
  });

  it('applies a changed field locally and writes the full config through', () => {
    const digestService = service();

    digestService.setEnabled(true);

    expect(digestService.enabled()).toBe(true);
    expect(writer.written).toEqual([
      { enabled: true, cadence: 'daily', sendHour: 8, weekday: 1, format: 'html' },
    ]);
    expect(digestService.saveFailed()).toBe(false);
  });

  it('writes the full config for each field, not just the one that changed', () => {
    const digestService = service();

    digestService.setEnabled(true);
    digestService.setCadence('weekly');
    digestService.setSendHour(20);
    digestService.setWeekday(5);
    digestService.setFormat('text');

    expect(writer.written).toEqual([
      { enabled: true, cadence: 'daily', sendHour: 8, weekday: 1, format: 'html' },
      { enabled: true, cadence: 'weekly', sendHour: 8, weekday: 1, format: 'html' },
      { enabled: true, cadence: 'weekly', sendHour: 20, weekday: 1, format: 'html' },
      { enabled: true, cadence: 'weekly', sendHour: 20, weekday: 5, format: 'html' },
      { enabled: true, cadence: 'weekly', sendHour: 20, weekday: 5, format: 'text' },
    ]);
  });

  it('writes the full config when only the format changes', () => {
    const digestService = service();

    digestService.setFormat('text');

    expect(digestService.format()).toBe('text');
    expect(writer.written).toEqual([
      { enabled: false, cadence: 'daily', sendHour: 8, weekday: 1, format: 'text' },
    ]);
  });

  it('flags a failed write without reverting the local value', () => {
    writer.result = false;
    const digestService = service();

    digestService.setEnabled(true);

    expect(digestService.enabled()).toBe(true);
    expect(digestService.saveFailed()).toBe(true);
  });

  it('adopts the account values without writing them back', () => {
    const digestService = service();

    digestService.adopt(
      user(
        { enabled: true, cadence: 'weekly', sendHour: 20, weekday: 5, format: 'text' },
        'Europe/Berlin',
      ),
    );

    expect(digestService.enabled()).toBe(true);
    expect(digestService.cadence()).toBe('weekly');
    expect(digestService.sendHour()).toBe(20);
    expect(digestService.weekday()).toBe(5);
    expect(digestService.timezone()).toBe('Europe/Berlin');
    expect(digestService.format()).toBe('text');
    expect(writer.written).toEqual([]);
  });

  it('adopts defaults without throwing when preferences.digest is missing', () => {
    const digestService = service();
    const malformedUser = {
      ...user(
        { enabled: true, cadence: 'weekly', sendHour: 20, weekday: 5, format: 'text' },
        'Europe/Berlin',
      ),
      preferences: { scrapeFallbackEnabled: false } as unknown as CurrentUser['preferences'],
    };

    expect(() => digestService.adopt(malformedUser)).not.toThrow();

    expect(digestService.enabled()).toBe(false);
    expect(digestService.cadence()).toBe('daily');
    expect(digestService.sendHour()).toBe(8);
    expect(digestService.weekday()).toBe(1);
    expect(digestService.timezone()).toBe('UTC');
    expect(digestService.format()).toBe('html');
    expect(writer.written).toEqual([]);
  });

  it('resets to defaults', () => {
    const digestService = service();
    digestService.adopt(
      user(
        { enabled: true, cadence: 'weekly', sendHour: 20, weekday: 5, format: 'text' },
        'Europe/Berlin',
      ),
    );

    digestService.reset();

    expect(digestService.enabled()).toBe(false);
    expect(digestService.cadence()).toBe('daily');
    expect(digestService.sendHour()).toBe(8);
    expect(digestService.weekday()).toBe(1);
    expect(digestService.timezone()).toBe('UTC');
    expect(digestService.format()).toBe('html');
    expect(digestService.saveFailed()).toBe(false);
  });

  it('forwards sendTest to the writer with the requested days', () => {
    writer.testMailResult = 'empty';
    const digestService = service();

    let result: DigestTestMailResult | undefined;
    digestService.sendTest(14).subscribe((mailResult) => (result = mailResult));

    expect(writer.testMailDaysRequested).toEqual([14]);
    expect(result).toBe('empty');
  });
});
