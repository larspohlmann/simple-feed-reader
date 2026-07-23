// src/app/reader/reader-shell.component.ts
import { Component, OnInit, computed, effect, inject, signal, untracked } from '@angular/core';
import { ActivatedRoute, Router, convertToParamMap } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { Dialog } from '@angular/cdk/dialog';
import { AuthService } from '../core/auth.service';
import { ReaderApi } from './reader-api';
import { SubscriptionsStore } from './subscriptions.store';
import { EntriesStore } from './entries.store';
import { RefreshService } from './refresh.service';
import { ReadingLayoutService } from './reading-layout.service';
import { LayoutService } from './layout.service';
import { markReadTarget, queryFromSelection, selectionFromParams } from './query';
import { EntryDto, SubscriptionDto } from './models';
import { ReaderHeaderComponent } from './header/reader-header.component';
import { SidebarComponent } from './sidebar/sidebar.component';
import { EntryListComponent } from './entry-list/entry-list.component';
import { ReaderViewComponent } from './reader-view/reader-view.component';
import { AddFeedDialogComponent } from './add-feed/add-feed-dialog.component';
import { ManageActions } from './manage/manage-actions.service';

@Component({
  selector: 'app-reader-shell',
  imports: [ReaderHeaderComponent, SidebarComponent, EntryListComponent, ReaderViewComponent],
  template: `
    <app-reader-header (toggleSidebar)="sidebarOpen.set(!sidebarOpen())" />
    <div class="body">
      @if (sidebarOpen()) {
        <button
          class="backdrop"
          type="button"
          aria-label="Close menu"
          (click)="sidebarOpen.set(false)"
        ></button>
      }
      <aside class="sidebar" [class.open]="sidebarOpen()">
        <app-sidebar
          [tagTree]="subs.tagTree()"
          [untagged]="subs.untagged()"
          [totalUnread]="subs.totalUnread()"
          [selection]="selection()"
          [loading]="subs.loading()"
          (editTag)="manage.editTag($event)"
          (deleteTag)="manage.deleteTag($event)"
          (editFeed)="manage.editSubscription($event)"
          (unsubscribe)="manage.unsubscribe($event)"
          (refresh)="onRefresh()"
          (addFeed)="onAddFeed()"
        />
      </aside>
      <main class="main" [class.split]="paneMode()">
        @if (paneMode()) {
          <section class="list">
            <app-entry-list
              [layout]="layout.mode()"
              [title]="title()"
              [entries]="entries.entries()"
              [loading]="entries.loading()"
              [loadingMore]="entries.loadingMore()"
              [error]="entries.error()"
              [hasMore]="hasMore()"
              [canMarkAllRead]="canMarkAllRead()"
              [selection]="selection()"
              [openEntryId]="entryId()"
              (loadMore)="entries.loadMore()"
              (markAllRead)="onMarkAllRead()"
              (favorite)="onFavorite($event)"
              (keep)="onKeep($event)"
              (read)="onToggleRead($event)"
              (open)="onOpen($event)"
            />
          </section>
          <section class="reader">
            <app-reader-view
              [entry]="openEntry()"
              [hasPrev]="hasPrev()"
              [hasNext]="hasNext()"
              (favorite)="withOpen(onFavorite)"
              (keep)="withOpen(onKeep)"
              (read)="withOpen(onToggleRead)"
              (prev)="onPrev()"
              (next)="onNext()"
              (close)="onCloseReader()"
            />
          </section>
        } @else if (openEntry()) {
          <app-reader-view
            [entry]="openEntry()"
            [hasPrev]="hasPrev()"
            [hasNext]="hasNext()"
            (favorite)="withOpen(onFavorite)"
            (keep)="withOpen(onKeep)"
            (read)="withOpen(onToggleRead)"
            (prev)="onPrev()"
            (next)="onNext()"
            (close)="onCloseReader()"
          />
        } @else {
          <app-entry-list
            [layout]="layout.mode()"
            [title]="title()"
            [entries]="entries.entries()"
            [loading]="entries.loading()"
            [loadingMore]="entries.loadingMore()"
            [error]="entries.error()"
            [hasMore]="hasMore()"
            [canMarkAllRead]="canMarkAllRead()"
            [selection]="selection()"
            [openEntryId]="entryId()"
            (loadMore)="entries.loadMore()"
            (markAllRead)="onMarkAllRead()"
            (favorite)="onFavorite($event)"
            (keep)="onKeep($event)"
            (read)="onToggleRead($event)"
            (open)="onOpen($event)"
          />
        }
      </main>
    </div>
  `,
  styles: [
    `
      :host {
        display: flex;
        flex-direction: column;
        height: 100vh;
      }
      .body {
        flex: 1;
        display: flex;
        min-height: 0;
      }
      .sidebar {
        width: 260px;
        flex: 0 0 auto;
        border-right: 1px solid var(--border);
        background: var(--surface-1);
      }
      .main {
        flex: 1;
        min-width: 0;
        display: flex;
      }
      .main.split .list {
        flex: 0 0 42%;
        max-width: 480px;
        border-right: 1px solid var(--border);
        display: flex;
        flex-direction: column;
      }
      .main.split .reader {
        flex: 1;
        min-width: 0;
      }
      .main:not(.split) > * {
        flex: 1;
        min-width: 0;
      }
      .backdrop {
        display: none;
        border: 0;
        padding: 0;
      }
      @media (max-width: 720px) {
        .sidebar {
          position: fixed;
          top: 56px;
          bottom: 0;
          left: 0;
          z-index: 20;
          width: 260px;
          max-width: 82vw;
          transform: translateX(-100%);
          transition: transform 0.2s ease;
        }
        .sidebar.open {
          transform: translateX(0);
        }
        .backdrop {
          display: block;
          position: fixed;
          inset: 56px 0 0 0;
          z-index: 15;
          background: rgba(0, 0, 0, 0.4);
        }
      }
    `,
  ],
})
export class ReaderShellComponent implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly dialog = inject(Dialog);
  private readonly api = inject(ReaderApi);
  private readonly auth = inject(AuthService);

  readonly manage = inject(ManageActions);
  readonly subs = inject(SubscriptionsStore);
  readonly entries = inject(EntriesStore);
  readonly refreshSvc = inject(RefreshService);
  readonly layout = inject(ReadingLayoutService);
  readonly screen = inject(LayoutService);

  private readonly params = toSignal(this.route.queryParamMap, {
    initialValue: convertToParamMap({}),
  });
  private readonly parsed = computed(() => selectionFromParams(this.params()));
  // Structural equality so an entry-only URL change does not produce a new
  // selection reference — the reload effect must react to selection, not the
  // open entry.
  readonly selection = computed(() => this.parsed().selection, {
    equal: (a, b) => a.kind === b.kind && a.id === b.id && a.unread === b.unread,
  });
  readonly entryId = computed(() => this.parsed().entryId);

  readonly openEntry = computed(
    () => this.entries.entries().find((e) => e.id === this.entryId()) ?? null,
  );
  readonly hasMore = computed(() => this.entries.nextCursor() !== null);
  readonly canMarkAllRead = computed(() => markReadTarget(this.selection()) !== null);
  readonly paneMode = computed(() => this.layout.mode() === 'pane' && this.screen.isWide());
  /** Mobile drawer state; the sidebar is a fixed overlay below 720px. */
  readonly sidebarOpen = signal(false);

  readonly title = computed(() => {
    const s = this.selection();
    if (s.kind === 'favorites') return 'Favorites';
    if (s.kind === 'kept') return 'Kept';
    if (s.kind === 'all') return 'All items';
    if (s.kind === 'tag')
      return this.subs.tagTree().find((n) => n.tag.id === s.id)?.tag.name ?? 'Tag';
    return this.subs.subscriptions().find((x) => x.id === s.id)?.title ?? 'Feed';
  });

  private readonly markedOnOpen = new Set<number>();

  private readonly index = computed(() =>
    this.entries.entries().findIndex((e) => e.id === this.entryId()),
  );
  readonly hasPrev = computed(() => this.index() > 0);
  readonly hasNext = computed(() => {
    const i = this.index();
    return i >= 0 && i < this.entries.entries().length - 1;
  });

  constructor() {
    // Reload the list whenever the selection (not the open entry) changes.
    effect(() => {
      const q = queryFromSelection(this.selection());
      untracked(() => this.entries.load(q));
    });
    // Dismiss the mobile drawer once a new selection is chosen from it.
    effect(() => {
      this.selection();
      untracked(() => this.sidebarOpen.set(false));
    });
    // Mark the opened entry read exactly once per session — even if the PATCH
    // fails and the entry rolls back to unread, we never re-fire the request.
    effect(() => {
      const e = this.openEntry();
      if (e && !e.isRead && !this.markedOnOpen.has(e.id)) {
        this.markedOnOpen.add(e.id);
        untracked(() => this.setRead(e, true));
      }
    });
  }

  ngOnInit(): void {
    this.subs.load();
    if (!this.auth.user()) this.auth.loadMe().subscribe({ error: () => undefined });
  }

  onFavorite = (e: EntryDto): void => this.entries.setState(e.id, { isFavorite: !e.isFavorite });
  onKeep = (e: EntryDto): void => this.entries.setState(e.id, { isKept: !e.isKept });
  onToggleRead = (e: EntryDto): void => this.setRead(e, !e.isRead);

  /** Reader-view outputs are payload-less; apply them to the currently open entry. */
  withOpen(fn: (e: EntryDto) => void): void {
    const e = this.openEntry();
    if (e) fn(e);
  }

  private setRead(e: EntryDto, read: boolean): void {
    // Apply the unread-count change optimistically and revert it if the PATCH
    // fails, so the sidebar count never desyncs from the entry's rolled-back flag.
    if (read) this.subs.decrementUnread(e.subscriptionId);
    else this.subs.incrementUnread(e.subscriptionId);
    this.entries.setState(e.id, { isRead: read }, () => {
      if (read) this.subs.incrementUnread(e.subscriptionId);
      else this.subs.decrementUnread(e.subscriptionId);
    });
  }

  onOpen(e: EntryDto): void {
    void this.router.navigate([], {
      relativeTo: this.route,
      queryParams: { entry: e.id },
      queryParamsHandling: 'merge',
    });
  }
  onCloseReader(): void {
    void this.router.navigate([], {
      relativeTo: this.route,
      queryParams: { entry: null },
      queryParamsHandling: 'merge',
    });
  }
  onPrev(): void {
    const i = this.index();
    if (i > 0) this.onOpen(this.entries.entries()[i - 1]);
  }
  onNext(): void {
    const i = this.index();
    if (i >= 0 && i < this.entries.entries().length - 1) this.onOpen(this.entries.entries()[i + 1]);
  }

  onMarkAllRead(): void {
    const t = markReadTarget(this.selection());
    if (!t) return;
    const until = this.entries.loadedAt() || new Date().toISOString();
    this.api.markRead(t.scope, until, t.id).subscribe({
      next: () => {
        this.subs.zeroUnread(
          t.scope === 'all' ? 'all' : t.scope === 'tag' ? { tag: t.id! } : { subscription: t.id! },
        );
        this.entries.load(queryFromSelection(this.selection()));
      },
    });
  }

  onRefresh(): void {
    this.refreshSvc.run(() => {
      this.subs.load();
      this.entries.load(queryFromSelection(this.selection()));
    });
  }

  onAddFeed(): void {
    const ref = this.dialog.open<SubscriptionDto>(AddFeedDialogComponent);
    ref.closed.subscribe((sub) => {
      if (!sub) return;
      this.subs.load();
      void this.router.navigate([], {
        relativeTo: this.route,
        queryParams: { subscription: sub.id, view: null, tag: null, entry: null },
        queryParamsHandling: 'merge',
      });
    });
  }
}
