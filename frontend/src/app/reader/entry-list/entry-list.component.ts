// src/app/reader/entry-list/entry-list.component.ts
import { Component, ElementRef, OnDestroy, effect, input, output, viewChild } from '@angular/core';
import { RouterLink } from '@angular/router';
import { IconComponent } from '../../shared/icon/icon.component';
import { EntryRowComponent } from '../entry-row/entry-row.component';
import { EntryDto } from '../models';
import { Selection } from '../query';
import { Problem } from '../../core/problem';

@Component({
  selector: 'app-entry-list',
  imports: [RouterLink, IconComponent, EntryRowComponent],
  template: `
    <header class="list-header">
      <h2>{{ title() }}</h2>
      <div class="tools">
        @if (
          selection().kind === 'all' ||
          selection().kind === 'tag' ||
          selection().kind === 'subscription'
        ) {
          <div class="toggle" role="group" aria-label="Filter">
            <a
              [class.on]="selection().unread"
              [routerLink]="[]"
              [queryParams]="{ unread: null }"
              queryParamsHandling="merge"
              >Unread</a
            >
            <a
              [class.on]="!selection().unread"
              [routerLink]="[]"
              [queryParams]="{ unread: '0' }"
              queryParamsHandling="merge"
              >All</a
            >
          </div>
        }
        @if (canMarkAllRead()) {
          <button class="mark-all" type="button" (click)="markAllRead.emit()">
            <app-icon name="done_all" [size]="18" /> Mark all read
          </button>
        }
      </div>
    </header>

    @if (error()) {
      <div class="banner" role="alert">{{ error()!.detail ?? error()!.title }}</div>
    }

    @if (loading()) {
      <div class="rows">
        @for (i of [1, 2, 3, 4, 5]; track i) {
          <div class="skeleton"></div>
        }
      </div>
    } @else if (entries().length === 0) {
      <p class="empty">{{ selection().unread ? "You're all caught up." : 'Nothing here yet.' }}</p>
    } @else {
      <div class="rows">
        @for (e of entries(); track e.id) {
          <app-entry-row
            [entry]="e"
            [class.open]="openEntryId() === e.id"
            (favorite)="favorite.emit($event)"
            (keep)="keep.emit($event)"
            (read)="read.emit($event)"
            (open)="open.emit($event)"
          />
        }
      </div>
      @if (hasMore()) {
        <div class="foot" #sentinel>
          <button
            class="load-more"
            type="button"
            [disabled]="loadingMore()"
            (click)="loadMore.emit()"
          >
            {{ loadingMore() ? 'Loading…' : 'Load more' }}
          </button>
        </div>
      }
    }
  `,
  styles: [
    `
      :host {
        display: flex;
        flex-direction: column;
        min-height: 0;
        height: 100%;
      }
      .list-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: var(--space-3);
        padding: var(--space-3) var(--space-4);
        border-bottom: 1px solid var(--border);
      }
      .list-header h2 {
        margin: 0;
        font-size: var(--fs-lg);
      }
      .tools {
        display: flex;
        align-items: center;
        gap: var(--space-3);
      }
      .toggle {
        display: inline-flex;
        border: 1px solid var(--border-strong);
        border-radius: var(--radius);
        overflow: hidden;
      }
      .toggle a {
        padding: var(--space-1) var(--space-3);
        font-size: var(--fs-sm);
        color: var(--text-secondary);
        text-decoration: none;
        cursor: pointer;
      }
      .toggle a.on {
        background: var(--surface-0);
        color: var(--text-primary);
      }
      .mark-all {
        display: inline-flex;
        align-items: center;
        gap: var(--space-1);
        background: none;
        border: none;
        color: var(--accent);
        cursor: pointer;
        font-size: var(--fs-sm);
      }
      .rows {
        overflow: auto;
      }
      .empty {
        color: var(--text-muted);
        padding: var(--space-6);
        text-align: center;
      }
      .banner {
        margin: var(--space-3) var(--space-4);
        padding: var(--space-3);
        border-radius: var(--radius);
        background: var(--bg-danger);
        color: var(--danger);
      }
      .skeleton {
        height: 72px;
        margin: var(--space-3) var(--space-4);
        border-radius: var(--radius);
        background: var(--surface-0);
      }
      .foot {
        display: flex;
        justify-content: center;
        padding: var(--space-4);
      }
      .load-more {
        padding: var(--space-2) var(--space-4);
        border: 1px solid var(--border-strong);
        border-radius: var(--radius);
        background: var(--surface-1);
        color: var(--text-primary);
        cursor: pointer;
      }
      @media (prefers-reduced-motion: reduce) {
        .skeleton {
          animation: none;
        }
      }
    `,
  ],
})
export class EntryListComponent implements OnDestroy {
  readonly title = input.required<string>();
  readonly entries = input.required<EntryDto[]>();
  readonly loading = input.required<boolean>();
  readonly loadingMore = input.required<boolean>();
  readonly error = input.required<Problem | null>();
  readonly hasMore = input.required<boolean>();
  readonly canMarkAllRead = input.required<boolean>();
  readonly selection = input.required<Selection>();
  readonly openEntryId = input.required<number | null>();

  readonly loadMore = output<void>();
  readonly markAllRead = output<void>();
  readonly favorite = output<EntryDto>();
  readonly keep = output<EntryDto>();
  readonly read = output<EntryDto>();
  readonly open = output<EntryDto>();

  private readonly sentinel = viewChild<ElementRef<HTMLElement>>('sentinel');
  private observer?: IntersectionObserver;

  // Re-observe whenever the sentinel appears/disappears (hasMore toggles it).
  private readonly _wire = effect(() => {
    const node = this.sentinel()?.nativeElement;
    this.observer?.disconnect();
    if (node && typeof IntersectionObserver !== 'undefined') {
      this.observer = new IntersectionObserver((es) => {
        if (es.some((e) => e.isIntersecting) && this.hasMore() && !this.loadingMore())
          this.loadMore.emit();
      });
      this.observer.observe(node);
    }
  });

  ngOnDestroy(): void {
    this.observer?.disconnect();
  }
}
