// src/app/core/language.service.ts
import { Injectable, inject, signal } from '@angular/core';
import { TranslocoService } from '@jsverse/transloco';
import { Lang, LANG_KEY, asLang, detectLang } from './language';

/**
 * The active UI language, persisted per-device (`sfr.lang`) exactly like the theme
 * and reading-layout preferences. Drives Transloco's active language so the whole
 * app re-renders in the chosen language at runtime — no reload. Defaults to the
 * browser's language on first visit.
 */
@Injectable({ providedIn: 'root' })
export class LanguageService {
  private readonly transloco = inject(TranslocoService);
  readonly lang = signal<Lang>(this.initial());

  constructor() {
    this.apply(this.lang());
  }

  set(lang: Lang): void {
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
