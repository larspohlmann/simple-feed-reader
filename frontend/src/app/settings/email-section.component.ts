// src/app/settings/email-section.component.ts
import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { AuthService } from '../core/auth.service';
import { DigestService } from '../core/digest.service';
import { DigestTestMailResult } from '../core/digest-writer';
import { SavedSearchesStore } from '../reader/saved-searches.store';
import { ButtonComponent } from '../shared/button/button.component';
import { IconComponent } from '../shared/icon/icon.component';
import { SettingsGroupComponent } from '../shared/settings/settings-group/settings-group.component';
import { SettingsRowComponent } from '../shared/settings/settings-row/settings-row.component';
import { SettingsStackComponent } from '../shared/settings/stack/settings-stack.component';
import { ToggleComponent } from '../shared/toggle/toggle.component';

const DEFAULT_TEST_MAIL_DAYS = 7;

/** Maps a test-mail result to the i18n key that reports it inline. */
const TEST_MAIL_RESULT_KEYS: Record<DigestTestMailResult, string> = {
  sent: 'settings.email.testMailSent',
  empty: 'settings.email.testMailEmpty',
  rateLimited: 'settings.email.testMailRateLimited',
  failed: 'settings.email.testMailFailed',
};

type EmailSectionState = 'mailDisabled' | 'unverified' | 'ready';

/** The 24 send-hour choices, each labelled as a zero-padded 24-hour clock time
 *  (e.g. "08:00") so the option reads unambiguously as a time of day rather than
 *  a bare number (#636). The value stays the plain hour the backend expects. */
const SEND_HOURS: readonly { value: number; label: string }[] = Array.from(
  { length: 24 },
  (_unused, hour) => ({ value: hour, label: `${String(hour).padStart(2, '0')}:00` }),
);

/** ISO-8601 weekday numbering (1=Mon … 7=Sun), matching `Preferences::getDigestWeekday()`
 *  on the backend -- see `backend/src/Entity/Preferences.php`. */
const WEEKDAYS: readonly { value: number; labelKey: string }[] = [
  { value: 1, labelKey: 'settings.email.weekdayMonday' },
  { value: 2, labelKey: 'settings.email.weekdayTuesday' },
  { value: 3, labelKey: 'settings.email.weekdayWednesday' },
  { value: 4, labelKey: 'settings.email.weekdayThursday' },
  { value: 5, labelKey: 'settings.email.weekdayFriday' },
  { value: 6, labelKey: 'settings.email.weekdaySaturday' },
  { value: 7, labelKey: 'settings.email.weekdaySunday' },
];

/**
 * The email digest settings section. Its controls are gated behind two
 * account-level preconditions the user cannot fix from a row toggle -- whether
 * this instance can send mail at all, and whether the account's own address is
 * verified -- so the section renders one of three states instead of disabling
 * rows piecemeal. The included-searches list and the test-mail row (#636 task
 * 22) render only in the `ready` state, alongside the digest config controls.
 */
@Component({
  selector: 'app-email-section',
  imports: [
    ButtonComponent,
    IconComponent,
    SettingsGroupComponent,
    SettingsRowComponent,
    SettingsStackComponent,
    ToggleComponent,
    TranslocoPipe,
  ],
  templateUrl: './email-section.component.html',
  styleUrl: './email-section.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class EmailSectionComponent {
  private readonly auth = inject(AuthService);
  private readonly searches = inject(SavedSearchesStore);
  readonly digest = inject(DigestService);

  readonly hours = SEND_HOURS;
  readonly weekdays = WEEKDAYS;
  readonly savedSearches = this.searches.savedSearches;

  /** True while a resend request is in flight, so the button can show its
   *  loading state and the user cannot fire a second request by clicking again. */
  readonly resending = signal(false);

  readonly testMailDays = signal(DEFAULT_TEST_MAIL_DAYS);
  /** True while a test-mail request is in flight, mirroring `resending`. */
  readonly sendingTestMail = signal(false);
  readonly testMailResult = signal<DigestTestMailResult | null>(null);

  readonly state = computed<EmailSectionState>(() => {
    const currentUser = this.auth.user();
    if (!currentUser?.mail.enabled) return 'mailDisabled';
    if (!currentUser.emailVerified) return 'unverified';
    return 'ready';
  });

  readonly controlsDisabled = computed(() => this.state() !== 'ready');

  /** Sending a test digest with nothing included would only ever confirm the
   *  empty-result path, so the button stays disabled until at least one saved
   *  search is included. */
  readonly noSearchesIncluded = computed(() =>
    this.savedSearches().every((search) => !search.includeInDigest),
  );

  readonly testMailMessageKey = computed<string | null>(() => {
    const result = this.testMailResult();
    return result === null ? null : TEST_MAIL_RESULT_KEYS[result];
  });

  constructor() {
    this.searches.load();
  }

  onIncludeInDigest(id: number, included: boolean): void {
    this.searches.setIncludeInDigest(id, included);
  }

  onTestMailDays(event: Event): void {
    this.testMailDays.set(+(event.target as HTMLInputElement).value);
  }

  /** `DigestWriter.sendTest()` never errors, but a stubbed caller in a test
   *  or a future writer might -- the `error` handler keeps that from wedging
   *  the button in its loading state. */
  sendTestMail(): void {
    this.sendingTestMail.set(true);
    this.testMailResult.set(null);
    this.digest.sendTest(this.testMailDays()).subscribe({
      next: (result) => {
        this.sendingTestMail.set(false);
        this.testMailResult.set(result);
      },
      error: () => {
        this.sendingTestMail.set(false);
        this.testMailResult.set('failed');
      },
    });
  }

  resend(): void {
    this.resending.set(true);
    this.auth.resendVerification().subscribe({
      next: () => this.resending.set(false),
      error: () => this.resending.set(false),
    });
  }

  onCadence(event: Event): void {
    const cadence = (event.target as HTMLSelectElement).value as 'daily' | 'weekly';
    this.digest.setCadence(cadence);
  }

  onSendHour(event: Event): void {
    this.digest.setSendHour(+(event.target as HTMLSelectElement).value);
  }

  onWeekday(event: Event): void {
    this.digest.setWeekday(+(event.target as HTMLSelectElement).value);
  }
}
