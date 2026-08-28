// src/app/settings/email-section.component.ts
import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { AuthService } from '../core/auth.service';
import { DigestService } from '../core/digest.service';
import { ButtonComponent } from '../shared/button/button.component';
import { IconComponent } from '../shared/icon/icon.component';
import { SettingsGroupComponent } from '../shared/settings/settings-group/settings-group.component';
import { SettingsRowComponent } from '../shared/settings/settings-row/settings-row.component';
import { SettingsStackComponent } from '../shared/settings/stack/settings-stack.component';
import { ToggleComponent } from '../shared/toggle/toggle.component';

type EmailSectionState = 'mailDisabled' | 'unverified' | 'ready';

const SEND_HOURS = Array.from({ length: 24 }, (_unused, hour) => hour);

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
 * rows piecemeal. The included-searches list and the test-mail row that will
 * eventually join it are a follow-up (#636 task 22), kept out here so this
 * stays reviewable as the gated shell alone.
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
  readonly digest = inject(DigestService);

  readonly hours = SEND_HOURS;
  readonly weekdays = WEEKDAYS;

  /** True while a resend request is in flight, so the button can show its
   *  loading state and the user cannot fire a second request by clicking again. */
  readonly resending = signal(false);

  readonly state = computed<EmailSectionState>(() => {
    const currentUser = this.auth.user();
    if (!currentUser?.mail.enabled) return 'mailDisabled';
    if (!currentUser.emailVerified) return 'unverified';
    return 'ready';
  });

  readonly controlsDisabled = computed(() => this.state() !== 'ready');

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
