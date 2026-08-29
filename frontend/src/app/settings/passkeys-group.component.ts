// src/app/settings/passkeys-group.component.ts
import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { Dialog } from '@angular/cdk/dialog';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { LanguageService } from '../core/language.service';
import { PasskeyService, PasskeySummary } from '../core/passkey.service';
import { Problem, parseProblem } from '../core/problem';
import { isPasskeySupported } from '../core/webauthn';
import { formatDateOr, formatLongDate } from '../reader/format';
import { ButtonComponent } from '../shared/button/button.component';
import {
  ConfirmData,
  ConfirmDialogComponent,
} from '../shared/confirm-dialog/confirm-dialog.component';
import { ErrorBannerComponent } from '../shared/error-banner/error-banner.component';
import { IconButtonDirective } from '../shared/icon-button/icon-button.directive';
import { IconComponent } from '../shared/icon/icon.component';
import { SettingsGroupComponent } from '../shared/settings/settings-group/settings-group.component';
import { SettingsRowComponent } from '../shared/settings/settings-row/settings-row.component';
import { PasskeyNameDialogComponent } from './passkey-name-dialog.component';

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
  private readonly dialog = inject(Dialog);

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

  /** Opens the naming dialog, then enrols with whatever name the user
   *  confirmed. Naming and enrolling are split across two methods because
   *  they run on two different lifecycles: the dialog closes as soon as a
   *  name is chosen, well before the ceremony -- and its own success or
   *  failure -- resolves.
   *
   *  `adding` flips true for the whole span, dialog included, not just the
   *  ceremony: guarding only the ceremony half left a fast double-click free
   *  to stack two naming dialogs before the first one closes. */
  openAddDialog(): void {
    if (this.adding()) return;
    this.adding.set(true);

    const ref = this.dialog.open<string>(PasskeyNameDialogComponent, { panelClass: 'app-dialog' });
    ref.closed.subscribe((name) => {
      if (name) {
        this.enrolWith(name);
      } else {
        this.adding.set(false);
      }
    });
  }

  /** Removing a passkey is irreversible -- the only way back is physically
   *  re-enrolling that device -- so a stray tap on the row's small icon
   *  button must not do it. Same two-step shape as
   *  `AccountSectionComponent.confirmThenDelete()` and
   *  `ManageActionsService.deleteTag()`: a `ConfirmDialogComponent` naming
   *  the thing about to go, then the actual call only on confirmation. */
  confirmThenRemove(passkey: PasskeySummary): void {
    const data: ConfirmData = {
      title: this.i18n.translate('settings.passkeys.removeTitle'),
      message: this.i18n.translate('settings.passkeys.removeMessage', { label: passkey.label }),
      confirmLabel: this.i18n.translate('settings.passkeys.removeConfirm'),
      danger: true,
    };
    const ref = this.dialog.open<boolean>(ConfirmDialogComponent, {
      data,
      role: 'alertdialog',
      panelClass: 'app-dialog',
    });
    ref.closed.subscribe((confirmed) => {
      if (confirmed) this.remove(passkey);
    });
  }

  private remove(passkey: PasskeySummary): void {
    this.removeError.set(null);
    this.passkeyService.remove(passkey.id).subscribe({
      next: () => this.refresh(),
      error: (failure: HttpErrorResponse) => this.removeError.set(parseProblem(failure)),
    });
  }

  private async enrolWith(label: string): Promise<void> {
    this.addError.set(null);
    try {
      await this.passkeyService.enrol(label);
      this.refresh();
    } catch (error) {
      this.handleEnrolFailure(error as Problem);
    } finally {
      this.adding.set(false);
    }
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
   *  `NotAllowedError` as exactly that case, and it is left alone here.
   *
   *  `InvalidStateError` -- this authenticator is already enrolled on the
   *  account, produced by the server's exclude list -- gets its own
   *  translated, actionable message rather than falling through to the
   *  generic path: the fallback there renders `error.title`, which for a
   *  `DOMException` is the browser's own untranslated, locale-dependent text
   *  (see `PasskeyService.toProblem()`'s docblock). Overwriting `detail`
   *  works because the banner reads `error.detail || error.title`. */
  private handleEnrolFailure(problem: Problem): void {
    if (problem.type === 'NotAllowedError') return;
    if (problem.type === 'InvalidStateError') {
      this.addError.set({
        ...problem,
        detail: this.i18n.translate('settings.passkeys.alreadyEnrolled'),
      });
      return;
    }
    this.addError.set(problem);
  }
}
