// src/app/core/preferences.service.ts
import { Injectable, inject, signal } from '@angular/core';
import { CurrentUser } from './auth.service';
import { PREFERENCES_WRITER } from './preferences-writer';

/**
 * Per-account settings, mirroring `LanguageService`: the account is the source
 * of truth, the signal is a cache the UI reads, and a write applies locally
 * first so the toggle does not wait on the network.
 */
@Injectable({ providedIn: 'root' })
export class PreferencesService {
  private readonly writer = inject(PREFERENCES_WRITER);

  /**
   * No "not loaded yet" state: a deep link to /settings renders this at its
   * `false` default before `AuthService.loadMe()` resolves and `adopt()`
   * overwrites it — a real window, not hypothetical (settings-shell only
   * fetches when `auth.user()` is null). Benign only because `false` is the
   * safe default for THIS preference: the next one added here must not
   * assume that — a `true` default could PATCH the wrong value during the
   * loading window. Give it a proper loaded/unloaded state instead of
   * repeating this coincidence.
   */
  readonly scrapeFallbackEnabled = signal(false);

  /** True when the value applied locally but the account write failed. */
  readonly saveFailed = signal(false);

  setScrapeFallbackEnabled(enabled: boolean): void {
    this.scrapeFallbackEnabled.set(enabled);
    this.saveFailed.set(false);

    this.writer.write(enabled).subscribe((ok) => {
      if (!ok) this.saveFailed.set(true);
    });
  }

  /**
   * Take the account's values, typically right after `AuthService.loadMe()`.
   * Caches only — a value that just arrived from the server is never PATCHed
   * straight back to it.
   */
  adopt(user: CurrentUser): void {
    this.scrapeFallbackEnabled.set(user.preferences.scrapeFallbackEnabled);
  }

  /**
   * Drops the signed-out account's cached preference. Unlike `LanguageService`
   * -- language is a deliberate per-device cache that outlives a session --
   * this preference is per-account: leaving it set would let the next signed-in
   * account see a stale value until its own `loadMe()` resolves, or forever if
   * that request fails.
   */
  reset(): void {
    this.scrapeFallbackEnabled.set(false);
    this.saveFailed.set(false);
  }
}
