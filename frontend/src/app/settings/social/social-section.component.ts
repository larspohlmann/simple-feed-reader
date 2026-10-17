import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { Dialog } from '@angular/cdk/dialog';
import { NgTemplateOutlet } from '@angular/common';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { formatLongDate } from '../../reader/format';
import { ButtonComponent } from '../../shared/button/button.component';
import { ConfirmService } from '../../shared/confirm-dialog/confirm.service';
import { LanguageService } from '../../core/i18n/language.service';
import { ErrorBannerComponent } from '../../shared/error-banner/error-banner.component';
import { IconComponent } from '../../shared/icon/icon.component';
import { inputValue } from '../../shared/input-value';
import { PasswordInputComponent } from '../../shared/password-input/password-input.component';
import { SettingsGroupComponent } from '../../shared/settings/settings-group/settings-group.component';
import { SettingsRowComponent } from '../../shared/settings/settings-row/settings-row.component';
import { SettingsStackComponent } from '../../shared/settings/stack/settings-stack.component';
import { CONFIRMATION_DURATION_MS, ToastService } from '../../shared/toast/toast.service';
import { SocialNetworkName } from '../../core/social-network';
import { SocialAccount, SocialAccountsService } from './social-accounts.service';
import {
  SocialImportDialogComponent,
  SocialImportDialogData,
} from './social-import-dialog.component';
import { SocialImportResult } from './social-import.service';

@Component({
  selector: 'app-social-section',
  imports: [
    ButtonComponent,
    ErrorBannerComponent,
    IconComponent,
    NgTemplateOutlet,
    PasswordInputComponent,
    SettingsGroupComponent,
    SettingsRowComponent,
    SettingsStackComponent,
    TranslocoPipe,
  ],
  providers: [SocialAccountsService],
  templateUrl: './social-section.component.html',
  styleUrl: './social-section.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SocialSectionComponent {
  protected readonly svc = inject(SocialAccountsService);
  private readonly confirm = inject(ConfirmService);
  private readonly dialog = inject(Dialog);
  private readonly toast = inject(ToastService);
  private readonly i18n = inject(TranslocoService);
  private readonly language = inject(LanguageService);

  protected readonly blueskyAccounts = this.accountsOf('bluesky');
  protected readonly mastodonAccounts = this.accountsOf('mastodon');

  protected readonly blueskyFormOpen = signal(false);
  protected readonly mastodonFormOpen = signal(false);
  protected readonly blueskyHandle = signal('');
  protected readonly blueskyPassword = signal('');
  protected readonly mastodonInstance = signal('');
  protected readonly mastodonToken = signal('');
  protected readonly mastodonHost = computed(() => hostOf(this.mastodonInstance()));
  protected readonly reconnectSecrets = signal<Readonly<Record<number, string>>>({});
  protected readonly inputValue = inputValue;

  constructor() {
    this.svc.load();
  }

  protected describeSince(account: SocialAccount): string {
    const date = formatLongDate(account.connectedAt, this.language.lang());
    return this.i18n.translate('settings.social.connectedSince', { date });
  }

  protected describeImportSource(account: SocialAccount): string {
    const network = this.i18n.translate(`settings.social.${account.network}`);
    if (account.status === 'connected') return network;
    return `${network} · ${this.i18n.translate('settings.social.needsReconnect')}`;
  }

  protected setReconnectSecret(id: number, secret: string): void {
    this.reconnectSecrets.update((current) => ({ ...current, [id]: secret }));
  }

  private forgetReconnectSecret(id: number): void {
    this.reconnectSecrets.update((current) =>
      Object.fromEntries(Object.entries(current).filter(([key]) => Number(key) !== id)),
    );
  }

  protected reconnectSecret(id: number): string {
    return this.reconnectSecrets()[id] ?? '';
  }

  protected connectBluesky(): void {
    this.svc.connectBluesky(this.blueskyHandle(), this.blueskyPassword()).subscribe({
      next: () => {
        this.resetBlueskyForm();
        this.announceConnected();
      },
      error: () => undefined,
    });
  }

  protected connectMastodon(): void {
    this.svc.connectMastodon(this.mastodonInstance(), this.mastodonToken()).subscribe({
      next: () => {
        this.resetMastodonForm();
        this.announceConnected();
      },
      error: () => undefined,
    });
  }

  protected resetBlueskyForm(): void {
    this.blueskyHandle.set('');
    this.blueskyPassword.set('');
    this.blueskyFormOpen.set(false);
  }

  protected resetMastodonForm(): void {
    this.mastodonInstance.set('');
    this.mastodonToken.set('');
    this.mastodonFormOpen.set(false);
  }

  protected reconnect(account: SocialAccount): void {
    this.svc.reconnect(account.id, this.reconnectSecret(account.id)).subscribe({
      next: () => {
        this.setReconnectSecret(account.id, '');
        this.announceConnected();
      },
      error: () => undefined,
    });
  }

  protected openImport(account: SocialAccount): void {
    const data: SocialImportDialogData = {
      accountId: account.id,
      network: account.network,
      handle: account.handle,
    };
    this.dialog
      .open<SocialImportResult | undefined>(SocialImportDialogComponent, {
        data,
        panelClass: 'app-dialog',
      })
      .closed.subscribe(() => this.svc.load());
  }

  protected makeDefault(account: SocialAccount): void {
    this.svc.makeDefault(account.id);
  }

  protected disconnect(account: SocialAccount): void {
    this.confirm.confirmThen(
      {
        title: this.i18n.translate('settings.social.disconnectTitle', { handle: account.handle }),
        message: this.i18n.translate('settings.social.disconnectMessage', {
          network: this.i18n.translate(`settings.social.${account.network}`),
        }),
        confirmLabel: this.i18n.translate('settings.social.disconnect'),
        danger: true,
      },
      () => {
        this.svc.disconnect(account.id);
        this.forgetReconnectSecret(account.id);
      },
    );
  }

  private accountsOf(network: SocialNetworkName) {
    return computed(() =>
      (this.svc.accounts() ?? []).filter((account) => account.network === network),
    );
  }

  private announceConnected(): void {
    this.toast.show({
      message: this.i18n.translate('settings.social.connected'),
      durationMs: CONFIRMATION_DURATION_MS,
    });
  }
}

function hostOf(instance: string): string | null {
  const typed = instance.trim();
  if (typed === '') return null;
  try {
    const { host, hostname } = new URL(typed.includes('://') ? typed : `https://${typed}`);
    return hostname.includes('.') ? host : null;
  } catch {
    return null;
  }
}
