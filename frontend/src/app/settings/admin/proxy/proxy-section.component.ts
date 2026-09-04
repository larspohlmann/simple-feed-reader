import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  linkedSignal,
  signal,
} from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { ButtonComponent } from '../../../shared/button/button.component';
import { ErrorBannerComponent } from '../../../shared/error-banner/error-banner.component';
import { IconComponent } from '../../../shared/icon/icon.component';
import { InfoTipComponent } from '../../../shared/info-tip/info-tip.component';
import { PasswordInputComponent } from '../../../shared/password-input/password-input.component';
import { SettingsGroupComponent } from '../../../shared/settings/settings-group/settings-group.component';
import { SettingsRowComponent } from '../../../shared/settings/settings-row/settings-row.component';
import { SettingsSaveBarComponent } from '../../../shared/settings/save-bar/save-bar.component';
import { SettingsStackComponent } from '../../../shared/settings/stack/settings-stack.component';
import { ToggleComponent } from '../../../shared/toggle/toggle.component';
import { toastOnSaved } from '../../../shared/toast/saved-toast';
import { ProxySettingsService, ProxyType } from './proxy-settings.service';

/** The SOCKS5 well-known port, mirroring the backend's ProxyConnection::DEFAULT_PORT. */
const DEFAULT_PORT = 1080;

/** The admin "Proxy" settings section (#490), on the grouped design language
 *  from #541: instant enable/type/direct-fallback controls above the typed
 *  host/port/credentials fields, which sit behind the shared save bar.
 *
 *  Test probes the last-*saved* config, so it disables itself while the draft
 *  is dirty -- testing an unsaved edit would test the wrong thing. The enable
 *  toggle stays off until a host is on record: egress with nothing to route
 *  through isn't a valid state, so it's disabled rather than left to fail
 *  server-side. */
@Component({
  selector: 'app-proxy-section',
  imports: [
    ButtonComponent,
    ErrorBannerComponent,
    IconComponent,
    InfoTipComponent,
    PasswordInputComponent,
    SettingsGroupComponent,
    SettingsRowComponent,
    SettingsSaveBarComponent,
    SettingsStackComponent,
    ToggleComponent,
    TranslocoPipe,
  ],
  providers: [ProxySettingsService],
  templateUrl: './proxy-section.component.html',
  styleUrl: './proxy-section.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProxySectionComponent {
  readonly svc = inject(ProxySettingsService);

  // Instant fields: persisted the moment they change, never held in the draft.
  readonly enabled = linkedSignal<boolean>(() => this.svc.state()?.enabled ?? false);
  readonly directFallback = linkedSignal<boolean>(() => this.svc.state()?.directFallback ?? true);
  readonly type = linkedSignal<ProxyType>(() => this.svc.state()?.type ?? 'SOCKS5');
  readonly remoteDns = linkedSignal<boolean>(() => this.svc.state()?.remoteDns ?? false);

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
  /** Never seeded from server truth -- the API never returns the secret. */
  readonly password = signal('');

  readonly configured = computed(() => (this.svc.state()?.host ?? '') !== '');
  /** Only SOCKS5 leaves the client a choice -- an HTTP proxy always resolves
   *  the name itself, so the switch would describe nothing on that type. */
  readonly dnsIsChoosable = computed(() => this.type() === 'SOCKS5');
  /** The probe runs against the SAVED row, so a pending edit would test
   *  something other than what is on screen. */
  readonly canTest = computed(() => this.configured() && !this.svc.dirty());
  /** Empty exactly when no password is stored, so it doubles as the "is one on
   *  record?" test the password field's placeholder needs. */
  readonly passwordHint = computed(() => this.svc.state()?.passwordHint ?? '');
  readonly probe = this.svc.probe;

  readonly failureMessage = computed(() => {
    const failure = this.svc.failure();
    return failure ? (failure.detail ?? failure.title) : null;
  });

  readonly typeOptions: readonly ProxyType[] = ['SOCKS5', 'HTTP'];

  constructor() {
    this.svc.load();
    toastOnSaved(this.svc, 'settings.proxy.saved');
  }

  onEnabled(value: boolean): void {
    this.enabled.set(value);
    this.svc.saveInstant({ enabled: value });
  }

  onDirectFallback(value: boolean): void {
    this.directFallback.set(value);
    this.svc.saveInstant({ directFallback: value });
  }

  onRemoteDns(value: boolean): void {
    this.remoteDns.set(value);
    this.svc.saveInstant({ remoteDns: value });
  }

  onType(event: Event): void {
    const value = (event.target as HTMLSelectElement).value as ProxyType;
    this.type.set(value);
    this.svc.saveInstant({ type: value });
  }

  onHost(event: Event): void {
    const value = (event.target as HTMLInputElement).value;
    this.host.set(value);
    this.svc.setTypedField('host', value);
  }

  onPort(event: Event): void {
    const value = +(event.target as HTMLInputElement).value;
    this.port.set(value);
    this.svc.setTypedField('port', value);
  }

  onUsername(event: Event): void {
    const value = (event.target as HTMLInputElement).value;
    this.username.set(value);
    this.svc.setTypedField('username', value);
  }

  /** An empty field means "keep the stored secret", not "clear it" -- the
   *  service never sees a blank password as a real edit. */
  onPassword(event: Event): void {
    const value = (event.target as HTMLInputElement).value;
    this.password.set(value);
    this.svc.setTypedField('password', value === '' ? null : value);
  }

  onSave(): void {
    this.svc.save();
  }

  /** Dropping the draft is enough for the typed inputs: they read it as their
   *  source, so clearing it reseeds them from the last-saved state. The
   *  password is a plain signal with no server source, so it is cleared here. */
  onReset(): void {
    this.svc.discardDraft();
    this.password.set('');
  }

  test(): void {
    this.svc.testConnection();
  }
}
