// src/app/reader/reader-shell.component.ts
import {
  AfterViewInit,
  Component,
  ElementRef,
  OnDestroy,
  OnInit,
  computed,
  effect,
  inject,
  signal,
  untracked,
  viewChild,
} from '@angular/core';
import { ActivatedRoute, Router, RouterLink, convertToParamMap } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { Dialog } from '@angular/cdk/dialog';
import { Title } from '@angular/platform-browser';
import { AuthService } from '../core/auth.service';
import { ReaderApi } from './reader-api';
import { SubscriptionsStore } from './subscriptions.store';
import { TagsStore } from './tags.store';
import { EntriesStore } from './entries.store';
import { RefreshService } from './refresh.service';
import { RecommendationsService } from './recommendations.service';
import { refreshFailureKey } from './refresh-message';
import { AiAvailabilityService } from '../core/ai-availability.service';
import { ReadingLayoutService } from './reading-layout.service';
import { LayoutService } from './layout.service';
import { RefreshScope, markReadTarget, queryFromSelection, selectionFromParams } from './query';
import { ListScrollReset } from './list-scroll-reset';
import { entryParam } from './slug';
import { EntryDto, EntryStatePatch, SubscriptionDto, SubscriptionTagDto, TagDto } from './models';
import { headerHiddenAtRest, nextHeaderHidden } from './header-scroll';
import { ReaderHeaderComponent } from './header/reader-header.component';
import { SidebarComponent } from './sidebar/sidebar.component';
import { EntryListComponent } from './entry-list/entry-list.component';
import { ReaderViewComponent } from './reader-view/reader-view.component';
import { AddFeedDialogComponent } from './add-feed/add-feed-dialog.component';
import {
  ConfirmData,
  ConfirmDialogComponent,
} from '../shared/confirm-dialog/confirm-dialog.component';
import { ActionSheet } from '../shared/action-sheet/action-sheet.service';
import { ManageActions } from './manage/manage-actions.service';
import { DrawerSwipeDirective } from './drawer-swipe.directive';
import { CatalogStore } from '../discover/catalog.store';
import { OnboardingSkip } from '../discover/onboarding-skip';
import { ProgressHairlineComponent } from '../shared/progress-hairline/progress-hairline.component';
import { ForYouProgressComponent } from './for-you-progress/for-you-progress.component';
import { IconComponent } from '../shared/icon/icon.component';
import { ButtonComponent } from '../shared/button/button.component';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';

@Component({
  selector: 'app-reader-shell',
  imports: [
    ReaderHeaderComponent,
    SidebarComponent,
    EntryListComponent,
    ReaderViewComponent,
    DrawerSwipeDirective,
    ProgressHairlineComponent,
    ForYouProgressComponent,
    IconComponent,
    ButtonComponent,
    RouterLink,
    TranslocoPipe,
  ],
  templateUrl: './reader-shell.component.html',
  styleUrl: './reader-shell.component.scss',
})
export class ReaderShellComponent implements OnInit, AfterViewInit, OnDestroy {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly dialog = inject(Dialog);
  private readonly actionSheet = inject(ActionSheet);
  private readonly i18n = inject(TranslocoService);
  private readonly api = inject(ReaderApi);
  private readonly auth = inject(AuthService);
  private readonly hostRef = inject(ElementRef<HTMLElement>);

  readonly manage = inject(ManageActions);
  readonly subs = inject(SubscriptionsStore);
  readonly tags = inject(TagsStore);
  readonly entries = inject(EntriesStore);
  readonly refreshSvc = inject(RefreshService);
  readonly recs = inject(RecommendationsService);
  readonly ai = inject(AiAvailabilityService);
  readonly layout = inject(ReadingLayoutService);
  readonly screen = inject(LayoutService);
  private readonly skip = inject(OnboardingSkip);
  private readonly catalog = inject(CatalogStore);
  private readonly titleService = inject(Title);
  /** Injected for its effect: it watches navigations so that a clicked list
   *  starts at the top while a list returned to keeps its place (#286). The
   *  reader is the only place that imports it, which is what keeps it out of
   *  the initial bundle. */
  private readonly listScrollReset = inject(ListScrollReset);
  private readonly baseTitle = 'simple feed reader';

