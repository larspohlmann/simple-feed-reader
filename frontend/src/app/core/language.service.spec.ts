import { TestBed } from '@angular/core/testing';
import { TranslocoService } from '@jsverse/transloco';
import { LanguageService } from './language.service';
import { LANG_KEY } from './language';

function makeTransloco() {
  return { setActiveLang: jest.fn() } as unknown as TranslocoService;
}

function setup(transloco = makeTransloco()) {
  TestBed.resetTestingModule();
  TestBed.configureTestingModule({
    providers: [LanguageService, { provide: TranslocoService, useValue: transloco }],
  });
  return { transloco, svc: () => TestBed.inject(LanguageService) };
}

describe('LanguageService', () => {
  beforeEach(() => localStorage.clear());

  it('starts from the persisted language and activates it in Transloco', () => {
    localStorage.setItem(LANG_KEY, 'de');
    const { transloco, svc } = setup();
    expect(svc().lang()).toBe('de');
    expect(transloco.setActiveLang).toHaveBeenCalledWith('de');
  });

  it('falls back to the browser language when nothing is persisted', () => {
    jest.spyOn(navigator, 'language', 'get').mockReturnValue('de-DE');
    const { svc } = setup();
    expect(svc().lang()).toBe('de');
  });

  it('ignores a garbage persisted value and uses the browser language', () => {
    localStorage.setItem(LANG_KEY, 'klingon');
    jest.spyOn(navigator, 'language', 'get').mockReturnValue('en-US');
    const { svc } = setup();
    expect(svc().lang()).toBe('en');
  });

  it('persists and activates a new language on set', () => {
    localStorage.setItem(LANG_KEY, 'en');
    const { transloco, svc } = setup();
    const s = svc();
    s.set('de');
    expect(s.lang()).toBe('de');
    expect(localStorage.getItem(LANG_KEY)).toBe('de');
    expect(transloco.setActiveLang).toHaveBeenLastCalledWith('de');
  });
});
