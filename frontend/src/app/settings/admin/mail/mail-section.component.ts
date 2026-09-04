import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  linkedSignal,
  signal,
} from '@angular/core';
import { Dialog } from '@angular/cdk/dialog';
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

/** The admin "Mail" settings section (#834), a structural twin of the Proxy
 *  section: instant enable/encryption controls above the typed fields behind
 *  the shared save bar. The enable toggle stays off until a host is on record. */
@Component({
  selector: 'app-mail-section',
  imports: [
    ButtonComponent,
    ErrorBannerComponent,
    IconComponent,
    PasswordInputComponent,
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

  readonly configured = computed(() => (this.svc.state()?.host ?? '') !== '');
  /** A staged enable/encryption (see `onEnabled`) counts as dirty too, or the
   *  save bar never appears for the first, row-creating Save. */
  readonly dirty = computed(() => {
    const state = this.svc.state();
    if (!state || state.hasSavedConfig) return this.svc.dirty();

    const toggleStaged = this.enabled() !== state.enabled || this.encryption() !== state.encryption;

    return this.svc.dirty() || toggleStaged;
  });
  /** The probe runs against the SAVED row, so a pending edit would test
   *  something other than what is on screen. */
  readonly canTest = computed(() => this.configured() && !this.dirty());
  /** Empty exactly when no password is stored, so it doubles as the "is one on
   *  record?" test the password field's placeholder needs. */
  readonly passwordHint = computed(() => this.svc.state()?.passwordHint ?? '');
  /** Reset discards the saved override, including the stored password -- only
   *  offer it when there is something to discard and an env fallback to fall
   *  back to. Otherwise it would double as a disguised "disable mail" control
   *  on an install whose env has no real transport. */
  readonly canReset = computed(
    () => !!this.svc.state()?.hasSavedConfig && !!this.svc.state()?.envFallbackConfigured,
  );
  readonly probe = this.svc.probe;

  readonly failureMessage = computed(() => {
    const failure = this.svc.failure();
    return failure ? (failure.detail ?? failure.title) : null;
  });

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
  }

  onPort(event: Event): void {
    const value = +(event.target as HTMLInputElement).value;
    this.port.set(value);
    this.svc.setTypedField('port', value);
  }

  /** An empty field means "keep the stored secret", not "clear it" -- the
   *  service never sees a blank password as a real edit. */
  onPassword(event: Event): void {
    const value = (event.target as HTMLInputElement).value;
    this.password.set(value);
    this.svc.setTypedField('password', value === '' ? null : value);
  }

  /** Carries the staged enable/encryption (see `onEnabled`). A cleared host
   *  never saves as enabled: the toggle is disabled without a host, so a row
   *  stuck on could not be turned off again from the form. */
  onSave(): void {
    this.svc.save({ enabled: this.enabled() && this.host() !== '', encryption: this.encryption() });
  }

  /** Dropping the draft is enough for the typed inputs: they read it as their
   *  source, so clearing it reseeds them from the last-saved state. The
   *  password and the staged enable/encryption have no draft-backed source,
   *  so they are reset here explicitly. */
  onReset(): void {
    this.svc.discardDraft();
    this.password.set('');
    const state = this.svc.state();
    if (state) {
      this.enabled.set(state.enabled);
      this.encryption.set(state.encryption);
    }
  }

  test(): void {
    this.svc.testConnection();
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
      if (confirmed) this.svc.reset();
    });
  }
}