  /** Is the picker worth showing at all? Nothing seeds the catalog — it arrives
   *  by admin import — so a deployment without one must not redirect anybody
   *  into a blank page. */
  private readonly onboardingAvailable = computed(
    () => this.catalog.resolved() && this.catalog.hasEntries(),
  );

  /** Admins get the catalog resolved unconditionally — they are the only ones
   *  who can fix an empty one, and the suppressed onboarding is otherwise
   *  invisible. One cached request per session. */
  private readonly loadCatalogForAdmin = effect(() => {
    if (this.auth.isAdmin()) untracked(() => this.catalog.load());
  });

  readonly showCatalogEmptyWarning = computed(
    () => this.auth.isAdmin() && this.catalog.resolved() && !this.catalog.hasEntries(),
  );

  /** A brand-new subscription set: rows exist, none has ever been fetched. This
   *  is what a just-completed onboarding looks like from the shell's side. */
  private readonly awaitingFirstFetch = computed(
    () =>
      this.subs.resolved() &&
      this.subs.subscriptions().length > 0 &&
      this.subs.subscriptions().every((s) => s.lastFetchedAt === null),
  );

  private readonly sweptOnce = signal(false);
  /** True only for the span of the post-onboarding sweep — set when it fires,
   *  cleared once it lands without error. `sweptOnce` is a permanent one-way
   *  latch, so gating the banner on it would re-show the counted banner on every
   *  later refresh (sidebar button, scoped, add-feed) over an already-populated
   *  list. This sweep-scoped flag is what keeps the banner to the sweep alone. */
  private readonly sweeping = signal(false);

  /** The counted banner belongs to the post-onboarding sweep only. Every other
   *  refresh has the hairline, which is enough context for a user who already
   *  knows what their reader looks like. A failure takes the strip over, so the
   *  two never compete for it. */
  readonly showFetchProgress = computed(
    () => this.sweeping() && this.refreshSvc.failure() === null,
  );

  /** What to tell the user about a refresh that fetched nothing, from ANY
   *  refresh — not just the sweep. Gating this on the sweep window is what left
   *  a failed sidebar refresh, scoped refresh or add-feed silent (#119). */
  readonly fetchFailureKey = computed(() => {
    const failure = this.refreshSvc.failure();
    return failure ? refreshFailureKey(failure) : null;
  });

  readonly fetchProgress = computed(() => {
    const report = this.refreshSvc.report();
    if (!report) return { done: 0, total: 0 };
    return { done: report.total - report.remaining, total: report.total };
  });

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

  // A deep-linked entry the current list page doesn't contain, fetched by id.
  private readonly fetchedEntry = signal<EntryDto | null>(null);
  readonly openEntry = computed(() => {
    const id = this.entryId();
    if (id == null) return null;
    const inList = this.entries.entries().find((e) => e.id === id);
    if (inList) return inList; // the live list copy wins (freshest state)
    const fetched = this.fetchedEntry();
    return fetched && fetched.id === id ? fetched : null;
  });
  /** Feed tags keyed by subscription id — feeds the tag pills on entries and the
   *  article view without threading tags through each entry DTO. */
  readonly feedTags = computed(() => {
    const m = new Map<number, SubscriptionTagDto[]>();
    for (const s of this.subs.subscriptions()) m.set(s.id, s.tags);
    return m;
  });
  readonly openEntryTags = computed(() => {
    const e = this.openEntry();
    return e ? (this.feedTags().get(e.subscriptionId) ?? []) : [];
  });
  readonly hasMore = computed(() => this.entries.nextCursor() !== null);
  readonly canMarkAllRead = computed(() => markReadTarget(this.selection()) !== null);
  /** What the list header's "Last refreshed" hint shows: a feed's fetch time,
   *  or the for-you list's generation time. Null everywhere else. */
  readonly listLastRefreshed = computed(() => {
    const s = this.selection();
    if (s.kind === 'for-you') return this.recs.generatedAt();
    if (s.kind !== 'subscription') return null;
    return this.subs.subscriptions().find((x) => x.id === s.id)?.lastFetchedAt ?? null;
  });
  readonly paneMode = computed(() => this.layout.mode() === 'pane' && this.screen.isWide());
  /** An article filling the whole main area (not the split pane) — the top bar
   *  takes over its back button, reader switch and prev/next. */
  readonly articleFullscreen = computed(() => this.openEntry() !== null && !this.paneMode());

