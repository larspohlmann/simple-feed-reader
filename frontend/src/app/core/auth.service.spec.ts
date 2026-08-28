// src/app/core/auth.service.spec.ts
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Router } from '@angular/router';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { API_BASE_URL } from './api';
import { TokenStore } from './token.store';
import { AuthService, CurrentUser } from './auth.service';
import { LanguageService } from './language.service';
import { LOCALE_WRITER } from './locale-writer';
import { HttpLocaleWriter } from './http-locale-writer';
import { PreferencesService } from './preferences.service';
import { DigestService } from './digest.service';
import { AiAvailabilityService } from './ai-availability.service';
import { CatalogStore } from '../discover/catalog.store';

describe('AuthService', () => {
  let svc: AuthService;
  let ctrl: HttpTestingController;
  let tokens: TokenStore;
  const navigate = jest.fn();

  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: Router, useValue: { navigate } },
        // Wire the real, HttpClient-backed writer rather than LOCALE_WRITER's
        // no-op default: the "no write-through on adopt" test below needs an
        // actual PATCH to *not* happen, which only the real writer can prove.
        { provide: LOCALE_WRITER, useExisting: HttpLocaleWriter },
      ],
    });
    svc = TestBed.inject(AuthService);
    ctrl = TestBed.inject(HttpTestingController);
    tokens = TestBed.inject(TokenStore);
  });
  afterEach(() => ctrl.verify());

  it('login stores the returned JWT', () => {
    svc.login('a@b.c', 'password12345').subscribe();
    const req = ctrl.expectOne('https://api.test/api/auth/login');
    expect(req.request.body).toEqual({ email: 'a@b.c', password: 'password12345' });
    req.flush({ token: 'jwt-xyz' });
    expect(tokens.token()).toBe('jwt-xyz');
  });

  it('loadMe populates the current-user signal and adopts the account locale, without writing it back', () => {
    svc.loadMe().subscribe();
    ctrl.expectOne('https://api.test/api/me').flush({
      id: 1,
      email: 'a@b.c',
      roles: ['ROLE_USER'],
      status: 'active',
      createdAt: '2026-07-01T00:00:00+00:00',
      locale: 'de',
      preferences: {
        scrapeFallbackEnabled: false,
        digest: {
          enabled: true,
          cadence: 'weekly',
          sendHour: 20,
          weekday: 5,
          timezone: 'Europe/Berlin',
        },
      },
      ai: { ready: true, model: 'gpt-4o' },
      mail: { enabled: true },
      emailVerified: true,
    });

    expect(svc.user()?.email).toBe('a@b.c');
    expect(TestBed.inject(AiAvailabilityService).ready()).toBe(true);
    expect(TestBed.inject(AiAvailabilityService).model()).toBe('gpt-4o');
    // The one place the account's locale is adopted into the UI.
    expect(TestBed.inject(LanguageService).lang()).toBe('de');
    const digest = TestBed.inject(DigestService);
    expect(digest.enabled()).toBe(true);
    expect(digest.cadence()).toBe('weekly');
    expect(digest.sendHour()).toBe(20);
    expect(digest.weekday()).toBe(5);
    expect(digest.timezone()).toBe('Europe/Berlin');
    // A value that just arrived from the server must never be PATCHed
    // straight back to it.
    ctrl.expectNone({ method: 'PATCH', url: 'https://api.test/api/me' });
  });

  it('logout clears token and user and routes to /login', () => {
    tokens.set('jwt');
    svc.logout();
    expect(tokens.token()).toBeNull();
    expect(svc.user()).toBeNull();
    expect(navigate).toHaveBeenCalledWith(['/login']);
  });

  it('logout resets the cached preferences, so the next account never sees a stale toggle', () => {
    const preferences = TestBed.inject(PreferencesService);
    preferences.setScrapeFallbackEnabled(true);
    expect(preferences.scrapeFallbackEnabled()).toBe(true);

    svc.logout();

    expect(preferences.scrapeFallbackEnabled()).toBe(false);
  });

  it('logout resets the cached digest settings, so the next account never sees a stale one', () => {
    const digest = TestBed.inject(DigestService);
    digest.setEnabled(true);
    expect(digest.enabled()).toBe(true);

    svc.logout();

    expect(digest.enabled()).toBe(false);
  });

  it('logout drops AI availability, so the next account never inherits it', () => {
    const ai = TestBed.inject(AiAvailabilityService);
    ai.adopt({ ai: { ready: true, model: 'gpt-4o' } } as CurrentUser);
    expect(ai.ready()).toBe(true);

    svc.logout();

    expect(ai.ready()).toBe(false);
    expect(ai.model()).toBeNull();
  });

  // Driven through the real logout() rather than through TokenStore, because
  // the wiring under test IS "logout clears the token, and the token is what
  // voids per-user caches" (#263).
  it('logout voids the cached catalog, so the next account never sees its subscribed marks', () => {
    tokens.set('jwt');
    const catalog = TestBed.inject(CatalogStore);

    catalog.load();
    ctrl.expectOne('https://api.test/api/catalog').flush({
      categories: [
        {
          id: 1,
          key: 'technology',
          name: 'Technology',
          icon: 'memory',
          color: '#3b82f6',
          feeds: [
            {
              id: 10,
              title: 'The Verge',
              description: null,
              siteUrl: null,
              faviconUrl: '/f/10',
              subscribed: true,
            },
          ],
        },
      ],
    });
    expect(catalog.resolved()).toBe(true);

    svc.logout();
    TestBed.tick();

    expect(catalog.resolved()).toBe(false);
    expect(catalog.categories()).toEqual([]);
  });
});
