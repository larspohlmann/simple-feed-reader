import { Injectable, computed, inject, signal } from '@angular/core';
import { userIdClaim } from '../auth/account-identity';
import { CurrentUser } from '../auth/auth.service';
import { TokenStore } from '../auth/token.store';
import { PREFERENCES_WRITER } from './preferences-writer';
import { userDeviceKey } from './user-device-storage';

const TRENDING_HIDDEN = 'trending-hidden';

/**
 * Per-account settings, mirroring `LanguageService`: the account is the source
 * of truth, the signal is a cache the UI reads, and a write applies locally
 * first so the toggle does not wait on the network.
 */
@Injectable({ providedIn: 'root' })
export class PreferencesService {
  private readonly writer = inject(PREFERENCES_WRITER);
  /** The token, not `UserDeviceStorage`: that one reaches `AuthService`, which injects this service. */
  private readonly tokens = inject(TokenStore);

  /**
   * No "not loaded yet" state: a deep link to /settings renders this at its
   * `false` default before `AuthService.loadMe()` resolves and `adopt()`
   * overwrites it — a real window, not hypothetical (settings-shell only
   * fetches when `auth.user()` is null). Benign only because `false` is the
   * safe default for THIS preference. Trending, on by default, paints from a
   * per-account cache instead, so its window shows the account's last choice.
   */
  readonly scrapeFallbackEnabled = signal(false);

  /** True when the value applied locally but the account write failed. */
  readonly saveFailed = signal(false);

  /** This session's value; null until `adopt()` or a toggle, when the account's cached choice paints instead. */
  private readonly trendingChoice = signal<boolean | null>(null);
  private readonly trendingCacheKey = computed(() => {
    const userId = userIdClaim(this.tokens.token());
    return userId === null ? null : userDeviceKey(userId, TRENDING_HIDDEN);
  });
  readonly trendingEnabled = computed(() => this.trendingChoice() ?? !this.trendingCachedHidden());

  readonly trendingSaveFailed = signal(false);

  setScrapeFallbackEnabled(enabled: boolean): void {
    this.scrapeFallbackEnabled.set(enabled);
    this.saveFailed.set(false);

    this.writer.write(enabled).subscribe((ok) => {
      if (!ok) this.saveFailed.set(true);
    });
  }

  setTrendingEnabled(enabled: boolean): void {
    this.applyTrending(enabled);
    this.trendingSaveFailed.set(false);

    this.writer.writeTrending(enabled).subscribe((ok) => {
      if (!ok) this.trendingSaveFailed.set(true);
    });
  }

  /**
   * Take the account's values, typically right after `AuthService.loadMe()`.
   * Caches only — a value that just arrived from the server is never PATCHed
   * straight back to it.
   */
  adopt(user: CurrentUser): void {
    this.scrapeFallbackEnabled.set(user.preferences.scrapeFallbackEnabled);
    this.applyTrending(user.preferences.trendingEnabled ?? true);
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
    this.trendingChoice.set(null);
    this.trendingSaveFailed.set(false);
  }

  private applyTrending(enabled: boolean): void {
    this.trendingChoice.set(enabled);
    const key = this.trendingCacheKey();
    if (key === null) return;
    if (enabled) localStorage.removeItem(key);
    else localStorage.setItem(key, 'true');
  }

  private trendingCachedHidden(): boolean {
    const key = this.trendingCacheKey();
    return key !== null && localStorage.getItem(key) === 'true';
  }
}
