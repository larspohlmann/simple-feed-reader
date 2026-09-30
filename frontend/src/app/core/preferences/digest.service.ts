import { Injectable, inject, signal } from '@angular/core';
import { Observable } from 'rxjs';
import { CurrentUser, UserDigestPreferences } from '../auth/auth.service';
import { DIGEST_WRITER, DigestConfig, DigestTestMailResult } from './digest-writer';

const DIGEST_DEFAULTS: UserDigestPreferences = {
  enabled: false,
  cadence: 'daily',
  sendHour: 8,
  weekday: 1,
  timezone: 'UTC',
  format: 'html',
};

function withDefaults(stored: Partial<UserDigestPreferences> | undefined): UserDigestPreferences {
  const pick = <Key extends keyof UserDigestPreferences>(key: Key): UserDigestPreferences[Key] =>
    stored?.[key] ?? DIGEST_DEFAULTS[key];
  return {
    enabled: pick('enabled'),
    cadence: pick('cadence'),
    sendHour: pick('sendHour'),
    weekday: pick('weekday'),
    timezone: pick('timezone'),
    format: pick('format'),
  };
}

/**
 * Per-account digest settings, mirroring `PreferencesService`: the account is
 * the source of truth, the signals are a cache the UI reads, and a write
 * applies locally first so a toggle does not wait on the network. Unlike
 * `PreferencesService`, the four fields form one config the backend accepts
 * together, so every setter writes the whole thing back, not just the field
 * that changed.
 */
@Injectable({ providedIn: 'root' })
export class DigestService {
  private readonly writer = inject(DIGEST_WRITER);

  readonly enabled = signal(DIGEST_DEFAULTS.enabled);
  readonly cadence = signal<'daily' | 'weekly'>(DIGEST_DEFAULTS.cadence);
  readonly sendHour = signal(DIGEST_DEFAULTS.sendHour);
  readonly weekday = signal(DIGEST_DEFAULTS.weekday);
  /** The instance's configured timezone, read-only instance config adopted
   *  from the account -- never written back through `writeAll()`. */
  readonly timezone = signal(DIGEST_DEFAULTS.timezone);
  readonly format = signal<'html' | 'text'>(DIGEST_DEFAULTS.format);

  /** True when the value applied locally but the account write failed. */
  readonly saveFailed = signal(false);

  setEnabled(enabled: boolean): void {
    this.enabled.set(enabled);
    this.writeAll();
  }

  setCadence(cadence: 'daily' | 'weekly'): void {
    this.cadence.set(cadence);
    this.writeAll();
  }

  setSendHour(sendHour: number): void {
    this.sendHour.set(sendHour);
    this.writeAll();
  }

  setWeekday(weekday: number): void {
    this.weekday.set(weekday);
    this.writeAll();
  }

  setFormat(format: 'html' | 'text'): void {
    this.format.set(format);
    this.writeAll();
  }

  /** Sends a one-off test digest; the caller (the email section) owns the
   *  in-flight and result state, the same split as `AuthService.resendVerification()`. */
  sendTest(days: number): Observable<DigestTestMailResult> {
    return this.writer.sendTest(days);
  }

  /**
   * Take the account's values, typically right after `AuthService.loadMe()`.
   * Caches only — a value that just arrived from the server is never PATCHed
   * straight back to it. Reads through optional chaining rather than trusting
   * `CurrentUser`'s type: this runs inside `loadMe()`'s shared `tap()`, so a
   * missing or partial `digest` on a malformed or older payload must fall
   * back per-field instead of throwing and aborting the adopters after it.
   */
  adopt(user: CurrentUser): void {
    this.apply(withDefaults(user.preferences?.digest));
  }

  /**
   * Drops the signed-out account's cached digest settings. Per-account, like
   * `PreferencesService`: leaving it set would let the next signed-in account
   * see the previous one's values until (or unless) its own `loadMe()`
   * resolves.
   */
  reset(): void {
    this.apply(DIGEST_DEFAULTS);
    this.saveFailed.set(false);
  }

  private apply(preferences: UserDigestPreferences): void {
    this.enabled.set(preferences.enabled);
    this.cadence.set(preferences.cadence);
    this.sendHour.set(preferences.sendHour);
    this.weekday.set(preferences.weekday);
    this.timezone.set(preferences.timezone);
    this.format.set(preferences.format);
  }

  private writeAll(): void {
    this.saveFailed.set(false);

    const config: DigestConfig = {
      enabled: this.enabled(),
      cadence: this.cadence(),
      sendHour: this.sendHour(),
      weekday: this.weekday(),
      format: this.format(),
    };

    this.writer.write(config).subscribe((ok) => {
      if (!ok) this.saveFailed.set(true);
    });
  }
}
