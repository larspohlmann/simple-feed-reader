// src/app/settings/passkeys-group.component.ts
import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { LanguageService } from '../core/language.service';
import { PasskeyService, PasskeySummary } from '../core/passkey.service';
import { Problem, parseProblem } from '../core/problem';
import { isPasskeySupported } from '../core/webauthn';
import { formatDateOr, formatLongDate } from '../reader/format';
import { ButtonComponent } from '../shared/button/button.component';
import { ErrorBannerComponent } from '../shared/error-banner/error-banner.component';
import { IconButtonDirective } from '../shared/icon-button/icon-button.directive';
import { IconComponent } from '../shared/icon/icon.component';
import { SettingsGroupComponent } from '../shared/settings/settings-group/settings-group.component';
import { SettingsRowComponent } from '../shared/settings/settings-row/settings-row.component';

/**
 * The Settings -> Account passkeys group (#624): the credentials enrolled on
 * this account, an "Add a passkey" action and a per-row remove. A sibling of
 * `AccountSectionComponent` rather than more markup inside it, so that file
 * stays small -- see its own docblock.
 *
 * Absent entirely when `isPasskeySupported()` is false: a browser with no
 * WebAuthn support cannot enrol or use one, so offering the group would be a
 * dead end with no way to act on it.
 */
@Component({
  selector: 'app-passkeys-group',
  imports: [
    ButtonComponent,
    ErrorBannerComponent,
    IconButtonDirective,
    IconComponent,
    SettingsGroupComponent,
    SettingsRowComponent,
    TranslocoPipe,
  ],
  templateUrl: './passkeys-group.component.html',
  styleUrl: './passkeys-group.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PasskeysGroupComponent {
  private readonly passkeyService = inject(PasskeyService);
  private readonly i18n = inject(TranslocoService);
  private readonly language = inject(LanguageService);

  /** Read once: a browser does not gain or lose WebAuthn support mid-session,
   *  so there is nothing to react to by making this a signal. */
  protected readonly isSupported = isPasskeySupported();

  readonly passkeys = signal<PasskeySummary[]>([]);
  readonly loadError = signal<Problem | null>(null);
  readonly addError = signal<Problem | null>(null);
  readonly removeError = signal<Problem | null>(null);
  readonly adding = signal(false);

  constructor() {
    if (this.isSupported) this.refresh();
  }

  /** One row's meta line: when it was added, and when it was last used -- or
   *  that it never was, so the row never renders blank (`lastUsedAt` is
   *  nullable). */
  meta(passkey: PasskeySummary): string {
    const locale = this.language.lang();
    const lastUsed = formatDateOr(
      passkey.lastUsedAt,
      locale,
      this.i18n.translate('settings.passkeys.never'),
    );
    return this.i18n.translate('settings.passkeys.meta', {
      created: formatLongDate(passkey.createdAt, locale),
      lastUsed,
    });
  }

  async add(): Promise<void> {
    this.addError.set(null);
    this.adding.set(true);
    try {
      await this.passkeyService.enrol(this.i18n.translate('settings.passkeys.defaultLabel'));
      this.refresh();
    } catch (error) {
      this.handleEnrolFailure(error as Problem);
    } finally {
      this.adding.set(false);
    }
  }

  remove(passkey: PasskeySummary): void {
    this.removeError.set(null);
    this.passkeyService.remove(passkey.id).subscribe({
      next: () => this.refresh(),
      error: (failure: HttpErrorResponse) => this.removeError.set(parseProblem(failure)),
    });
  }

  private refresh(): void {
    this.loadError.set(null);
    this.passkeyService.list().subscribe({
      next: (list) => this.passkeys.set(list),
      error: (failure: HttpErrorResponse) => this.loadError.set(parseProblem(failure)),
    });
  }

  /** A cancelled ceremony (the user dismissed the platform sheet, or it timed
   *  out) is not a failure to report -- `PasskeyService`'s own docblock names
   *  `NotAllowedError` as exactly that case. Anything else -- an authenticator
   *  already enrolled, a rejected HTTP call -- is shown. */
  private handleEnrolFailure(problem: Problem): void {
    if (problem.type === 'NotAllowedError') return;
    this.addError.set(problem);
  }
}
