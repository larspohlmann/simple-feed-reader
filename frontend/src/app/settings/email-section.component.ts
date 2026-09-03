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
/** The look-back window the backend accepts, mirroring `SendTestDigestRequest`'s
 *  `Assert\Range` (#636). The form shows this range and blocks a send outside it,
 *  so the server's 422 is never the way the user learns the limit. */
const MIN_TEST_MAIL_DAYS = 1;
const MAX_TEST_MAIL_DAYS = 30;

/** How an inline feedback line reads: neutral info, a positive confirmation, or
 *  an error. Drives both the colour and the icon so a failure never shows in the
 *  neutral/positive styling (#636). */
type FeedbackSeverity = 'info' | 'success' | 'error';

interface TestMailFeedback {
  readonly key: string;
  readonly severity: FeedbackSeverity;
  readonly icon: string;
}

/** Maps a test-mail result to the message, severity and icon that report it. */
const TEST_MAIL_FEEDBACK: Record<DigestTestMailResult, TestMailFeedback> = {
  sent: { key: 'settings.email.testMailSent', severity: 'success', icon: 'check_circle' },
  empty: { key: 'settings.email.testMailEmpty', severity: 'info', icon: 'info' },
  rateLimited: { key: 'settings.email.testMailRateLimited', severity: 'error', icon: 'error' },
  failed: { key: 'settings.email.testMailFailed', severity: 'error', icon: 'error' },
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

/** The email digest settings section. Gated behind two account-level
 *  preconditions the user can't fix from a row toggle -- can this instance
 *  send mail, and is the address verified -- so it renders one of three
 *  states instead of disabling rows piecemeal. The included-searches list
 *  and test-mail row (#636 task 22) render only in the `ready` state. */
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
  readonly minTestMailDays = MIN_TEST_MAIL_DAYS;
  readonly maxTestMailDays = MAX_TEST_MAIL_DAYS;
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

  /** True when the day count is outside the accepted range, so the form can show
   *  the reason and block the send before it reaches the backend's 422. */
  readonly testMailDaysInvalid = computed(() => {
    const days = this.testMailDays();
    return !Number.isInteger(days) || days < MIN_TEST_MAIL_DAYS || days > MAX_TEST_MAIL_DAYS;
  });

  readonly testMailFeedback = computed<TestMailFeedback | null>(() => {
    const result = this.testMailResult();
    return result === null ? null : TEST_MAIL_FEEDBACK[result];
  });

  constructor() {
    this.searches.load();
  }

  onIncludeInDigest(id: number, included: boolean): void {
    this.searches.setIncludeInDigest(id, included);
  }

  onTestMailDays(event: Event): void {
    this.testMailDays.set(Math.round(+(event.target as HTMLInputElement).value));
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

  onFormat(event: Event): void {
    const format = (event.target as HTMLSelectElement).value as 'html' | 'text';
    this.digest.setFormat(format);
  }
}
