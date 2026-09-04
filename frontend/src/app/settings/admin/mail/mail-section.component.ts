import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  linkedSignal,
  signal,
} from '@angular/core';
import { Dialog } from '@angular/cdk/dialog';
import { NgTemplateOutlet } from '@angular/common';
import { RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { ButtonComponent } from '../../../shared/button/button.component';
import {
  ConfirmDialogComponent,
  ConfirmData,
} from '../../../shared/confirm-dialog/confirm-dialog.component';
import { ErrorBannerComponent } from '../../../shared/error-banner/error-banner.component';
import { IconComponent } from '../../../shared/icon/icon.component';
import { PasswordInputComponent } from '../../../shared/password-input/password-input.component';
import { SettingsGroupComponent } from '../../../shared/settings/settings-group/settings-group.component';
import { SettingsRowComponent } from '../../../shared/settings/settings-row/settings-row.component';
import { SettingsSaveBarComponent } from '../../../shared/settings/save-bar/save-bar.component';
import { SettingsStackComponent } from '../../../shared/settings/stack/settings-stack.component';
import { ToggleComponent } from '../../../shared/toggle/toggle.component';
import { toastOnSaved } from '../../../shared/toast/saved-toast';
import { MailEncryption, MailSettingsService } from './mail-settings.service';

/** The default submission port, mirroring the backend's MailConnection::DEFAULT_PORT. */
const DEFAULT_PORT = 587;

/** Matches the backend DTO property names exactly -- both the client
 *  validation below and the 422 `errors` map key on these names. */
type MailField = 'host' | 'port' | 'username' | 'fromAddress' | 'fromName' | 'password';

/** The admin "Mail" settings section (#834), a structural twin of the Proxy
 *  section: instant enable/encryption controls above the typed fields behind
 *  the shared save bar. The enable toggle stays off until a host is on record. */
@Component({
  selector: 'app-mail-section',
  imports: [
    ButtonComponent,
    ErrorBannerComponent,
    IconComponent,
    NgTemplateOutlet,
    PasswordInputComponent,
    RouterLink,
    SettingsGroupComponent,
    SettingsRowComponent,
    SettingsSaveBarComponent,
    SettingsStackComponent,
    ToggleComponent,
    TranslocoPipe,
  ],
  providers: [MailSettingsService],
  templateUrl: './mail-section.component.html',
  styleUrl: './mail-section.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class MailSectionComponent {
  readonly svc = inject(MailSettingsService);
  private readonly i18n = inject(TranslocoService);
  private readonly dialog = inject(Dialog);

  // Instant fields: persisted the moment they change, never held in the draft.
  readonly enabled = linkedSignal<boolean>(() => this.svc.state()?.enabled ?? false);
  readonly encryption = linkedSignal<MailEncryption>(
    () => this.svc.state()?.encryption ?? 'starttls',
  );

  // Typed fields: held as a pending draft until the explicit Save. Each reads
  // the pending edit first, server truth underneath -- so an instant save,
  // which replaces `state` while the draft stands, can't revert an unsaved edit.
  readonly host = linkedSignal<string>(
    () => this.svc.pending('host') ?? this.svc.state()?.host ?? '',
  );
  readonly port = linkedSignal<number>(
    () => this.svc.pending('port') ?? this.svc.state()?.port ?? DEFAULT_PORT,
  );
  readonly username = linkedSignal<string>(
    () => this.svc.pending('username') ?? this.svc.state()?.username ?? '',
  );
  readonly fromAddress = linkedSignal<string>(
    () => this.svc.pending('fromAddress') ?? this.svc.state()?.fromAddress ?? '',
  );
  readonly fromName = linkedSignal<string>(
    () => this.svc.pending('fromName') ?? this.svc.state()?.fromName ?? '',
  );
  /** Never seeded from server truth -- the API never returns the secret. */
  readonly password = signal('');

  /** Client-side validation, keyed the same as the server's 422 `errors` map
   *  (see `fieldError`) so both sources render through one code path. */
  readonly clientErrors = signal<Partial<Record<MailField, string>>>({});
  /** A server error the user has since edited past -- `svc.failure()` outlives
   *  the field it named, so a per-field dismissal is needed to stop showing it. */
  readonly dismissedServerErrors = signal<Partial<Record<MailField, true>>>({});

  readonly configured = computed(() => (this.svc.state()?.host ?? '') !== '');
  /** A staged enable/encryption/use-proxy (see `onEnabled`/`onUseProxy`) counts
   *  as dirty too, or the save bar never appears for the first, row-creating
   *  Save -- and a proxy-only edit before the row exists could never be saved. */
  readonly dirty = computed(() => {
    const state = this.svc.state();
    if (!state || state.hasSavedConfig) return this.svc.dirty();

    const toggleStaged =
      this.enabled() !== state.enabled ||
      this.encryption() !== state.encryption ||
      this.useProxy() !== state.useProxy;

    return this.svc.dirty() || toggleStaged;
  });
  /** The probe runs against the SAVED row, so a pending edit would test
   *  something other than what is on screen -- and it must also work
   *  against an unsaved env fallback, since there is nothing else to test
   *  until the user overrides it. */
  readonly canTest = computed(
    () =>
      (this.configured() || (this.svc.state()?.envFallbackConfigured ?? false)) && !this.dirty(),
  );
  /** Whether a password is on record -- never any part of it, the API does not
   *  return the secret. */
  readonly passwordSaved = computed(() => this.svc.state()?.hasPassword ?? false);
  /** Reset discards the saved override, including the stored password -- only
   *  offer it when there is something to discard and an env fallback to fall
   *  back to. Otherwise it would double as a disguised "disable mail" control
   *  on an install whose env has no real transport. */
  readonly canReset = computed(
    () => !!this.svc.state()?.hasSavedConfig && !!this.svc.state()?.envFallbackConfigured,
  );
  readonly probe = this.svc.probe;

  /** Set once the user chooses to configure their own transport instead of
   *  the env fallback (see `envManaged`). Irrelevant once a row is saved --
   *  from then on there is always something to edit. */
  readonly overriding = signal(false);
  /** The read-only view: no saved row, an env fallback stands in for it, and
   *  the user has not asked to override it. */
  readonly envManaged = computed(() => {
    const state = this.svc.state();
    return !!state && !state.hasSavedConfig && state.envFallbackConfigured && !this.overriding();
  });
  /** What the env fallback actually sends through, for the read-only panel:
   *  sendmail carries no host, so it names itself instead of an empty line. */
  readonly envTransportSummary = computed(() => {
    const state = this.svc.state();
    if (!state) return '';
    return state.host === ''
      ? this.i18n.translate('settings.mail.env.systemMail')
      : `${state.host}:${state.port} (${state.encryption})`;
  });

  readonly useProxy = linkedSignal<boolean>(() => this.svc.state()?.useProxy ?? false);
  readonly proxyConfigured = computed(() => this.svc.state()?.proxyConfigured ?? false);
  readonly proxyLabel = computed(() => this.svc.state()?.proxyLabel ?? '');

  readonly failureMessage = computed(() => {
    const failure = this.svc.failure();
    return failure ? (failure.detail ?? failure.title) : null;
  });

  /** Google rejects a normal account password once 2-Step Verification is on
   *  and asks for an App Password instead; the raw SMTP reply says so only in
   *  codes (#841). Matching the reason string keeps the actionable hint
   *  frontend-only -- no backend classification of the cause. */
  readonly gmailAppPasswordFailure = computed(() => {
    const current = this.probe();
    if (current.status !== 'error') return false;
    const reason = current.message.toLowerCase();
    return (
      reason.includes('application-specific password') ||
      reason.includes('invalidsecondfactor') ||
      (reason.includes('534') && reason.includes('5.7.9'))
    );
  });

  readonly gmailAppPasswordUrl = 'https://myaccount.google.com/apppasswords';

  readonly encryptionOptions: readonly MailEncryption[] = ['none', 'starttls', 'tls'];

  constructor() {
    this.svc.load();
    toastOnSaved(this.svc, 'settings.mail.saved');
  }

  /** The enable toggle and the encryption select instant-save only once a DB
   *  row exists. Before that, `svc.state()` is the env prefill, and an instant
   *  PUT would persist a host+username row with no password (the password is
   *  never prefilled): a broken authenticated transport overriding a working
   *  env fallback. Until then the value is staged locally and rides along on
   *  the first explicit Save, which also carries the password. */
  onEnabled(value: boolean): void {
    this.enabled.set(value);
    if (this.svc.state()?.hasSavedConfig) {
      this.svc.saveInstant({ enabled: value });
    }
  }

  onEncryption(event: Event): void {
    const value = (event.target as HTMLSelectElement).value as MailEncryption;
    this.encryption.set(value);
    if (this.svc.state()?.hasSavedConfig) {
      this.svc.saveInstant({ encryption: value });
    }
  }

  onTyped(field: 'host' | 'username' | 'fromAddress' | 'fromName', event: Event): void {
    const value = (event.target as HTMLInputElement).value;
    this[field].set(value);
    this.svc.setTypedField(field, value);
    this.clearFieldError(field);
  }

  onPort(event: Event): void {
    const value = +(event.target as HTMLInputElement).value;
    this.port.set(value);
    this.svc.setTypedField('port', value);
    this.clearFieldError('port');
  }

  /** An empty field means "keep the stored secret", not "clear it" -- the
   *  service never sees a blank password as a real edit. */
  onPassword(event: Event): void {
    const value = (event.target as HTMLInputElement).value;
    this.password.set(value);
    this.svc.setTypedField('password', value === '' ? null : value);
    this.clearFieldError('password');
  }

  /** Carries the staged enable/encryption (see `onEnabled`). A cleared host
   *  never saves as enabled: the toggle is disabled without a host, so a row
   *  stuck on could not be turned off again from the form. */
  onSave(): void {
    if (!this.validateBeforeSave()) return;
    this.svc.save({
      enabled: this.enabled() && this.host() !== '',
      encryption: this.encryption(),
      useProxy: this.useProxy(),
    });
  }

  /** Leaves the read-only env view for the editable form, e.g. so the user
   *  can set up their own transport instead of the one the env provides. */
  startOverride(): void {
    this.overriding.set(true);
  }

  onUseProxy(value: boolean): void {
    this.useProxy.set(value);
    if (this.svc.state()?.hasSavedConfig) {
      this.svc.saveInstant({ useProxy: value });
    }
  }

  /** The client error for a field, or -- once no client error stands and the
   *  field has not since been edited past a server rejection -- the matching
   *  entry from the last 422's `errors` map. */
  fieldError(field: MailField): string | null {
    const clientError = this.clientErrors()[field];
    if (clientError) return clientError;
    if (this.dismissedServerErrors()[field]) return null;
    return this.svc.failure()?.errors?.[field]?.join(' ') ?? null;
  }

  isFieldInvalid(field: MailField): boolean {
    return this.fieldError(field) !== null;
  }

  /** Dropping the draft is enough for the typed inputs: they read it as their
   *  source, so clearing it reseeds them from the last-saved state. The
   *  password and the staged enable/encryption/use-proxy have no draft-backed
   *  source, so they are reset here explicitly. */
  onReset(): void {
    this.svc.discardDraft();
    this.password.set('');
    const state = this.svc.state();
    if (state) {
      this.enabled.set(state.enabled);
      this.encryption.set(state.encryption);
      this.useProxy.set(state.useProxy);
    }
  }

  test(): void {
    this.svc.testConnection();
  }

  removePassword(): void {
    this.svc.removePassword();
  }

  /** Rejects an unsendable draft before it reaches the server: an enabled row
   *  with no host, a from-address that is not an email, or a port outside the
   *  valid range. */
  private validateBeforeSave(): boolean {
    const errors: Partial<Record<MailField, string>> = {};
    if (this.enabled() && this.host() === '') {
      errors.host = this.i18n.translate('settings.mail.errors.hostRequired');
    }
    if (this.fromAddress() !== '' && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(this.fromAddress())) {
      errors.fromAddress = this.i18n.translate('settings.mail.errors.emailInvalid');
    }
    if (this.port() < 1 || this.port() > 65535) {
      errors.port = this.i18n.translate('settings.mail.errors.portRange');
    }
    this.clientErrors.set(errors);
    return Object.keys(errors).length === 0;
  }

  private clearFieldError(field: MailField): void {
    this.clientErrors.update((errors) => ({ ...errors, [field]: undefined }));
    this.dismissedServerErrors.update((errors) => ({ ...errors, [field]: true }));
  }

  /** Discards the saved SMTP override, password included -- confirm first so
   *  a stray click can't silently fall the install back to the env transport. */
  confirmThenResetToEnv(): void {
    const data: ConfirmData = {
      title: this.i18n.translate('settings.mail.resetToEnv'),
      message: this.i18n.translate('settings.mail.resetConfirm'),
      confirmLabel: this.i18n.translate('settings.mail.resetToEnv'),
      danger: true,
    };
    const ref = this.dialog.open<boolean>(ConfirmDialogComponent, {
      data,
      role: 'alertdialog',
      panelClass: 'app-dialog',
    });
    ref.closed.subscribe((confirmed) => {
      if (!confirmed) return;
      // Fall back to the read-only env view once the override is gone.
      this.overriding.set(false);
      this.svc.reset();
    });
  }
}
