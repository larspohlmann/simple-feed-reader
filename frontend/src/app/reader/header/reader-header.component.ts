// src/app/reader/header/reader-header.component.ts
import { Component, inject, input, output, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { IconComponent } from '../../shared/icon/icon.component';
import { AuthService } from '../../core/auth.service';
import { ReaderModeService } from '../reader-mode.service';
import { TagDto } from '../models';

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
        @if (articleOpen()) {
          @if (readerMode.canToggle()) {
            <button
              class="mode"
              type="button"
              [attr.aria-pressed]="readerMode.mode() === 'reader'"
              [attr.aria-label]="
                readerMode.mode() === 'reader' ? 'Show original feed content' : 'Show reader view'
              "
              (click)="readerMode.toggle()"
            >
              <app-icon [name]="readerMode.mode() === 'reader' ? 'article' : 'feed'" [size]="18" />
              <span class="mode-label">{{
                readerMode.mode() === 'reader' ? 'Reader' : 'Original'
              }}</span>
            </button>
          }
          <button
            class="icon-btn"
            type="button"
            aria-label="Previous"
            [disabled]="!hasPrev()"
            (click)="prev.emit()"
          >
            <app-icon name="chevron_left" [size]="20" />
          </button>
          <button
            class="icon-btn"
            type="button"
            aria-label="Next"
            [disabled]="!hasNext()"
            (click)="next.emit()"
          >
            <app-icon name="chevron_right" [size]="20" />
          </button>
        }
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

    @if (!articleOpen() && tags().length) {
      <nav class="tagrow" aria-label="Tags">
        @for (t of tags(); track t.id) {
          <a
            class="chip"
            [class.active]="t.id === activeTagId()"
            [routerLink]="[]"
            [queryParams]="{ tag: t.id, view: null, subscription: null, entry: null }"
            queryParamsHandling="merge"
          >
            @if (t.icon) {
              <app-icon
                [name]="t.icon"
                [size]="14"
                [style.color]="t.color || 'var(--text-muted)'"
              />
            } @else {
              <span class="dot" [style.background]="t.color || 'var(--text-muted)'"></span>
            }
            {{ t.name }}
          </a>
        }
      </nav>
    }
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
      /* The swipeable tag row is a mobile-only affordance; on wider screens the
         sidebar already lists the tags. */
      .tagrow {
        display: none;
      }
      @media (max-width: 720px) {
        .tagrow {
          display: flex;
          gap: var(--space-2);
          align-items: center;
          padding: var(--space-2) var(--space-3);
          border-bottom: 1px solid var(--border);
          background: var(--surface-1);
          overflow-x: auto;
          scrollbar-width: none;
          -webkit-overflow-scrolling: touch;
          scroll-snap-type: x proximity;
        }
        .tagrow::-webkit-scrollbar {
          display: none;
        }
      }
      .chip {
        flex: none;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: var(--space-1) var(--space-3);
        border: 1px solid var(--border);
        border-radius: 999px;
        background: var(--surface-2);
        color: var(--text-secondary);
        font-size: var(--fs-sm);
        text-decoration: none;
        white-space: nowrap;
        scroll-snap-align: start;
      }
      .chip.active {
        background: var(--accent-soft);
        border-color: var(--accent);
        color: var(--accent);
      }
      .chip .dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        flex: none;
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
      .icon-btn {
        display: inline-flex;
        align-items: center;
        background: none;
        border: none;
        color: var(--text-primary);
        cursor: pointer;
        padding: var(--space-1);
      }
      .icon-btn:disabled {
        color: var(--text-muted);
        cursor: default;
      }
      .mode {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        background: none;
        border: none;
        color: var(--text-secondary);
        cursor: pointer;
        padding: var(--space-1);
        font-size: var(--fs-sm);
      }
      .mode[aria-pressed='true'] {
        color: var(--accent);
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
  /** True when an article is open full-screen: the bar swaps the brand for a
   *  back button and shows the reader switch and prev/next. */
  readonly articleOpen = input(false);
  readonly hasPrev = input(false);
  readonly hasNext = input(false);
  /** Tags for the mobile swipe row; empty hides the row (and on wider screens
   *  CSS hides it regardless). */
  readonly tags = input<TagDto[]>([]);
  readonly activeTagId = input<number | null>(null);

  readonly toggleSidebar = output<void>();
  readonly prev = output<void>();
  readonly next = output<void>();

  readonly auth = inject(AuthService);
  readonly readerMode = inject(ReaderModeService);
  readonly menuOpen = signal(false);
}
