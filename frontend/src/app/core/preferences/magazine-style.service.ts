import { Injectable, inject, signal } from '@angular/core';
import { CurrentUser } from '../auth/auth.service';
import { MAGAZINE_STYLE_KEY, MagazineStyle, asMagazineStyle } from './magazine-style';
import { PREFERENCES_WRITER, writeTracked } from './preferences-writer';

/**
 * The account is the record; `localStorage` is a paint cache. It answers the
 * warning in `PreferencesService`: once a style has been cached, no later
 * window shows or writes a wrong one — first login still seeds `boxed`.
 */
@Injectable({ providedIn: 'root' })
export class MagazineStyleService {
  private readonly writer = inject(PREFERENCES_WRITER);

  readonly style = signal<MagazineStyle>(this.cached());

  /** True when the style applied locally but the account write failed. */
  readonly saveFailed = signal(false);

  /** Applies locally first, then writes through; a failed write is surfaced. */
  set(style: MagazineStyle): void {
    this.cache(style);
    writeTracked(this.writer, { magazineStyle: style }, this.saveFailed);
  }

  /**
   * Caches only — a value that just arrived from the server is never PATCHed
   * straight back to it, and `adopt` runs on every `loadMe()`.
   */
  adopt(user: CurrentUser): void {
    const style = asMagazineStyle(user.preferences.magazineStyle);
    if (style === null) return;
    this.cache(style);
  }

  /**
   * Cache included: unlike the language, this is per-account, so leaving it set
   * would show the next account the previous one's reader.
   */
  reset(): void {
    localStorage.removeItem(MAGAZINE_STYLE_KEY);
    this.style.set('boxed');
    this.saveFailed.set(false);
  }

  private cache(style: MagazineStyle): void {
    localStorage.setItem(MAGAZINE_STYLE_KEY, style);
    this.style.set(style);
  }

  private cached(): MagazineStyle {
    return asMagazineStyle(localStorage.getItem(MAGAZINE_STYLE_KEY)) ?? 'boxed';
  }
}
