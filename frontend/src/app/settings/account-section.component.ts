// src/app/settings/account-section.component.ts
import { Component, inject } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { UserAvatarComponent } from '../shared/user-avatar/user-avatar.component';
import { AuthService } from '../core/auth.service';
import { LanguageService } from '../core/language.service';
import { formatLongDate } from '../reader/format';
import { ButtonComponent } from '../shared/button/button.component';
import { SettingsCardComponent } from '../shared/settings-card/settings-card.component';

@Component({
  selector: 'app-account-section',
  imports: [ButtonComponent, SettingsCardComponent, TranslocoPipe, UserAvatarComponent],
  templateUrl: './account-section.component.html',
  styleUrl: './account-section.component.scss',
})
export class AccountSectionComponent {
  readonly auth = inject(AuthService);
  private readonly language = inject(LanguageService);

  memberSince(iso: string): string {
    return formatLongDate(iso, this.language.lang());
  }
}
