// src/app/reader/header/reader-header.component.ts
import { Component, inject, output, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { IconComponent } from '../../shared/icon/icon.component';
import { AuthService } from '../../core/auth.service';

@Component({
  selector: 'app-reader-header',
  imports: [IconComponent, RouterLink],
  template: `
    <header>
      <div class="left">
        <button class="menu-btn" aria-label="Toggle sidebar" (click)="toggleSidebar.emit()">
          <app-icon name="menu" [size]="20" />
        </button>
        <a
          class="brand"
          [routerLink]="[]"
          [queryParams]="{ view: null, tag: null, subscription: null, entry: null }"
          queryParamsHandling="merge"
        >
          <app-icon name="rss_feed" [size]="20" />
          <span class="name">simple feed reader</span>
        </a>
      </div>
      <div class="right">
        <div class="account">
          <button
            aria-haspopup="menu"
            [attr.aria-expanded]="menuOpen()"
            (click)="menuOpen.set(!menuOpen())"
          >
            <app-icon class="acct-ico" name="account_circle" [size]="20" />
            <span class="acct-email">{{ auth.user()?.email ?? '…' }}</span>
            <app-icon name="expand_more" [size]="18" />
          </button>
          @if (menuOpen()) {
            <div class="menu" role="menu">
              <a role="menuitem" routerLink="/settings" (click)="menuOpen.set(false)">Settings</a>
              @if (auth.isAdmin()) {
                <a role="menuitem" routerLink="/admin/users" (click)="menuOpen.set(false)">Admin</a>
              }
              <button role="menuitem" (click)="auth.logout()">Sign out</button>
            </div>
          }
        </div>
      </div>
    </header>
  `,
  styles: [
    `
      header {
        height: 56px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 var(--space-4);
        border-bottom: 1px solid var(--border);
        background: var(--surface-1);
      }
      .left {
        display: flex;
        align-items: center;
        gap: var(--space-2);
      }
      .brand {
        display: inline-flex;
        align-items: center;
        gap: var(--space-2);
        font-weight: 700;
        color: var(--text-primary);
        text-decoration: none;
      }
      .brand .name {
        white-space: nowrap;
      }
      .brand app-icon {
        color: var(--accent);
      }
      .acct-ico {
        display: none;
      }
      .acct-email {
        max-width: 220px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }
      .menu-btn {
        display: none;
        align-items: center;
        background: none;
        border: none;
        color: var(--text-primary);
        cursor: pointer;
        padding: var(--space-1);
      }
      .right {
        display: flex;
        align-items: center;
        gap: var(--space-3);
      }
      .account > button {
        display: inline-flex;
        align-items: center;
        gap: var(--space-1);
        background: none;
        border: none;
        color: var(--text-primary);
        cursor: pointer;
      }
      @media (max-width: 720px) {
        header {
          padding: 0 var(--space-3);
        }
        .menu-btn {
          display: inline-flex;
        }
        .right {
          gap: var(--space-2);
        }
        /* Drop the wordmark and the long email; keep icon-only affordances. */
        .brand .name {
          display: none;
        }
        .acct-email {
          display: none;
        }
        .acct-ico {
          display: inline-flex;
        }
      }
      .account {
        position: relative;
      }
      .menu {
        position: absolute;
        right: 0;
        top: 40px;
        background: var(--surface-2);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        min-width: 140px;
      }
      .menu button {
        width: 100%;
        text-align: left;
        padding: var(--space-3);
        background: none;
        border: none;
        color: var(--text-primary);
        cursor: pointer;
      }
      .menu a {
        display: block;
        padding: var(--space-3);
        color: var(--text-primary);
        text-decoration: none;
      }
      .menu a:hover {
        background: var(--surface-0);
      }
    `,
  ],
})
export class ReaderHeaderComponent {
  readonly toggleSidebar = output<void>();

  readonly auth = inject(AuthService);
  readonly menuOpen = signal(false);
}
