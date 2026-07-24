// src/app/settings/account-section.component.ts
import { DatePipe } from '@angular/common';
import { Component, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../shared/icon/icon.component';
import { AuthService } from '../core/auth.service';

@Component({
  selector: 'app-account-section',
  imports: [RouterLink, IconComponent, DatePipe, TranslocoPipe],
  templateUrl: './account-section.component.html',
  styleUrl: './account-section.component.scss',
})
export class AccountSectionComponent {
  readonly auth = inject(AuthService);
}
