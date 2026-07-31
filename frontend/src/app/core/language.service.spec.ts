import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { LanguageService } from './language.service';
import { LANG_KEY } from './language';
import { LOCALE_WRITER } from './locale-writer';

describe('LanguageService', () => {
  let write: jest.Mock;

  beforeEach(() => {
    localStorage.clear();
    TestBed.resetTestingModule();
    write = jest.fn().mockReturnValue(of(true));
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [{ provide: LOCALE_WRITER, useValue: { write } }],
    });
  });

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

    expect(write).toHaveBeenCalledWith('de');
    expect(service.lang()).toBe('de');
    expect(localStorage.getItem(LANG_KEY)).toBe('de');
    expect(document.documentElement.lang).toBe('de');
  });

  it('adopts the account language on login, over the cached one, without writing it back', () => {
    localStorage.setItem(LANG_KEY, 'en');
    const service = TestBed.inject(LanguageService);

    service.adopt('de');

    expect(service.lang()).toBe('de');
    expect(localStorage.getItem(LANG_KEY)).toBe('de');
    // A value that just arrived from the server must never be PATCHed
    // straight back to it.
    expect(write).not.toHaveBeenCalled();
  });

  it('ignores an unsupported account language rather than breaking the UI', () => {
    localStorage.setItem(LANG_KEY, 'en');
    const service = TestBed.inject(LanguageService);

    service.adopt('tlh');

    expect(service.lang()).toBe('en');
  });

  it('keeps the language applied locally when the write fails', () => {
    write.mockReturnValue(of(false));
    const service = TestBed.inject(LanguageService);
    service.set('de');

    expect(service.lang()).toBe('de');
    expect(service.saveFailed()).toBe(true);
  });
});
