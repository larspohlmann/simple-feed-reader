// src/app/core/boot-language.spec.ts
import { NEVER, Observable, of, throwError } from 'rxjs';
import { TranslocoService } from '@jsverse/transloco';
import { DICTIONARY_WAIT_MS, preloadInitialLanguage } from './boot-language';
import { FALLBACK_LANG } from './language';

/** The two members preloadInitialLanguage touches, with observable behavior per lang. */
function translocoStub(load: (lang: string) => Observable<unknown>) {
  return {
    load: jest.fn(load),
    setActiveLang: jest.fn(),
  } as unknown as TranslocoService & { load: jest.Mock; setActiveLang: jest.Mock };
}

describe('preloadInitialLanguage', () => {
  afterEach(() => jest.useRealTimers());

  it('resolves once the requested dictionary loads', async () => {
    const transloco = translocoStub(() => of({ title: 'Anmelden' }));

    await expect(preloadInitialLanguage(transloco, 'de')).resolves.toEqual({ title: 'Anmelden' });
    expect(transloco.setActiveLang).not.toHaveBeenCalled();
  });

  it('falls back to the bundled language when the load fails', async () => {
    const transloco = translocoStub((lang) =>
      lang === FALLBACK_LANG ? of({}) : throwError(() => new Error('offline')),
    );

    await expect(preloadInitialLanguage(transloco, 'de')).resolves.toBeDefined();
    expect(transloco.setActiveLang).toHaveBeenCalledWith(FALLBACK_LANG);
    expect(transloco.load).toHaveBeenCalledWith(FALLBACK_LANG);
  });

  it('gives up on a stalled load after the wait bound and falls back', async () => {
    jest.useFakeTimers();
    const transloco = translocoStub((lang) => (lang === FALLBACK_LANG ? of({}) : NEVER));

    const boot = preloadInitialLanguage(transloco, 'de');
    jest.advanceTimersByTime(DICTIONARY_WAIT_MS);

    await expect(boot).resolves.toBeDefined();
    expect(transloco.setActiveLang).toHaveBeenCalledWith(FALLBACK_LANG);
  });

  it('never rejects, even when the fallback load itself throws', async () => {
    const transloco = translocoStub(() => throwError(() => new Error('everything is broken')));

    await expect(preloadInitialLanguage(transloco, 'de')).resolves.toBeUndefined();
  });
});
