// src/app/settings/admin/admin-settings/admin-settings.component.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, inject, signal } from '@angular/core';
import { Dialog } from '@angular/cdk/dialog';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { Problem, parseProblem } from '../../../core/problem';
import {
  ConfirmData,
  ConfirmDialogComponent,
} from '../../../shared/confirm-dialog/confirm-dialog.component';
import { DisclosureComponent } from '../../../shared/disclosure/disclosure.component';
import { ErrorBannerComponent } from '../../../shared/error-banner/error-banner.component';
import { SettingsGroupComponent } from '../../../shared/settings/settings-group/settings-group.component';
import { SettingsRowComponent } from '../../../shared/settings/settings-row/settings-row.component';
import { SettingsStackComponent } from '../../../shared/settings/stack/settings-stack.component';
import { SkeletonComponent } from '../../../shared/skeleton/skeleton.component';
import { ToggleComponent } from '../../../shared/toggle/toggle.component';
import { AdminSettingsApi, InstanceSettings, InstanceSettingsUpdate } from './admin-settings-api';

/** The registration-gate toggles (#224): whether a new signup needs email
 *  confirmation and/or admin approval before it becomes active. Plus the
 *  passkey sign-in switch (#624 follow-up), beside the relying-party fields
 *  it governs. Each toggle saves immediately on change, mirroring the admin
 *  user queue's row actions — there is no separate save step to forget. */
@Component({
  selector: 'app-admin-settings',
  imports: [
    DisclosureComponent,
    ErrorBannerComponent,
    SettingsGroupComponent,
    SettingsRowComponent,
    SettingsStackComponent,
    SkeletonComponent,
    ToggleComponent,
    TranslocoPipe,
  ],
  templateUrl: './admin-settings.component.html',
  styleUrl: './admin-settings.component.scss',
})
export class AdminSettingsComponent implements OnInit {
  private readonly api = inject(AdminSettingsApi);
  private readonly dialog = inject(Dialog);
  private readonly i18n = inject(TranslocoService);

  readonly requireEmailConfirmation = signal(false);
  readonly requireApproval = signal(false);
  // The instance-wide passkey sign-in switch (#624 follow-up). Off refuses
  // every passkey endpoint server-side, not just this frontend's own
  // buttons -- see PasskeySignInAvailability. Initial value matches the
  // backend default -- false, off until an admin opts in (addendum) -- for
  // the instant before load() below replaces it with the real value; see
  // InstanceSetting::$passkeySignInEnabled's docblock for the full list of
  // five places this default has to agree.
  readonly passkeySignInEnabled = signal(false);
  // The external base URL for links in outgoing email; null falls back to the
  // APP_FRONTEND_URL deploy env (#636).
  readonly publicBaseUrl = signal<string | null>(null);
  // The stored passkey relying-party overrides; null falls back to the
  // derived host / the "Simple Feed Reader" default respectively (#624).
  readonly passkeyRpId = signal<string | null>(null);
  readonly passkeyRpName = signal<string | null>(null);
  // Read-only: what the server actually uses right now. Feeds the RP id
  // field's placeholder and its description, so an admin who leaves the
  // field empty still sees the real value.
  readonly passkeyRpIdEffective = signal('');
  // mailEnabled reflects the deploy-time MAIL_DISABLED flag (#230), not a
  // toggle the admin can flip — it only explains why the email-confirmation
  // switch is disabled.
  readonly mailEnabled = signal(false);
  readonly loading = signal(false);
  readonly error = signal<Problem | null>(null);

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.get().subscribe({
      next: (settings) => {
        this.applySettings(settings);
        this.loading.set(false);
      },
      error: (failure: HttpErrorResponse) => {
        this.error.set(parseProblem(failure));
        this.loading.set(false);
      },
    });
  }

  toggleEmailConfirmation(): void {
    this.save({
      ...this.currentUpdate(),
      requireEmailConfirmation: !this.requireEmailConfirmation(),
    });
  }

  toggleApproval(): void {
    this.save({ ...this.currentUpdate(), requireApproval: !this.requireApproval() });
  }

  togglePasskeySignIn(): void {
    this.save({ ...this.currentUpdate(), passkeySignInEnabled: !this.passkeySignInEnabled() });
  }

  savePublicBaseUrl(value: string): void {
    this.save({ ...this.currentUpdate(), publicBaseUrl: emptyToNull(value) });
  }

  savePasskeyRpId(value: string): void {
    this.save({ ...this.currentUpdate(), passkeyRpId: emptyToNull(value) });
  }

  savePasskeyRpName(value: string): void {
    this.save({ ...this.currentUpdate(), passkeyRpName: emptyToNull(value) });
  }

  private currentUpdate(): InstanceSettingsUpdate {
    return {
      requireEmailConfirmation: this.requireEmailConfirmation(),
      requireApproval: this.requireApproval(),
      publicBaseUrl: this.publicBaseUrl(),
      passkeyRpId: this.passkeyRpId(),
      passkeyRpName: this.passkeyRpName(),
      invalidateExistingPasskeys: false,
      passkeySignInEnabled: this.passkeySignInEnabled(),
    };
  }

  private save(update: InstanceSettingsUpdate): void {
    this.error.set(null);
    this.api.update(update).subscribe({
      next: (settings) => this.applySettings(settings),
      error: (failure: HttpErrorResponse) => this.handleSaveError(failure, update),
    });
  }

  /** A relying-party id change that would orphan enrolled passkeys comes back
   *  as a 409 quoting the count (RelyingPartyChangeRequiresConfirmationException).
   *  Every other failure -- including the 422 a disallowed id gets -- renders
   *  through the plain error banner, same as any other row on this page. */
  private handleSaveError(failure: HttpErrorResponse, update: InstanceSettingsUpdate): void {
    const problem = parseProblem(failure);
    if (failure.status === 409 && problem.invalidatedPasskeyCount !== undefined) {
      this.confirmInvalidation(update, problem.invalidatedPasskeyCount);
      return;
    }
    this.error.set(problem);
  }

  private confirmInvalidation(
    update: InstanceSettingsUpdate,
    invalidatedPasskeyCount: number,
  ): void {
    const data: ConfirmData = {
      title: this.i18n.translate('settings.instance.passkeyInvalidateTitle'),
      message: this.i18n.translate('settings.instance.passkeyInvalidateMessage', {
        count: invalidatedPasskeyCount,
      }),
      confirmLabel: this.i18n.translate('settings.instance.passkeyInvalidateConfirm'),
      danger: true,
    };
    const ref = this.dialog.open<boolean>(ConfirmDialogComponent, {
      data,
      role: 'alertdialog',
      panelClass: 'app-dialog',
    });
    ref.closed.subscribe((confirmed) => {
      if (confirmed) this.save({ ...update, invalidateExistingPasskeys: true });
    });
  }

  private applySettings(settings: InstanceSettings): void {
    this.requireEmailConfirmation.set(settings.requireEmailConfirmation);
    this.requireApproval.set(settings.requireApproval);
    this.mailEnabled.set(settings.mailEnabled);
    this.publicBaseUrl.set(settings.publicBaseUrl);
    this.passkeyRpId.set(settings.passkeyRpId);
    this.passkeyRpName.set(settings.passkeyRpName);
    this.passkeyRpIdEffective.set(settings.passkeyRpIdEffective);
    this.passkeySignInEnabled.set(settings.passkeySignInEnabled);
  }
}

/** The client sends `null`, not `''`, to restore whatever fallback the server
 *  applies when a nullable settings field is left empty. */
function emptyToNull(value: string): string | null {
  const trimmed = value.trim();
  return trimmed === '' ? null : trimmed;
}
