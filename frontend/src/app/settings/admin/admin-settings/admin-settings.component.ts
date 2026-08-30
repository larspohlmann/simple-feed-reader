// src/app/settings/admin/admin-settings/admin-settings.component.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, computed, inject, linkedSignal, signal } from '@angular/core';
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
import { SettingsSaveBarComponent } from '../../../shared/settings/save-bar/save-bar.component';
import { SettingsStackComponent } from '../../../shared/settings/stack/settings-stack.component';
import { SkeletonComponent } from '../../../shared/skeleton/skeleton.component';
import { ToggleComponent } from '../../../shared/toggle/toggle.component';
import { AdminSettingsApi, InstanceSettings, InstanceSettingsUpdate } from './admin-settings-api';

/** The registration-gate toggles (#224): whether a new signup needs email
 *  confirmation and/or admin approval before it becomes active. Plus the
 *  passkey sign-in switch (#624 follow-up), beside the relying-party fields
 *  it governs. Save by control type, per docs/design-language.md: the toggles
 *  persist on change, the text fields are dirty-tracked behind the save bar. */
@Component({
  selector: 'app-admin-settings',
  imports: [
    DisclosureComponent,
    ErrorBannerComponent,
    SettingsGroupComponent,
    SettingsRowComponent,
    SettingsSaveBarComponent,
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
  // The instance-wide passkey sign-in switch (#624). Off refuses every
  // passkey endpoint server-side, not just this frontend's own buttons --
  // see PasskeySignInAvailability. Initial value matches the backend
  // default, false, for the instant before load() below replaces it with
  // the real value.
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
  /** A failed load leaves nothing to render, so it replaces the form. A failed
   *  save must not: the form still holds the edit that caused it. */
  readonly loadError = signal<Problem | null>(null);
  readonly saveError = signal<Problem | null>(null);
  readonly saving = signal(false);

  // Drafts for the three text fields. Reseeded whenever server truth changes,
  // so a save or a reload adopts it, while an edit the admin has typed but not
  // saved survives an unrelated instant toggle.
  readonly publicBaseUrlDraft = linkedSignal(() => this.publicBaseUrl() ?? '');
  readonly passkeyRpIdDraft = linkedSignal(() => this.passkeyRpId() ?? '');
  readonly passkeyRpNameDraft = linkedSignal(() => this.passkeyRpName() ?? '');

  readonly dirty = computed(
    () =>
      emptyToNull(this.publicBaseUrlDraft()) !== this.publicBaseUrl() ||
      emptyToNull(this.passkeyRpIdDraft()) !== this.passkeyRpId() ||
      emptyToNull(this.passkeyRpNameDraft()) !== this.passkeyRpName(),
  );

  /** Prefers the server's per-field messages: the shared 422 detail only says
   *  "One or more fields are invalid.", which names neither field nor reason. */
  readonly saveErrorMessage = computed(() => {
    const problem = this.saveError();
    if (!problem) return null;

    const fieldMessages = Object.values(problem.errors ?? {}).flat();
    return fieldMessages.length > 0 ? fieldMessages.join(' ') : (problem.detail ?? problem.title);
  });

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.loadError.set(null);
    this.saveError.set(null);
    this.api.get().subscribe({
      next: (settings) => {
        this.applySettings(settings);
        this.loading.set(false);
      },
      error: (failure: HttpErrorResponse) => {
        this.loadError.set(parseProblem(failure));
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

  onSave(): void {
    this.save({
      ...this.currentUpdate(),
      publicBaseUrl: emptyToNull(this.publicBaseUrlDraft()),
      passkeyRpId: emptyToNull(this.passkeyRpIdDraft()),
      passkeyRpName: emptyToNull(this.passkeyRpNameDraft()),
    });
  }

  onReset(): void {
    this.publicBaseUrlDraft.set(this.publicBaseUrl() ?? '');
    this.passkeyRpIdDraft.set(this.passkeyRpId() ?? '');
    this.passkeyRpNameDraft.set(this.passkeyRpName() ?? '');
    this.saveError.set(null);
  }

  /** The text fields read server truth, not the drafts: this is the body a
   *  toggle sends, and a toggle must not smuggle an unsaved text edit with it. */
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
    this.saveError.set(null);
    this.saving.set(true);
    this.api.update(update).subscribe({
      next: (settings) => {
        this.applySettings(settings);
        this.saving.set(false);
      },
      error: (failure: HttpErrorResponse) => {
        this.handleSaveError(failure, update);
        this.saving.set(false);
      },
    });
  }

  /** A relying-party id change that would orphan enrolled passkeys comes back
   *  as a 409 quoting the count (RelyingPartyChangeRequiresConfirmationException).
   *  Every other failure -- including the 422 a disallowed id gets -- renders
   *  inline above the save bar, with the form and the edit still on screen. */
  private handleSaveError(failure: HttpErrorResponse, update: InstanceSettingsUpdate): void {
    const problem = parseProblem(failure);
    if (failure.status === 409 && problem.invalidatedPasskeyCount !== undefined) {
      this.confirmInvalidation(update, problem.invalidatedPasskeyCount);
      return;
    }
    this.saveError.set(problem);
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
