// src/app/core/language.service.ts
import { Injectable, inject, signal } from '@angular/core';
import { TranslocoService } from '@jsverse/transloco';
import { AuthService } from './auth.service';
import { Lang, LANG_KEY, asLang, detectLang } from './language';

/**
 * The active UI language. The account is the source of truth -- AccountMailer
 * picks the language of every transactional email from User.locale, and a
 * native client cannot read browser storage -- so `sfr.lang` is a per-device
 * cache that keeps the pre-login screens and the next cold start in the right
 * language, not the record.
 */
@Injectable({ providedIn: 'root' })
export class LanguageService {
  private readonly transloco = inject(TranslocoService);
  private readonly auth = inject(AuthService);

  readonly lang = signal<Lang>(this.initial());

  /** True when the language applied locally but the account write failed. */
  readonly saveFailed = signal(false);

  constructor() {
    this.apply(this.lang());
  }

  /**
   * Switch the language. Applies immediately so the UI does not wait on the
   * network, then writes through. A failed write is surfaced rather than left
   * to make the two copies disagree in silence.
   */
  set(lang: Lang): void {
    this.cache(lang);
    this.saveFailed.set(false);

    this.auth.updateLocale(lang).subscribe({
      error: () => this.saveFailed.set(true),
    });
  }

  /**
   * Take the account's language after login. An unsupported value is ignored
   * rather than applied: an old or hand-edited locale must not leave the UI
   * with no translations.
   */
  adopt(locale: string | null): void {
    const lang = asLang(locale);
    if (lang === null || lang === this.lang()) return;
    this.cache(lang);
  }

  private cache(lang: Lang): void {
    localStorage.setItem(LANG_KEY, lang);
    this.lang.set(lang);
    this.apply(lang);
  }

  private apply(lang: Lang): void {
    this.transloco.setActiveLang(lang);
    // Keep the document language in step so screen readers pronounce content in
    // the right language and the browser offers the right translation prompts.
    if (typeof document !== 'undefined') document.documentElement.lang = lang;
  }

  private initial(): Lang {
    return asLang(localStorage.getItem(LANG_KEY)) ?? detectLang(navigator.language);
  }
}
