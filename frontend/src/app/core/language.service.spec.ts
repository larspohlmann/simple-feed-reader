import { TestBed } from '@angular/core/testing';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { LanguageService } from './language.service';
import { LANG_KEY } from './language';
import { API_BASE_URL } from './api';

describe('LanguageService', () => {
  let http: HttpTestingController;

  beforeEach(() => {
    localStorage.clear();
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: '' },
      ],
    });
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('starts from the persisted language and activates it', () => {
    localStorage.setItem(LANG_KEY, 'de');
    const service = TestBed.inject(LanguageService);
    expect(service.lang()).toBe('de');
    expect(document.documentElement.lang).toBe('de');
  });

  it('falls back to the browser language when nothing is persisted', () => {
    jest.spyOn(navigator, 'language', 'get').mockReturnValue('de-DE');
    const service = TestBed.inject(LanguageService);
    expect(service.lang()).toBe('de');
  });

  it('ignores a garbage persisted value and uses the browser language', () => {
    localStorage.setItem(LANG_KEY, 'klingon');
    jest.spyOn(navigator, 'language', 'get').mockReturnValue('en-US');
    const service = TestBed.inject(LanguageService);
    expect(service.lang()).toBe('en');
  });

  it('writes the chosen language through to the account', () => {
    const service = TestBed.inject(LanguageService);
    service.set('de');

    const req = http.expectOne({ method: 'PATCH', url: '/api/me' });
    expect(req.request.body).toEqual({ locale: 'de' });
    req.flush({ locale: 'de' });

    expect(service.lang()).toBe('de');
    expect(localStorage.getItem(LANG_KEY)).toBe('de');
    expect(document.documentElement.lang).toBe('de');
  });

  it('adopts the account language on login, over the cached one', () => {
    localStorage.setItem(LANG_KEY, 'en');
    const service = TestBed.inject(LanguageService);

    service.adopt('de');

    expect(service.lang()).toBe('de');
    expect(localStorage.getItem(LANG_KEY)).toBe('de');
    http.expectNone({ method: 'PATCH', url: '/api/me' });
  });

  it('ignores an unsupported account language rather than breaking the UI', () => {
    localStorage.setItem(LANG_KEY, 'en');
    const service = TestBed.inject(LanguageService);

    service.adopt('tlh');

    expect(service.lang()).toBe('en');
  });

  it('keeps the language applied locally when the write fails', () => {
    const service = TestBed.inject(LanguageService);
    service.set('de');

    http
      .expectOne({ method: 'PATCH', url: '/api/me' })
      .flush({ title: 'Server error' }, { status: 500, statusText: 'Server Error' });

    expect(service.lang()).toBe('de');
    expect(service.saveFailed()).toBe(true);
  });
});
