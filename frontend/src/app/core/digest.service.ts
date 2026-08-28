// src/app/core/digest.service.ts
import { Injectable, inject, signal } from '@angular/core';
import { Observable } from 'rxjs';
import { CurrentUser } from './auth.service';
import { DIGEST_WRITER, DigestConfig, DigestTestMailResult } from './digest-writer';

const DEFAULT_ENABLED = false;
const DEFAULT_CADENCE = 'daily';
const DEFAULT_SEND_HOUR = 8;
const DEFAULT_WEEKDAY = 1;

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

  readonly enabled = signal(DEFAULT_ENABLED);
  readonly cadence = signal<'daily' | 'weekly'>(DEFAULT_CADENCE);
  readonly sendHour = signal(DEFAULT_SEND_HOUR);
  readonly weekday = signal(DEFAULT_WEEKDAY);

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
    const digest = user.preferences?.digest;
    this.enabled.set(digest?.enabled ?? DEFAULT_ENABLED);
    this.cadence.set(digest?.cadence ?? DEFAULT_CADENCE);
    this.sendHour.set(digest?.sendHour ?? DEFAULT_SEND_HOUR);
    this.weekday.set(digest?.weekday ?? DEFAULT_WEEKDAY);
  }

  /**
   * Drops the signed-out account's cached digest settings. Per-account, like
   * `PreferencesService`: leaving it set would let the next signed-in account
   * see the previous one's values until (or unless) its own `loadMe()`
   * resolves.
   */
  reset(): void {
    this.enabled.set(DEFAULT_ENABLED);
    this.cadence.set(DEFAULT_CADENCE);
    this.sendHour.set(DEFAULT_SEND_HOUR);
    this.weekday.set(DEFAULT_WEEKDAY);
    this.saveFailed.set(false);
  }

  private writeAll(): void {
    this.saveFailed.set(false);

    const config: DigestConfig = {
      enabled: this.enabled(),
      cadence: this.cadence(),
      sendHour: this.sendHour(),
      weekday: this.weekday(),
    };

    this.writer.write(config).subscribe((ok) => {
      if (!ok) this.saveFailed.set(true);
    });
  }
}
