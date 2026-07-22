// src/app/settings/account-section.component.ts
import { DatePipe } from '@angular/common';
import { Component, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { IconComponent } from '../shared/icon/icon.component';
import { AuthService } from '../core/auth.service';

@Component({
  selector: 'app-account-section',
  imports: [RouterLink, IconComponent, DatePipe],
  template: `
    <section>
      <h2>Account</h2>
      @if (auth.user(); as u) {
        <dl class="grid">
          <dt>Email</dt>
          <dd>{{ u.email }}</dd>
          <dt>Member since</dt>
          <dd>{{ u.createdAt | date: 'longDate' }}</dd>
        </dl>
        @if (auth.isAdmin()) {
          <a class="admin" routerLink="/admin/users">
            <app-icon name="shield_person" [size]="18" /> Admin — user queue
          </a>
        }
        <button class="signout" (click)="auth.logout()">Sign out</button>
      }
    </section>
  `,
  styles: [
    `
      h2 {
        font-size: var(--fs-lg);
        margin: 0 0 var(--space-3);
      }
      .grid {
        display: grid;
        grid-template-columns: auto 1fr;
        gap: var(--space-2) var(--space-4);
        margin: 0 0 var(--space-3);
      }
      dt {
        color: var(--text-muted);
      }
      dd {
        margin: 0;
        color: var(--text-primary);
      }
      .admin {
        display: inline-flex;
        align-items: center;
        gap: var(--space-1);
        color: var(--accent);
        text-decoration: none;
        margin-bottom: var(--space-3);
      }
      .signout {
        display: block;
        padding: var(--space-2) var(--space-4);
        border: 1px solid var(--border-strong);
        border-radius: var(--radius);
        background: var(--surface-1);
        color: var(--text-primary);
        cursor: pointer;
      }
    `,
  ],
})
export class AccountSectionComponent {
  readonly auth = inject(AuthService);
}
