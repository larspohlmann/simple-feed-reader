// src/app/reader/sidebar/sidebar.component.ts
import { Component, input, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { IconComponent } from '../../shared/icon/icon.component';
import { TagNode } from '../subscriptions.store';
import { Selection } from '../query';
import { SubscriptionDto } from '../models';

@Component({
  selector: 'app-sidebar',
  imports: [RouterLink, IconComponent],
  template: `
    <nav class="sidebar" aria-label="Feeds">
      <a
        class="nav all"
        [class.active]="selection().kind === 'all'"
        [routerLink]="[]"
        [queryParams]="{ view: null, tag: null, subscription: null, entry: null }"
        queryParamsHandling="merge"
      >
        <app-icon name="inbox" [size]="18" /><span>All items</span>
        @if (totalUnread() > 0) {
          <span class="count">{{ totalUnread() }}</span>
        }
      </a>
      <a
        class="nav"
        [class.active]="selection().kind === 'favorites'"
        [routerLink]="[]"
        [queryParams]="{ view: 'favorites', tag: null, subscription: null, entry: null }"
        queryParamsHandling="merge"
      >
        <app-icon name="star" [size]="18" /><span>Favorites</span>
      </a>
      <a
        class="nav"
        [class.active]="selection().kind === 'kept'"
        [routerLink]="[]"
        [queryParams]="{ view: 'kept', tag: null, subscription: null, entry: null }"
        queryParamsHandling="merge"
      >
        <app-icon name="bookmark" [size]="18" /><span>Kept</span>
      </a>

      @if (tagTree().length) {
        <p class="label">Tags</p>
        @for (node of tagTree(); track node.tag.id) {
          <div class="tag">
            <button
              class="expand"
              type="button"
              [attr.aria-expanded]="expanded().has(node.tag.id)"
              [attr.aria-label]="'Toggle ' + node.tag.name"
              (click)="toggle(node.tag.id)"
            >
              <app-icon
                [name]="expanded().has(node.tag.id) ? 'expand_more' : 'chevron_right'"
                [size]="18"
              />
            </button>
            <a
              class="nav grow"
              [class.active]="selection().kind === 'tag' && selection().id === node.tag.id"
              [routerLink]="[]"
              [queryParams]="{ tag: node.tag.id, view: null, subscription: null, entry: null }"
              queryParamsHandling="merge"
            >
              <span class="dot" [style.background]="node.tag.color || 'var(--text-muted)'"></span>
              <span>{{ node.tag.name }}</span>
              @if (node.unreadCount > 0) {
                <span class="count">{{ node.unreadCount }}</span>
              }
            </a>
          </div>
          @if (expanded().has(node.tag.id)) {
            @for (s of node.subscriptions; track s.id) {
              <a
                class="nav tag-sub"
                [class.active]="selection().kind === 'subscription' && selection().id === s.id"
                [routerLink]="[]"
                [queryParams]="{ subscription: s.id, view: null, tag: null, entry: null }"
                queryParamsHandling="merge"
              >
                <span>{{ s.title }}</span>
                @if (s.unreadCount > 0) {
                  <span class="count">{{ s.unreadCount }}</span>
                }
              </a>
            }
          }
        }
      }

      @if (untagged().length) {
        <p class="label">Feeds</p>
        @for (s of untagged(); track s.id) {
          <a
            class="nav"
            [class.active]="selection().kind === 'subscription' && selection().id === s.id"
            [routerLink]="[]"
            [queryParams]="{ subscription: s.id, view: null, tag: null, entry: null }"
            queryParamsHandling="merge"
          >
            <app-icon name="rss_feed" [size]="16" /><span>{{ s.title }}</span>
            @if (s.unreadCount > 0) {
              <span class="count">{{ s.unreadCount }}</span>
            }
          </a>
        }
      }
    </nav>
  `,
  styles: [
    `
      .sidebar {
        padding: var(--space-3) var(--space-2);
        display: flex;
        flex-direction: column;
        gap: 2px;
        overflow: auto;
        height: 100%;
      }
      .nav {
        display: flex;
        align-items: center;
        gap: var(--space-2);
        padding: var(--space-2);
        border-radius: var(--radius);
        color: var(--text-primary);
        text-decoration: none;
      }
      .nav:hover {
        background: var(--surface-0);
      }
      .nav.active {
        background: var(--accent-soft);
        color: var(--accent);
      }
      .nav .count {
        margin-left: auto;
        font-size: var(--fs-sm);
        color: var(--text-muted);
      }
      .nav.active .count {
        color: var(--accent);
      }
      .label {
        font-size: var(--fs-sm);
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--text-muted);
        margin: var(--space-3) var(--space-2) var(--space-1);
      }
      .tag {
        display: flex;
        align-items: center;
      }
      .tag .expand {
        background: none;
        border: none;
        color: var(--text-secondary);
        cursor: pointer;
        padding: var(--space-2) 0 var(--space-2) var(--space-1);
      }
      .tag .grow {
        flex: 1;
      }
      .tag-sub {
        padding-left: var(--space-6);
      }
      .dot {
        width: 9px;
        height: 9px;
        border-radius: 50%;
        flex: 0 0 auto;
      }
    `,
  ],
})
export class SidebarComponent {
  readonly tagTree = input.required<TagNode[]>();
  readonly untagged = input.required<SubscriptionDto[]>();
  readonly totalUnread = input.required<number>();
  readonly selection = input.required<Selection>();
  readonly loading = input(false);

  readonly expanded = signal<Set<number>>(new Set());

  toggle(tagId: number): void {
    this.expanded.update((set) => {
      const next = new Set(set);
      if (next.has(tagId)) {
        next.delete(tagId);
      } else {
        next.add(tagId);
      }
      return next;
    });
  }
}
