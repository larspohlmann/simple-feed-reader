// src/app/settings/admin/proxy/proxy-section.component.ts
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  linkedSignal,
} from '@angular/core';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { ButtonComponent } from '../../../shared/button/button.component';
import { ErrorBannerComponent } from '../../../shared/error-banner/error-banner.component';
import { InfoTipComponent } from '../../../shared/info-tip/info-tip.component';
import { SettingsGroupComponent } from '../../../shared/settings/settings-group/settings-group.component';
import { SettingsRowComponent } from '../../../shared/settings/settings-row/settings-row.component';
import { SettingsSaveBarComponent } from '../../../shared/settings/save-bar/save-bar.component';
import { ToggleComponent } from '../../../shared/toggle/toggle.component';
import { ToastService } from '../../../shared/toast/toast.service';
import { ProxySettingsService, ProxyType } from './proxy-settings.service';

/**
 * The admin "Proxy" settings section (#490), on the grouped design language
 * introduced by #541: one `app-settings-group` holding the enable/type/direct-
 * fallback instant controls above the host/port/credentials fields, which sit
 * behind the shared save bar because they take an explicit Save. A Test
 * connection row probes the last-*saved* config, which is why it disables
 * itself while the draft is dirty -- testing an unsaved edit would silently
 * test the wrong thing.
 *
 * The enable toggle stays off-limits until a host is on record: turning egress
 * on with nothing to route through is not a valid state, so the toggle is
 * disabled rather than left to fail server-side.
 */
@Component({
  selector: 'app-proxy-section',
  imports: [
    ButtonComponent,
    ErrorBannerComponent,
    InfoTipComponent,
    SettingsGroupComponent,
    SettingsRowComponent,
    SettingsSaveBarComponent,
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
  private readonly i18n = inject(TranslocoService);
  private readonly toast = inject(ToastService);

  // Instant fields: persisted the moment they change, never held in the draft.
  readonly enabled = linkedSignal<boolean>(() => this.svc.state()?.enabled ?? false);
  readonly directFallback = linkedSignal<boolean>(() => this.svc.state()?.directFallback ?? true);
  readonly type = linkedSignal<ProxyType>(() => this.svc.state()?.type ?? 'SOCKS5');

  // Typed fields: held as a pending draft in the service until the explicit
  // Save. Each seeds from server truth and recomputes when the state does.
  readonly host = linkedSignal<string>(() => this.svc.state()?.host ?? '');
  readonly port = linkedSignal<number>(() => this.svc.state()?.port ?? 1080);
  readonly username = linkedSignal<string>(() => this.svc.state()?.username ?? '');
  /** Never seeded from server truth -- the API never returns the secret. */
  readonly password = linkedSignal<string>(() => '');

  readonly configured = computed(() => (this.svc.state()?.host ?? '') !== '');
  readonly hasStoredPassword = computed(() => this.svc.state()?.hasPassword ?? false);
  readonly passwordHint = computed(() => this.svc.state()?.passwordHint ?? '');
  readonly probe = this.svc.probe;

  readonly failureMessage = computed(() => {
    const failure = this.svc.failure();
    return failure ? (failure.detail ?? failure.title) : null;
  });

  readonly typeOptions: readonly ProxyType[] = ['SOCKS5', 'HTTP'];

  constructor() {
    this.svc.load();
    // One success signal, fired on the actual HTTP success rather than the
    // click: every persist sets `saved`, so this toasts once and resets the
    // flag. A rejected save never sets `saved`, so it stays silent.
    effect(() => {
      if (this.svc.saved() && !this.svc.failure()) {
        this.toast.show({ message: this.i18n.translate('settings.proxy.saved') });
        this.svc.saved.set(false);
      }
    });
  }

  onEnabled(value: boolean): void {
    this.enabled.set(value);
    this.svc.saveInstant({ enabled: value });
  }

  onDirectFallback(value: boolean): void {
    this.directFallback.set(value);
    this.svc.saveInstant({ directFallback: value });
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
    this.svc.setTypedField('username', value === '' ? null : value);
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

  /** Drops the pending typed edits and reseeds every typed input from the
   *  last-saved state, so a Reset with no intervening save still visibly
   *  restores the inputs (a `linkedSignal` only recomputes when `state` does). */
  onReset(): void {
    this.svc.discardDraft();
    const state = this.svc.state();
    this.host.set(state?.host ?? '');
    this.port.set(state?.port ?? 1080);
    this.username.set(state?.username ?? '');
    this.password.set('');
  }

  test(): void {
    this.svc.testConnection();
  }
}