  /** The user's tags for the mobile swipe row (the sidebar covers wider screens). */
  readonly headerTags = computed<TagDto[]>(() => this.subs.tagTree().map((n) => n.tag));
  readonly activeTagId = computed(() => {
    const s = this.selection();
    return s.kind === 'tag' ? (s.id ?? null) : null;
  });
  readonly allItemsActive = computed(() => this.selection().kind === 'all');

  // Mobile hide-on-scroll app bar — the LIST's chrome, driven exclusively by
  // the entry list's typed `scrolled` output. A full-screen article is a layer
  // above this bar with its own toolbar, so opening or closing one never
  // touches it (#128).
  readonly headerHidden = signal(false);
  readonly headerHeight = signal(0);
  private readonly hdr = viewChild('hdr', { read: ElementRef });
  /** Only one of the two template branches renders a list at a time. */
  private readonly list = viewChild(EntryListComponent);
  private lastListScrollTop = 0;
  private resizeObs?: ResizeObserver;
  /** Mobile drawer state; the sidebar is a fixed overlay below 720px. */
  readonly sidebarOpen = signal(false);
  /** Mirror of the sidebar's Organise model. Owned here so closing the drawer
   *  can reset it, and so the close-swipe pauses while a drag is possible. */
  readonly sidebarOrganising = signal(false);

  /** The tag the list is scoped to, or null for every other selection. The list
   *  header renders its glyph beside the name; the name itself comes from here
   *  too, so the heading and the glyph can never describe different tags. */
  readonly selectedTag = computed(() => {
    const s = this.selection();
    if (s.kind !== 'tag') return null;
    return this.subs.tagTree().find((n) => n.tag.id === s.id)?.tag ?? null;
  });

  readonly title = computed(() => {
    const s = this.selection();
    if (s.kind === 'favorites') return 'Favorites';
    if (s.kind === 'kept') return 'Kept';
    if (s.kind === 'for-you') return 'For you';
    if (s.kind === 'all') return 'All items';
    if (s.kind === 'tag') return this.selectedTag()?.name ?? 'Tag';
    return this.subs.subscriptions().find((x) => x.id === s.id)?.title ?? 'Feed';
  });

  private readonly markedOnOpen = new Set<number>();
  private readonly viewedOnOpen = new Set<number>();

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
    // Mark the opened entry read and viewed, each exactly once per session —
    // even if the PATCH fails and the flags roll back, we never re-fire. One
    // combined request: the endpoint is a partial update, and both flags
    // change at the same moment (the open).
    effect(() => {
      const e = this.openEntry();
      if (!e) return;
      const patch: EntryStatePatch = {};
      if (!e.isRead && !this.markedOnOpen.has(e.id)) {
        this.markedOnOpen.add(e.id);
        patch.isRead = true;
      }
      if (!e.isViewed && !this.viewedOnOpen.has(e.id)) {
        this.viewedOnOpen.add(e.id);
        patch.isViewed = true;
      }
      if (Object.keys(patch).length === 0) return;
      untracked(() => this.applyOpenedPatch(e, patch));
    });
    // Deep link to an entry the current list page doesn't hold: fetch it by id so
    // it still opens. Tracks only entryId; the list copy takes over once loaded.
    effect(() => {
      const id = this.entryId();
      untracked(() => {
        if (id == null) {
          this.fetchedEntry.set(null); // reader closed — drop the stale fetch
          return;
        }
        if (this.entries.entries().some((e) => e.id === id)) return;
        if (this.fetchedEntry()?.id === id) return;
        // Id-guard the async writes: a slow response for a since-abandoned deep
        // link (e.g. Back/Forward between two cold entries) must not clobber the
        // entry now open.
        this.api.entry(id).subscribe({
          next: (r) => {
            if (this.entryId() === id) this.fetchedEntry.set(r.entry);
          },
          error: () => {
            if (this.entryId() === id) this.fetchedEntry.set(null);
          },
        });
      });
    });

