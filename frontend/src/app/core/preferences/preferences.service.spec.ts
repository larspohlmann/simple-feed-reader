import { TestBed } from '@angular/core/testing';
import { CurrentUser, UserPreferences } from '../auth/auth.service';
import { Observable, of } from 'rxjs';
import { PREFERENCES_WRITER, PreferencesWriter } from './preferences-writer';
import { PreferencesService } from './preferences.service';
import { SHARING_EVERYTHING } from './sharing';

class FakeWriter implements PreferencesWriter {
  written: boolean[] = [];
  trendingWritten: boolean[] = [];
  result = true;

  write(enabled: boolean): Observable<boolean> {
    this.written.push(enabled);
    return of(this.result);
  }

  writeTrending(enabled: boolean): Observable<boolean> {
    this.trendingWritten.push(enabled);
    return of(this.result);
  }
}

function jwtWith(claims: object): string {
  const encode = (part: object): string =>
    btoa(JSON.stringify(part)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  return `${encode({ alg: 'RS256' })}.${encode(claims)}.signature`;
}

function account(preferences: Partial<UserPreferences> = {}): CurrentUser {
  return {
    id: 1,
    email: 'a@example.com',
    roles: [],
    status: 'active',
    createdAt: '2026-08-02T10:00:00+00:00',
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
        timezone: 'UTC',
      },
      passkeyOfferAnswered: true,
      magazineStyle: 'boxed',
      sharing: SHARING_EVERYTHING,
      trendingEnabled: true,
      ...preferences,
    },
    ai: { ready: false, model: null, capabilities: null },
    mail: { enabled: true },
    emailVerified: true,
  };
}

describe('PreferencesService', () => {
  let writer: FakeWriter;

  const service = (): PreferencesService => TestBed.inject(PreferencesService);

  beforeEach(() => {
    localStorage.clear();
    writer = new FakeWriter();
    TestBed.configureTestingModule({
      providers: [{ provide: PREFERENCES_WRITER, useValue: writer }],
    });
  });

  it('defaults to scraping disabled', () => {
    expect(service().scrapeFallbackEnabled()).toBe(false);
  });

  it('applies the value locally and writes it through', () => {
    const preferencesService = service();

    preferencesService.setScrapeFallbackEnabled(true);

    expect(preferencesService.scrapeFallbackEnabled()).toBe(true);
    expect(writer.written).toEqual([true]);
    expect(preferencesService.saveFailed()).toBe(false);
  });

  it('flags a failed write without reverting the local value', () => {
    writer.result = false;
    const preferencesService = service();

    preferencesService.setScrapeFallbackEnabled(true);

    expect(preferencesService.scrapeFallbackEnabled()).toBe(true);
    expect(preferencesService.saveFailed()).toBe(true);
  });

  it('adopts the account value without writing it back', () => {
    const preferencesService = service();

    preferencesService.adopt(account({ scrapeFallbackEnabled: true, trendingEnabled: false }));

    expect(preferencesService.scrapeFallbackEnabled()).toBe(true);
    expect(preferencesService.trendingEnabled()).toBe(false);
    expect(writer.written).toEqual([]);
    expect(writer.trendingWritten).toEqual([]);
  });

  describe('Trending (#2017, D39)', () => {
    it('defaults to shown', () => {
      expect(service().trendingEnabled()).toBe(true);
    });

    it('applies the value locally and writes only Trending through', () => {
      const preferencesService = service();

      preferencesService.setTrendingEnabled(false);

      expect(preferencesService.trendingEnabled()).toBe(false);
      expect(writer.trendingWritten).toEqual([false]);
      expect(writer.written).toEqual([]);
      expect(preferencesService.trendingSaveFailed()).toBe(false);
    });

    it('flags a failed write on its own banner, keeping the local value', () => {
      writer.result = false;
      const preferencesService = service();

      preferencesService.setTrendingEnabled(false);

      expect(preferencesService.trendingEnabled()).toBe(false);
      expect(preferencesService.trendingSaveFailed()).toBe(true);
      expect(preferencesService.saveFailed()).toBe(false);
    });

    it('reads a missing account field as shown', () => {
      const preferencesService = service();
      const fromAnOlderServer: Partial<UserPreferences> = { ...account().preferences };
      delete fromAnOlderServer.trendingEnabled;

      preferencesService.adopt({ ...account(), preferences: fromAnOlderServer as UserPreferences });

      expect(preferencesService.trendingEnabled()).toBe(true);
    });

    describe("with the account's cached choice on this device", () => {
      const cacheKey = 'sfr.user.7.trending-enabled';

      beforeEach(() => localStorage.setItem('sfr.jwt', jwtWith({ userId: 7 })));

      it('paints a cached off as hidden before the account loads', () => {
        localStorage.setItem(cacheKey, 'false');

        expect(service().trendingEnabled()).toBe(false);
      });

      it("lets the account's value overwrite the cache", () => {
        localStorage.setItem(cacheKey, 'false');
        const preferencesService = service();

        preferencesService.adopt(account({ trendingEnabled: true }));

        expect(preferencesService.trendingEnabled()).toBe(true);
        expect(localStorage.getItem(cacheKey)).toBeNull();
      });

      it('caches an off from the account, and from the toggle, for the next start', () => {
        service().adopt(account({ trendingEnabled: false }));
        expect(localStorage.getItem(cacheKey)).toBe('false');

        localStorage.removeItem(cacheKey);
        service().setTrendingEnabled(false);
        expect(localStorage.getItem(cacheKey)).toBe('false');
      });
    });

    it('shows Trending again for the next account after a sign-out', () => {
      writer.result = false;
      const preferencesService = service();
      preferencesService.setTrendingEnabled(false);

      preferencesService.reset();

      expect(preferencesService.trendingEnabled()).toBe(true);
      expect(preferencesService.trendingSaveFailed()).toBe(false);
    });
  });
});
