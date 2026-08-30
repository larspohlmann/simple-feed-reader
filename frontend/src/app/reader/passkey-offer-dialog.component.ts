// src/app/reader/passkey-offer-dialog.component.ts
import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { A11yModule } from '@angular/cdk/a11y';
import { DialogRef } from '@angular/cdk/dialog';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { AuthService } from '../core/auth.service';
import { defaultPasskeyName } from '../core/passkey-device-name';
import { toEnrolFailureProblem } from '../core/passkey-enrol-failure';
import { PasskeyService } from '../core/passkey.service';
import { Problem } from '../core/problem';
import { CONFIRMATION_DURATION_MS, ToastService } from '../shared/toast/toast.service';
import { ButtonComponent } from '../shared/button/button.component';
import { ErrorBannerComponent } from '../shared/error-banner/error-banner.component';
import { OverlayPanelComponent } from '../shared/overlay-panel/overlay-panel.component';

type OfferStage = 'offer' | 'declined';

/**
 * The first-login passkey offer (#624): shown once, on the first reader boot
 * where the account has not answered it yet -- `ReaderShellComponent`'s own
 * gating effect is the only place that opens this, and its docblock names
 * the conditions that decide when.
 *
 * Two stages, not a route of their own:
 *
 *  - "offer" -- *Set up a passkey* runs the identical ceremony
 *    `PasskeysGroupComponent` runs, defaulting the label the same way its own
 *    naming dialog does (`defaultPasskeyName`) rather than asking for one --
 *    a first-ever passkey needs no disambiguating name yet. On success the
 *    enrol endpoint has already stamped the flag server-side, so only the
 *    local signal needs to catch up, never a second POST. A cancelled sheet
 *    (`NotAllowedError`) is not a failure to report -- mirrors
 *    `PasskeysGroupComponent.handleEnrolFailure()` -- and does not count as
 *    an answer. Any other failure stays on screen with its message, *Not
 *    now* still available.
 *  - "declined" -- reached by *Not now*, names the Settings path. One OK
 *    button.
 *
 * Every way out counts as an answer exactly once: the accept path marks it
 * locally on success, declining marks it the moment this stage opens (not
 * when OK is pressed, so an Escape here still counts), and the
 * constructor's `closed` subscription is the fallback for a close that
 * chose neither -- Escape or the backdrop from the offer stage. `answered`
 * guards all three so they never race each other into a duplicate write.
 */
@Component({
  selector: 'app-passkey-offer-dialog',
  imports: [
    A11yModule,
    ButtonComponent,
    ErrorBannerComponent,
    OverlayPanelComponent,
    TranslocoPipe,
  ],
  templateUrl: './passkey-offer-dialog.component.html',
  styleUrl: './passkey-offer-dialog.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PasskeyOfferDialogComponent {
  readonly ref = inject<DialogRef<void>>(DialogRef);
  private readonly passkeyService = inject(PasskeyService);
  private readonly authService = inject(AuthService);
  private readonly toast = inject(ToastService);
  private readonly i18n = inject(TranslocoService);

  protected readonly stage = signal<OfferStage>('offer');
  protected readonly enrolling = signal(false);
  protected readonly error = signal<Problem | null>(null);

  private answered = false;

  constructor() {
    this.ref.closed.subscribe(() => this.markAnswered());
  }

  async setUpPasskey(): Promise<void> {
    if (this.enrolling()) return;
    this.enrolling.set(true);
    this.error.set(null);
    try {
      await this.passkeyService.enrol(defaultPasskeyName(navigator.userAgent));
      this.answered = true;
      this.authService.markPasskeyOfferAnswered();
      this.toast.show({
        message: this.i18n.translate('reader.passkeyOffer.success'),
        durationMs: CONFIRMATION_DURATION_MS,
      });
      this.ref.close();
    } catch (error) {
      this.handleEnrolFailure(error as Problem);
    } finally {
      this.enrolling.set(false);
    }
  }

  declineForNow(): void {
    this.stage.set('declined');
    this.markAnswered();
  }

  /** Records the answer exactly once, whichever of the several paths --
   *  declining, a successful enrolment, or the constructor's close fallback
   *  -- gets here first. */
  private markAnswered(): void {
    if (this.answered) return;
    this.answered = true;
    this.authService.answerPasskeyOffer().subscribe({ error: () => undefined });
  }

  /** Shared with `PasskeysGroupComponent` -- this runs the identical
   *  ceremony, so it faces the identical failure shapes; see
   *  `toEnrolFailureProblem()`'s own docblock for the branch-by-branch
   *  reasoning, including why it reuses the Settings copies
   *  (`settings.passkeys.alreadyEnrolled`, `settings.passkeys.addFailed`)
   *  rather than second keys with the same words. */
  private handleEnrolFailure(problem: Problem): void {
    const display = toEnrolFailureProblem(problem, this.i18n);
    if (display) this.error.set(display);
  }
}