    // Set the document title to reflect the current selection or open article.
    effect(() => {
      const entry = this.openEntry();
      const name = this.title();
      const page = entry
        ? entry.title.length > 60
          ? entry.title.slice(0, 60) + '…'
          : entry.title
        : name;
      this.titleService.setTitle(
        page === this.baseTitle ? this.baseTitle : `${page} | ${this.baseTitle}`,
      );
    });

    // Nothing to read and nothing skipped: send the user to the picker. Purely
    // state-driven — no guard, no resolver — and gated on `resolved` so it never
    // fires against a list the server has not answered on yet. Use `replaceUrl`:
    // otherwise Back from /discover lands here and redirects again — a dead Back
    // button.
    effect(() => {
      if (!this.subs.resolved()) return;
      if (this.subs.subscriptions().length > 0) return;
      if (this.skip.wasSkipped()) return;

      // Ask what the catalog holds before deciding. load() is a no-op once
      // resolved, and the store is shared with /discover, so the redirect path
      // still fetches the catalog exactly once. Untracked so the effect depends
      // on the catalog's resolution (onboardingAvailable, below), not on the
      // synchronous loading flag load() sets.
      untracked(() => this.catalog.load());
      if (!this.onboardingAvailable()) return;

      void this.router.navigate(['/discover'], { replaceUrl: true });
    });

    // The post-onboarding sweep, owned BY STATE rather than by being called:
    // RefreshService.run() early-returns while a refresh is already running, so a
    // call made from the picker could be silently swallowed by the shell's own
    // load. Expressing it as "feeds exist that have never been fetched" removes
    // the ordering question entirely.
    effect(() => {
      if (!this.awaitingFirstFetch() || this.sweptOnce()) return;
      this.sweptOnce.set(true);
      this.sweeping.set(true);
      this.refreshSvc.run();
    });

    // Close the sweep window once the onboarding sweep lands without error. A
    // failure keeps it open so the banner's retry stays available until a retry
    // succeeds. Gated on `sweeping` so no later, unrelated refresh reopens it —
    // and RefreshService.run()'s onDone fires on both success and failure, which
    // is why the clear is expressed as state here rather than in that callback.
    effect(() => {
      if (this.sweeping() && !this.refreshSvc.running() && this.refreshSvc.failure() === null) {
        untracked(() => this.sweeping.set(false));
      }
    });

    // Repopulate as slices land, not only when the sweep ends. Landing in a
    // reader that stays empty for two minutes is the bad first impression this
    // whole feature exists to remove.
    effect(() => {
      if (this.refreshSvc.slice() === 0) return;
      untracked(() => {
        this.subs.load();
        this.entries.load(queryFromSelection(this.selection()));
      });
    });

    // Reload the list when a for-you run completes while the user is already
    // on that feed. `completedStamp` starts at 0, which is the signal's
    // initial value, not a completion — the guard keeps a boot from reloading.
    effect(() => {
      if (this.recs.completedStamp() === 0) return;
      untracked(() => {
        if (this.selection().kind === 'for-you') this.entries.load({ view: 'for-you' });
      });
    });

