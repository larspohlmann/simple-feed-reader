// src/app/settings/passkeys-group.component.ts
import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { Dialog } from '@angular/cdk/dialog';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { catchError, of } from 'rxjs';
import { AuthService } from '../core/auth.service';
import { LanguageService } from '../core/language.service';
import { toEnrolFailureProblem } from '../core/passkey-enrol-failure';
import { PasskeyService, PasskeySummary } from '../core/passkey.service';
import { Problem, parseProblem } from '../core/problem';
import { isPasskeySupported } from '../core/webauthn';
import { formatDateOr, formatLongDate } from '../reader/format';
import { SetupService } from '../setup/setup.service';
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
 * dead end with no way to act on it. Absent too when
 * `SetupService.passkeySignInAvailable` is not `true` (#624 follow-up): a
 * user who enrols while the instance has sign-in turned off ends up with a
 * credential they can never use. Fails CLOSED, unlike the login page's
 * `mailEnabled`/`passkeySignInAvailable` convention: showing an *Add a
 * passkey* action that then produces a dead credential is worse here than a
 * moment's extra hiding while the flag loads.
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
  private readonly authService = inject(AuthService);
  private readonly i18n = inject(TranslocoService);
  private readonly language = inject(LanguageService);
  private readonly dialog = inject(Dialog);
  private readonly setup = inject(SetupService);

  /** Read once: a browser does not gain or lose WebAuthn support mid-session,
   *  so there is nothing to react to by making this a signal. */
  protected readonly isSupported = isPasskeySupported();

  /** See the class docblock for why this fails closed. This route is never
   *  behind `setupRedirectGuard` -- the constructor below triggers the same
   *  `ensureLoaded()` that guard runs, so a cached load resolves the signal
   *  synchronously and an uncached one resolves it the moment the response
   *  arrives. */
  protected readonly visible = computed(
    () => this.isSupported && this.setup.passkeySignInAvailable() === true,
  );

  readonly passkeys = signal<PasskeySummary[]>([]);
  /**
   * One slot, because loading, adding and removing are mutually exclusive
   * user-triggered flows that render their failure in the same place. Each
   * clears it on entry, so the banner always describes the last thing the
   * user actually did rather than stacking a stale one above it.
   */
  readonly error = signal<Problem | null>(null);
  readonly adding = signal(false);

  constructor() {
    if (!this.isSupported) return;
    // catchError mirrors setupRedirectGuard/requireSetupGuard's own handling
    // of this exact observable (fix round 1) -- an uncaught failure here
    // would otherwise throw on every Settings visit rather than just
    // leaving the group hidden the way a `false`/null availability already
    // does.
    this.setup
      .ensureLoaded()
      .pipe(catchError(() => of(false)))
      .subscribe(() => {
        if (this.visible()) this.refresh();
      });
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
    this.error.set(null);
    this.passkeyService.remove(passkey.id).subscribe({
      next: () => this.refresh(),
      error: (failure: HttpErrorResponse) => this.error.set(parseProblem(failure)),
    });
  }

  /** A successful enrolment here stamps the offer flag server-side as a side
   *  effect (`AttestationVerifier::persist()`, #624 design spec §5.2), the
   *  same as it does from the first-login offer dialog. Without the local
   *  signal update below, a stale `passkeyOfferAnswered: false` survives
   *  until the next full reload -- long enough for `ReaderShellComponent` to
   *  reopen the offer for a passkey that already exists (finding 1). */
  private async enrolWith(label: string): Promise<void> {
    this.error.set(null);
    try {
      await this.passkeyService.enrol(label);
      this.authService.markPasskeyOfferAnswered();
      this.refresh();
    } catch (error) {
      this.handleEnrolFailure(error as Problem);
    } finally {
      this.adding.set(false);
    }
  }

  private refresh(): void {
    this.error.set(null);
    this.passkeyService.list().subscribe({
      next: (list) => this.passkeys.set(list),
      error: (failure: HttpErrorResponse) => this.error.set(parseProblem(failure)),
    });
  }

  /** Shared with `PasskeyOfferDialogComponent` -- both run the identical
   *  ceremony and so face the identical failure shapes; see
   *  `toEnrolFailureProblem()`'s own docblock for the branch-by-branch
   *  reasoning (#624 finding 5). */
  private handleEnrolFailure(problem: Problem): void {
    const display = toEnrolFailureProblem(problem, this.i18n);
    if (display) this.error.set(display);
  }
}
