import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { NgTemplateOutlet } from '@angular/common';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { ButtonComponent } from '../../shared/button/button.component';
import { ConfirmService } from '../../shared/confirm-dialog/confirm.service';
import { ErrorBannerComponent } from '../../shared/error-banner/error-banner.component';
import { PasswordInputComponent } from '../../shared/password-input/password-input.component';
import { SettingsGroupComponent } from '../../shared/settings/settings-group/settings-group.component';
import { SettingsRowComponent } from '../../shared/settings/settings-row/settings-row.component';
import { SettingsStackComponent } from '../../shared/settings/stack/settings-stack.component';
import { CONFIRMATION_DURATION_MS, ToastService } from '../../shared/toast/toast.service';
import { SocialAccount, SocialAccountsService, SocialNetwork } from './social-accounts.service';

@Component({
  selector: 'app-social-section',
  imports: [
    ButtonComponent,
    ErrorBannerComponent,
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
  private readonly toast = inject(ToastService);
  private readonly i18n = inject(TranslocoService);

  protected readonly blueskyAccounts = this.accountsOf('bluesky');
  protected readonly mastodonAccounts = this.accountsOf('mastodon');

  // Secrets live only in these component signals; each is cleared once its request succeeds.
  protected readonly blueskyHandle = signal('');
  protected readonly blueskyPassword = signal('');
  protected readonly mastodonInstance = signal('');
  protected readonly mastodonToken = signal('');
  protected readonly mastodonHost = computed(() => hostOf(this.mastodonInstance()));
  protected readonly reconnectSecrets = signal<Readonly<Record<number, string>>>({});

  constructor() {
    this.svc.load();
  }

  protected typed(event: Event): string {
    return (event.target as HTMLInputElement).value;
  }

  protected setReconnectSecret(id: number, secret: string): void {
    this.reconnectSecrets.update((current) => ({ ...current, [id]: secret }));
  }

  protected reconnectSecret(id: number): string {
    return this.reconnectSecrets()[id] ?? '';
  }

  protected connectBluesky(): void {
    this.svc.connectBluesky(this.blueskyHandle(), this.blueskyPassword()).subscribe({
      next: () => {
        this.blueskyHandle.set('');
        this.blueskyPassword.set('');
        this.announceConnected();
      },
      error: () => undefined,
    });
  }

  protected connectMastodon(): void {
    this.svc.connectMastodon(this.mastodonInstance(), this.mastodonToken()).subscribe({
      next: () => {
        this.mastodonInstance.set('');
        this.mastodonToken.set('');
        this.announceConnected();
      },
      error: () => undefined,
    });
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

  protected makeDefault(account: SocialAccount): void {
    this.svc.makeDefault(account.id);
  }

  protected disconnect(account: SocialAccount): void {
    this.confirm.confirmThen(
      {
        title: this.i18n.translate('settings.social.disconnectTitle', { handle: account.handle }),
        message: this.i18n.translate('settings.social.disconnectMessage'),
        confirmLabel: this.i18n.translate('settings.social.disconnect'),
        danger: true,
      },
      () => this.svc.disconnect(account.id),
    );
  }

  private accountsOf(network: SocialNetwork) {
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
    const { hostname } = new URL(typed.includes('://') ? typed : `https://${typed}`);
    return hostname.includes('.') ? hostname : null;
  } catch {
    return null;
  }
}