    // The wide layout never hides the bar. Crossing the breakpoint with a
    // retracted one (a phone rotation) must not leave it stuck off-screen:
    // only list scrolls bring it back, and the wide layouts scroll other panes.
    effect(() => {
      if (this.screen.isWide()) untracked(() => this.headerHidden.set(false));
    });
  }

  ngOnInit(): void {
    this.subs.load();
    this.tags.load(); // the sidebar tag tree (order, empty tags) reads TagsStore
    if (!this.auth.user()) this.auth.loadMe().subscribe({ error: () => undefined });
    // Reopening the app resumes a for-you run left in flight by an earlier session.
    this.recs.resume();
  }

  ngAfterViewInit(): void {
    const hdrEl = this.hdr()?.nativeElement as HTMLElement | undefined;
    if (hdrEl && typeof ResizeObserver !== 'undefined') {
      // Floor the *fractional* rendered height, not `offsetHeight`. With the
      // mobile tag row present the bar's real height is fractional, and
      // `offsetHeight` rounds it — rounding up drops every element anchored at
      // `--app-bar-h` (the list header, the drawer) a sub-pixel *below* the
      // bar's true bottom edge, opening a hairline the scrolling list shows
      // through on iOS Safari (#122). Flooring lands them at or just under that
      // edge, so the bands overlap instead of gapping.
      this.resizeObs = new ResizeObserver(() =>
        this.headerHeight.set(Math.floor(hdrEl.getBoundingClientRect().height)),
      );
      this.resizeObs.observe(hdrEl);
    }
    // This height drives the content area's top padding and the mobile
    // drawer's offset, not just how far the header slides. The observer's
    // first callback covers the initial measurement; until it lands the
    // stylesheet's own 56px (the bare bar, no tag row) holds, which is why
    // the template binds `headerHeight() || null` rather than a raw 0.
  }

  ngOnDestroy(): void {
    this.resizeObs?.disconnect();
  }

  /**
   * Publish the bar's geometry to everything under the shell as inherited
   * custom properties (#87):
   *
   *   --app-bar-h      how much space a scrolling pane reserves at its top
   *   --app-bar-shift  how far a pane's own sticky furniture travels with the
   *                    bar when it retracts, so nothing is left hanging in the
   *                    gap the bar leaves behind
   *
   * Both are constants from the panes' point of view — `--app-bar-h` never
   * changes with the hidden state, which is what keeps their geometry fixed.
   * The bar itself never changes with an article either: the full-screen
   * article is a layer above it with its own toolbar, so this height cannot
   * churn when one opens or closes (#128).
   * Set imperatively rather than through a `[style.--x]` binding so there is no
   * doubt about custom-property support in the template compiler.
   */
  private readonly _publishBarVars = effect(() => {
    const style = this.hostRef.nativeElement.style;
    const h = this.headerHeight();
    if (h > 0) style.setProperty('--app-bar-h', `${h}px`);
    style.setProperty('--app-bar-shift', this.headerHidden() ? `-${h}px` : '0px');
  });

  /**
   * The mobile drawer hangs below the header, so it must never open under a
   * retracted one — that would leave a strip of backdrop where the bar should
   * be. On close the header returns to what the *list's* scroll position
   * implies — asked of the list itself, because the last scroller to fire may
   * have been an article's, whose offset says nothing about the list (#128).
   * Leaving the bar expanded over a scrolled-down list dead-zones touch-scroll
   * in the strip it overlays (it covers the list but is not its scroller),
   * which reads as the list refusing to scroll until the swipe starts below
   * the bar.
   */
  setSidebarOpen(open: boolean): void {
    this.headerHidden.set(open ? false : this.restingHeaderHidden());
    if (!open) this.sidebarOrganising.set(false);
    this.sidebarOpen.set(open);
  }

  /** The bar state the list's own scroll offset implies, asked of the list
   *  itself: the last scroller to fire may have been an article's, whose offset
   *  says nothing about the list (#128). */
  private restingHeaderHidden(): boolean {
    return headerHiddenAtRest(this.list()?.currentScrollTop() ?? 0, this.screen.isWide());
  }

  /** The top bar's empty middle was tapped: send the list back to the top. */
  onScrollListTop(): void {
    this.list()?.scrollToTop();
  }

  /**
   * The entry list scrolled. This typed output is the bar's ONE scroll source:
   * the shell used to capture-listen for scroll events across everything
   * beneath it and guess which scroller mattered — the article overlay's (its
   * own coordinate space, so deltas across the two were nonsense) and the tag
   * row's (horizontal, so its snap events read as scrollTop 0, springing the
   * retracted bar back via the near-top rule) both fed it (#128).
   */
  onListScrolled(top: number): void {
    const previous = this.lastListScrollTop;
    this.lastListScrollTop = top;
    // While the drawer is open the header must stay shown (it hangs below the
    // bar). Inertial scrolling keeps firing scroll events after the open-swipe's
    // touchend, so the gesture handler cannot be trusted as the last word — guard
    // here. The offset still advances above, so the next real scroll sees no
    // phantom jump once the drawer closes.
    if (this.sidebarOpen()) return;
    this.headerHidden.set(
      nextHeaderHidden(this.headerHidden(), previous, top, this.screen.isWide()),
    );
  }

  // Toggle favourite/kept and keep the sidebar badge in sync optimistically,
  // reverting the count if the PATCH fails (mirrors the unread-count handling).
  onFavorite = (e: EntryDto): void => {
    const delta = e.isFavorite ? -1 : 1;
    this.subs.bumpFavorites(delta);
    this.patchOpen(e, { isFavorite: !e.isFavorite }, () => this.subs.bumpFavorites(-delta));
  };
  onKeep = (e: EntryDto): void => {
    const delta = e.isKept ? -1 : 1;
    this.subs.bumpKept(delta);
    this.patchOpen(e, { isKept: !e.isKept }, () => this.subs.bumpKept(-delta));
  };
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
    this.patchOpen(e, { isRead: read }, () => {
      if (read) this.subs.incrementUnread(e.subscriptionId);
      else this.subs.decrementUnread(e.subscriptionId);
    });
  }

  /** The on-open patch: read + viewed in one request, with the unread badge
   *  kept in sync (and reverted on failure) only for the read part — viewed
   *  has no badge. */
  private applyOpenedPatch(e: EntryDto, patch: EntryStatePatch): void {
    if (patch.isRead) this.subs.decrementUnread(e.subscriptionId);
    this.patchOpen(e, patch, () => {
      if (patch.isRead) this.subs.incrementUnread(e.subscriptionId);
    });
  }

  /** Following the original-article link is an active open even when the
   *  entry was opened before; the flag is one-way, so an already-viewed
   *  entry is a no-op (this fires only after an on-open PATCH rolled back). */
  onOpenOriginal = (e: EntryDto): void => {
    if (!e.isViewed) this.patchOpen(e, { isViewed: true });
  };

  /** Apply an entry-state change. Entries in the loaded list go through the
   *  store's optimistic path; a cold-opened deep-link entry (in no list) is
   *  patched on its fetched copy and persisted directly, reverting on failure. */
  private patchOpen(e: EntryDto, patch: EntryStatePatch, onError?: () => void): void {
    if (this.entries.entries().some((x) => x.id === e.id)) {
      this.entries.setState(e.id, patch, onError);
      return;
    }
    const before = this.fetchedEntry();
    this.fetchedEntry.update((cur) => (cur && cur.id === e.id ? { ...cur, ...patch } : cur));
    this.api.updateState(e.id, patch).subscribe({
      error: () => {
        // Only revert if the same cold entry is still open — a Back/Forward to
        // another cold entry while the PATCH was in flight must not be clobbered.
        this.fetchedEntry.update((cur) => (cur && cur.id === e.id ? before : cur));
        onError?.();
      },
    });
  }

  onOpen(e: EntryDto): void {
    void this.router.navigate([], {
      relativeTo: this.route,
      queryParams: { entry: entryParam(e.id, e.title) },
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
      this.tags.load();
      this.entries.load(queryFromSelection(this.selection()));
    });
  }

  /** Map the current selection to a refresh scope, or null where a scoped
   *  refresh doesn't apply (the cross-feed favorites/kept views). A subscription
   *  resolves to its underlying feed id — the API keys refresh by feed, and a
   *  subscription id is a different id space. */
  private refreshScope(s = this.selection()): RefreshScope | null {
    switch (s.kind) {
      case 'all':
        return {};
      case 'tag':
        return s.id != null ? { tagId: s.id } : null;
      case 'subscription': {
        const feedId = this.subs.subscriptions().find((x) => x.id === s.id)?.feedId;
        return feedId != null ? { feedId } : null;
      }
      default:
        return null;
    }
  }

  /** The list-scoped refresh (header button + mobile pull): sweep only the feeds
   *  behind the current selection, then reload the list once it lands. */
  onScopedRefresh(): void {
    const scope = this.refreshScope();
    if (!scope) return;
    this.refreshSvc.run(() => {
      this.subs.load();
      this.tags.load();
      this.entries.load(queryFromSelection(this.selection()));
    }, scope);
  }

  /** The header button's start path: a for-you run is long and spends provider
   *  budget, so it is confirmed every time before it begins. The run itself,
   *  its poll loop, and its stop live in `RecommendationsService`; this only
   *  guards the door.
   *
   *  A leftover failed run can be resumed at the batch that failed rather than
   *  redone from scratch, but its candidate snapshot is frozen from when it
   *  first started -- so the choice is the user's, not a silent resume (#329). */
  startRecommendations(): void {
    if (this.recs.report()?.status === 'failed') {
      this.chooseResumeOrFreshRun();
      return;
    }
    this.confirmFreshRun();
  }

  private confirmFreshRun(): void {
    const data: ConfirmData = {
      title: this.i18n.translate('reader.forYouRunConfirm'),
      message: this.i18n.translate('reader.forYouRunConfirmMessage'),
      confirmLabel: this.i18n.translate('reader.forYouRun'),
    };
    const ref = this.dialog.open<boolean>(ConfirmDialogComponent, {
      data,
      role: 'alertdialog',
      panelClass: 'app-dialog',
    });
    ref.closed.subscribe((confirmed) => {
      if (confirmed) this.recs.start();
    });
  }

  /** An unfinished (failed) run is waiting: offer to resume it or start over,
   *  rather than silently picking one. Both choices spend provider budget, so
   *  the sheet itself stands in for the plain confirm. */
  private chooseResumeOrFreshRun(): void {
    this.actionSheet
      .open({
        title: this.i18n.translate('reader.forYouUnfinishedTitle'),
        actions: [
          { id: 'resume', label: this.i18n.translate('reader.forYouResume') },
          { id: 'fresh', label: this.i18n.translate('reader.forYouStartOver') },
        ],
      })
      .subscribe((choice) => {
        if (choice === 'resume') this.recs.resumeRun();
        else if (choice === 'fresh') this.recs.start();
      });
  }

  onAddFeed(): void {
    const ref = this.dialog.open<SubscriptionDto>(AddFeedDialogComponent, {
      panelClass: 'app-dialog',
    });
    ref.closed.subscribe((sub) => {
      if (!sub) return;
      this.subs.load();
      void this.router.navigate([], {
        relativeTo: this.route,
        queryParams: { subscription: sub.id, view: null, tag: null, entry: null },
        queryParamsHandling: 'merge',
      });
      // A feed discovery could read arrives with its entries already stored
      // (#290), so there is nothing left to fetch — and asking the same host
      // again a second later is precisely what a rationing site answers with
      // 429. Only a feed that came in unfetched, the scraped shortcut, still
      // needs its first fetch; scope it to that one feed so it stays fast.
      if (sub.lastFetchedAt) {
        this.entries.load(queryFromSelection(this.selection()));
        return;
      }
      this.refreshSvc.run(
        () => {
          this.subs.load();
          this.entries.load(queryFromSelection(this.selection()));
        },
        { feedId: sub.feedId },
      );
    });
  }
}
