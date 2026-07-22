// src/app/reader/header/reader-header.component.ts
import { Component, inject, input, output, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { IconComponent } from '../../shared/icon/icon.component';
import { AuthService } from '../../core/auth.service';
import { ThemeService } from '../../theme/theme.service';
import { ThemeMode } from '../../theme/themes/registry';
import { ReadingLayoutService } from '../reading-layout.service';
import { RefreshService } from '../refresh.service';

@Component({
  selector: 'app-reader-header',
  imports: [IconComponent, RouterLink],
  template: `
    <header>
      <span class="brand">{{ title() }}</span>
      <div class="right">
        <div class="progress" [class.on]="refreshSvc.running()">
          <button aria-label="Refresh" [disabled]="refreshSvc.running()" (click)="refresh.emit()">
            <app-icon name="refresh" [size]="18" />
          </button>
          @if (refreshSvc.running()) {
            <span class="bar"><i [style.width.%]="refreshSvc.progress() * 100"></i></span>
          }
        </div>
        <button aria-label="Add feed" (click)="addFeed.emit()">
          <app-icon name="add" [size]="18" />
        </button>

        <div class="seg" role="group" aria-label="Reading layout">
          <button
            aria-label="List layout"
            [class.active]="layout.mode() === 'list'"
            (click)="layout.set('list')"
          >
            <app-icon name="view_agenda" [size]="18" />
          </button>
          <button
            aria-label="Pane layout"
            [class.active]="layout.mode() === 'pane'"
            (click)="layout.set('pane')"
          >
            <app-icon name="view_column_2" [size]="18" />
          </button>
        </div>

        <div class="seg" role="group" aria-label="Theme">
          @for (m of modes; track m.id) {
            <button
              [class.active]="theme.mode() === m.id"
              [attr.aria-pressed]="theme.mode() === m.id"
              [title]="m.label"
              (click)="theme.setMode(m.id)"
            >
              <app-icon [name]="m.icon" [size]="18" />
            </button>
          }
        </div>

        <div class="account">
          <button
            aria-haspopup="menu"
            [attr.aria-expanded]="menuOpen()"
            (click)="menuOpen.set(!menuOpen())"
          >
            {{ auth.user()?.email ?? '…' }} <app-icon name="expand_more" [size]="18" />
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
      .brand {
        font-weight: 500;
      }
      .right {
        display: flex;
        align-items: center;
        gap: var(--space-3);
      }
      .right > button,
      .account > button {
        display: inline-flex;
        align-items: center;
        gap: var(--space-1);
        background: none;
        border: none;
        color: var(--text-primary);
        cursor: pointer;
      }
      .seg {
        display: inline-flex;
        border: 1px solid var(--border);
        border-radius: var(--radius);
        overflow: hidden;
      }
      .seg button {
        padding: var(--space-2);
        background: var(--surface-1);
        border: none;
        color: var(--text-secondary);
        cursor: pointer;
      }
      .seg button.active {
        background: var(--accent-soft);
        color: var(--accent);
      }
      .progress {
        display: inline-flex;
        align-items: center;
        gap: var(--space-1);
      }
      .progress .bar {
        width: 48px;
        height: 4px;
        border-radius: 2px;
        background: var(--border);
        overflow: hidden;
      }
      .progress .bar i {
        display: block;
        height: 100%;
        background: var(--accent);
        transition: width 0.2s;
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
  readonly title = input.required<string>();
  readonly refresh = output<void>();
  readonly addFeed = output<void>();

  readonly auth = inject(AuthService);
  readonly theme = inject(ThemeService);
  readonly layout = inject(ReadingLayoutService);
  readonly refreshSvc = inject(RefreshService);
  readonly menuOpen = signal(false);

  readonly modes: { id: ThemeMode; label: string; icon: string }[] = [
    { id: 'light', label: 'Light', icon: 'light_mode' },
    { id: 'dark', label: 'Dark', icon: 'dark_mode' },
    { id: 'system', label: 'System', icon: 'contrast' },
  ];
}
