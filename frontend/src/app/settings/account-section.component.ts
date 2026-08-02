// src/app/settings/account-section.component.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { Dialog } from '@angular/cdk/dialog';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { Problem, parseProblem } from '../core/problem';
import { UserAvatarComponent } from '../shared/user-avatar/user-avatar.component';
import { AuthService } from '../core/auth.service';
import { LanguageService } from '../core/language.service';
import { formatLongDate } from '../reader/format';
import { ButtonComponent } from '../shared/button/button.component';
import {
  ConfirmData,
  ConfirmDialogComponent,
} from '../shared/confirm-dialog/confirm-dialog.component';
import { ErrorBannerComponent } from '../shared/error-banner/error-banner.component';
import { SettingsCardComponent } from '../shared/settings-card/settings-card.component';

@Component({
  selector: 'app-account-section',
  imports: [
    ButtonComponent,
    ErrorBannerComponent,
    SettingsCardComponent,
    TranslocoPipe,
    UserAvatarComponent,
  ],
  templateUrl: './account-section.component.html',
  styleUrl: './account-section.component.scss',
})
export class AccountSectionComponent {
  readonly auth = inject(AuthService);
  private readonly dialog = inject(Dialog);
  private readonly i18n = inject(TranslocoService);
  private readonly language = inject(LanguageService);

  readonly deleteError = signal<Problem | null>(null);

  memberSince(iso: string): string {
    return formatLongDate(iso, this.language.lang());
  }

  /** The account and everything in it, gone. Same treatment as the admin's
   *  delete: type your own address to enable the confirm. */
  confirmThenDelete(): void {
    const email = this.auth.user()?.email ?? '';
    const data: ConfirmData = {
      title: this.i18n.translate('settings.account.deleteTitle'),
      message: this.i18n.translate('settings.account.deleteMessage'),
      confirmLabel: this.i18n.translate('settings.account.delete'),
      danger: true,
      requireText: email,
    };
    const ref = this.dialog.open<boolean>(ConfirmDialogComponent, {
      data,
      role: 'alertdialog',
      panelClass: 'app-dialog',
    });
    ref.closed.subscribe((confirmed) => {
      if (confirmed) this.deleteAccount();
    });
  }

  private deleteAccount(): void {
    this.deleteError.set(null);
    this.auth.deleteAccount().subscribe({
      // logout() clears the token, resets per-account state and routes to
      // /login. The token is stateless and the user row is gone, so it
      // authenticates nobody either way -- clearing it is what stops the app
      // from rendering a signed-in shell for an account that no longer exists.
      next: () => this.auth.logout(),
      error: (failure: HttpErrorResponse) => this.deleteError.set(parseProblem(failure)),
    });
  }
}
