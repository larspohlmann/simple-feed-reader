// src/app/shell/shell.component.ts
import { Component, OnInit, inject, signal } from '@angular/core';
import { AuthService } from '../core/auth.service';
import { ThemeService } from '../theme/theme.service';
import { ThemeMode } from '../theme/themes/registry';
import { IconComponent } from '../shared/icon/icon.component';

@Component({
  selector: 'app-shell',
  imports: [IconComponent],
  template: `
    <header>
      <span class="brand">Simple Feed Reader</span>
      <div class="right">
        <div class="theme" role="group" aria-label="Theme">
          @for (m of modes; track m.id) {
            <button
              [class.active]="theme.mode() === m.id"
              (click)="theme.setMode(m.id)"
              [attr.aria-pressed]="theme.mode() === m.id"
              [title]="m.label"
            >
              <app-icon [name]="m.icon" [size]="18" />
            </button>
          }
        </div>
        <div class="account">
          <button
            (click)="menuOpen.set(!menuOpen())"
            aria-haspopup="menu"
            [attr.aria-expanded]="menuOpen()"
          >
            {{ auth.user()?.email ?? '…' }}
            <app-icon name="expand_more" [size]="18" />
          </button>
          @if (menuOpen()) {
            <div class="menu" role="menu">
              <button role="menuitem" (click)="auth.logout()">Sign out</button>
            </div>
          }
        </div>
      </div>
    </header>
    <main>
      <p class="placeholder">Your reader lands here in 5b.</p>
    </main>
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
        gap: var(--space-4);
      }
      .theme {
        display: inline-flex;
        border: 1px solid var(--border);
        border-radius: var(--radius);
        overflow: hidden;
      }
      .theme button {
        padding: var(--space-2);
        background: var(--surface-1);
        border: none;
        color: var(--text-secondary);
        cursor: pointer;
      }
      .theme button.active {
        background: var(--accent-soft);
        color: var(--accent);
      }
      .account {
        position: relative;
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
      main {
        padding: var(--space-6);
      }
      .placeholder {
        color: var(--text-muted);
      }
    `,
  ],
})
export class ShellComponent implements OnInit {
  readonly auth = inject(AuthService);
  readonly theme = inject(ThemeService);
  readonly menuOpen = signal(false);
  readonly modes: { id: ThemeMode; label: string; icon: string }[] = [
    { id: 'light', label: 'Light', icon: 'light_mode' },
    { id: 'dark', label: 'Dark', icon: 'dark_mode' },
    { id: 'system', label: 'System', icon: 'contrast' },
  ];

  ngOnInit(): void {
    if (!this.auth.user()) this.auth.loadMe().subscribe({ error: () => undefined });
  }
}
