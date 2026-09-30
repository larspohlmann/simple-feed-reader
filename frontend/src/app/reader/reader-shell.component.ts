import {
  AfterViewInit,
  Component,
  ElementRef,
  OnDestroy,
  OnInit,
  afterRenderEffect,
  computed,
  effect,
  inject,
  signal,
  untracked,
  viewChild,
} from '@angular/core';
import { NgTemplateOutlet } from '@angular/common';
import { EntryActionHandler } from './entry-actions/entry-action-handler';
import { ReaderRouteState } from './shell/reader-route-state.service';
import { ListHeading } from './shell/list-heading.service';
import { EntryStateActions } from './shell/entry-state-actions.service';
import { MarkReadActions } from './shell/mark-read-actions.service';
import { ReaderOnboarding } from './shell/reader-onboarding.service';
import { PasskeyFirstBootOffer } from './shell/passkey-first-boot-offer.service';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Dialog } from '@angular/cdk/dialog';
import { AuthService } from '../core/auth/auth.service';
import { PageTitleService } from '../core/i18n/page-title.service';
import { EntryBodyService } from './entry-body.service';
import { SubscriptionsStore } from './subscriptions.store';
import { TagsStore } from './tags.store';
import { EntriesStore } from './entries.store';
import { RefreshService } from './refresh.service';
import { RecommendationsService } from './recommendations.service';
import { SavedSearchesStore } from './saved-searches.store';
import { refreshFailureKey } from './refresh-message';
import { AiAvailabilityService } from '../core/ai-availability.service';
import { DigestService } from '../core/preferences/digest.service';
import { VersionService } from '../core/version.service';
import { ReadingLayoutService } from './reading-layout.service';
import { LayoutService } from './layout.service';
import { SidebarVisibilityService } from './sidebar-visibility.service';
import {
  RefreshScope,
  isDirectSearch,
  isWholeWordTerm,
  isPhraseTerm,
  queryFromSelection,
  selectionQueryParams,
  visibleSearchTerm,
} from './query';
import { UnreadFilterService } from './unread-filter.service';
import { ListOrderService } from './list-order.service';
import { ListPreferences } from './list-preferences.service';
import { ListScrollReset } from './list-scroll-reset';
import { EntryDto, SavedSearchDto, SubscriptionDto, SubscriptionTagDto, TagDto } from './models';
import { ReaderHeaderComponent } from './header/reader-header.component';
import { SidebarComponent } from './sidebar/sidebar.component';
import { EntryListComponent } from './entry-list/entry-list.component';
import { ReaderViewComponent } from './reader-view/reader-view.component';
import { AudioPlayerBarComponent } from './audio-player-bar/audio-player-bar.component';
import { AddFeedDialogComponent } from './add-feed/add-feed-dialog.component';
import { ConfirmData } from '../shared/confirm-dialog/confirm-dialog.component';
import { ConfirmService } from '../shared/confirm-dialog/confirm.service';
import { ActionSheet } from '../shared/action-sheet/action-sheet.service';
import { ManageActions } from './manage/manage-actions.service';
import { DrawerSwipeDirective } from './drawer-swipe.directive';
import { PaneResizeDirective } from './pane-resize.directive';
import { SidebarCountsPoll } from './sidebar-counts-poll.service';
import { IconComponent } from '../shared/icon/icon.component';
import { IconButtonDirective } from '../shared/icon-button/icon-button.directive';
import { ListActionDirective } from '../shared/list-action/list-action.directive';
import { ButtonComponent } from '../shared/button/button.component';
import { FeedIntroComponent } from './feed-intro/feed-intro.component';
import { CONFIRMATION_DURATION_MS, ToastService } from '../shared/toast/toast.service';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';

@Component({
  selector: 'app-reader-shell',
  imports: [
    NgTemplateOutlet,
    ReaderHeaderComponent,
    SidebarComponent,
    EntryListComponent,
    ReaderViewComponent,
    AudioPlayerBarComponent,
    DrawerSwipeDirective,
    PaneResizeDirective,
    IconComponent,
    IconButtonDirective,
    ListActionDirective,
    ButtonComponent,
    FeedIntroComponent,
    RouterLink,
    TranslocoPipe,
  ],
  templateUrl: './reader-shell.component.html',
  styleUrl: './reader-shell.component.scss',
  // Per-reader state: provided here so none of it outlives the reader.
  providers: [
    SidebarCountsPoll,
    ReaderRouteState,
    ListHeading,
    EntryStateActions,
    MarkReadActions,
    ReaderOnboarding,
    PasskeyFirstBootOffer,
    { provide: EntryActionHandler, useExisting: EntryStateActions },
  ],
})
export class ReaderShellComponent implements OnInit, AfterViewInit, OnDestroy {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly dialog = inject(Dialog);
  private readonly confirm = inject(ConfirmService);
  private readonly toast = inject(ToastService);
  private readonly actionSheet = inject(ActionSheet);
  private readonly i18n = inject(TranslocoService);
  private readonly bodyService = inject(EntryBodyService);
  protected readonly auth = inject(AuthService);
  private readonly hostRef = inject(ElementRef<HTMLElement>);

  readonly manage = inject(ManageActions);
  readonly subs = inject(SubscriptionsStore);
  readonly tags = inject(TagsStore);
  readonly entries = inject(EntriesStore);
  readonly refreshSvc = inject(RefreshService);
  readonly recs = inject(RecommendationsService);
  readonly savedSearchesStore = inject(SavedSearchesStore);
  readonly ai = inject(AiAvailabilityService);
  readonly digest = inject(DigestService);
  private readonly versions = inject(VersionService);
  readonly layout = inject(ReadingLayoutService);
  readonly screen = inject(LayoutService);
  readonly sidebarVisibility = inject(SidebarVisibilityService);
  private readonly pageTitle = inject(PageTitleService);
  /** Injected for its effect: it watches navigations so that a clicked list
   *  starts at the top while a list returned to keeps its place (#286). The
   *  reader is the only place that imports it, which is what keeps it out of
   *  the initial bundle. */
  private readonly listScrollReset = inject(ListScrollReset);
  /** Injected for its timer: it keeps every count on screen — sidebar badges,
   *  list heading, tab title, all reading the same two stores (#709) — moving
   *  on its own while the reader is open (#708). Holding it starts it. */
  private readonly countsPoll = inject(SidebarCountsPoll);

  protected readonly onboarding = inject(ReaderOnboarding);
  /** Injected for its effect: holding it opens the passkey offer when due. */
  private readonly passkeyOffer = inject(PasskeyFirstBootOffer);

  /** What to tell the user about a refresh that fetched nothing, from ANY
   *  refresh — not just the sweep. Gating this on the sweep window is what left
   *  a failed sidebar refresh, scoped refresh or add-feed silent (#119). */
  readonly fetchFailureKey = computed(() => {
    const failure = this.refreshSvc.failure();
    return failure ? refreshFailureKey(failure) : null;
  });

  readonly unreadFilter = inject(UnreadFilterService);
  readonly listOrder = inject(ListOrderService);
  private readonly listPreferences = inject(ListPreferences);
  readonly listLoading = computed(() => this.entries.loading() || !this.listPreferences.ready());
  private readonly routeState = inject(ReaderRouteState);
  readonly selection = this.routeState.selection;
  readonly entryId = this.routeState.entryId;
  readonly openEntry = this.routeState.openEntry;
  readonly heading = inject(ListHeading);
  readonly entryActions = inject(EntryStateActions);
  readonly markRead = inject(MarkReadActions);
  readonly viewingSavedSearch = computed(() => this.selection().kind === 'saved-search');
  /** Whether the header offers its Save/Remove control: a direct search can be
   *  saved, a saved search removed. Named so a third search-like kind can't slip
   *  the gate the way #1118 dropped this one. */
  readonly canToggleSavedSearch = computed(
    () => isDirectSearch(this.selection()) || this.viewingSavedSearch(),
  );

  /** Feed tags keyed by subscription id — feeds the tag pills on entries and the
   *  article view without threading tags through each entry DTO. */
  readonly feedTags = computed(() => {
    const tagsBySubscription = new Map<number, SubscriptionTagDto[]>();
    for (const subscription of this.subs.subscriptions())
      tagsBySubscription.set(subscription.id, subscription.tags);
    return tagsBySubscription;
  });
  readonly openEntryTags = computed(() => {
    const entry = this.openEntry();
    return entry ? (this.feedTags().get(entry.subscriptionId) ?? []) : [];
  });
  readonly paneMode = computed(() => this.layout.mode() === 'pane' && this.screen.isWide());
  readonly searchPane = computed(() => this.screen.isWide() && isDirectSearch(this.selection()));
  readonly splitView = computed(() => this.paneMode() || this.searchPane());
  /** An article filling the whole main area (not the split pane) — the top bar
   *  takes over its back button, reader switch and prev/next. */
  readonly articleFullscreen = computed(() => this.openEntry() !== null && !this.splitView());

  /** The user's tags for the mobile swipe row (the sidebar covers wider screens). */
  readonly headerTags = computed<TagDto[]>(() => this.subs.tagTree().map((node) => node.tag));
  readonly activeTagId = computed(() => {
    const selection = this.selection();
    return selection.kind === 'tag' ? (selection.id ?? null) : null;
  });
  readonly allItemsActive = computed(() => this.selection().kind === 'all');

  readonly headerHeight = signal(0);
  private readonly hdr = viewChild('hdr', { read: ElementRef });
  /** Only one of the two template branches renders a list at a time. */
  private readonly list = viewChild(EntryListComponent);
  private readonly header = viewChild(ReaderHeaderComponent);
  /** Whether the mobile header's own search bar covers it — read from the
   *  child rather than owned here, since the bar's open/closed state (trigger,
   *  close button, Escape, outside click) is entirely the header's business. */
  private readonly headerSearchOpen = computed(() => this.header()?.searchOpen() ?? false);
  /** Either overlay hanging off the header -- drawer or search bar -- force-
   *  shows it. A single derived signal, read from the one place that applies
   *  the rule, so the two overlays can never disagree the way two writers once did. */
  private readonly headerOverlayOpen = computed(
    () => this.sidebarOpen() || this.headerSearchOpen(),
  );
  // Mobile hide-on-scroll app bar — the LIST's chrome, not a separate state: it
  // mirrors the list's own `collapsed` (same scroll logic, reset on selection
  // change), so the bar reacts to the list scroller alone, never an article's or
  // the tag row's (#128), and follows a view switch without a stale-offset
  // recompute (#630). An open overlay (drawer/search bar) force-shows it; a
  // full-screen article sits above the bar with its own toolbar, untouched (#128).
  readonly headerHidden = computed(() =>
    this.headerOverlayOpen() ? false : (this.list()?.collapsed() ?? false),
  );
  private resizeObs?: ResizeObserver;
  /** Mobile drawer state; the sidebar is a fixed overlay below 720px. */
  readonly sidebarOpen = signal(false);
  /** Mirror of the sidebar's Organise model. Owned here so closing the drawer
   *  can reset it, and so the close-swipe pauses while a drag is possible. */
  readonly sidebarOrganising = signal(false);

  /** The wide-layout sidebar is collapsed: the user hid it, and the layout is
   *  not the narrow drawer, where `sidebarOpen` governs instead. Drives both the
   *  `.body` class the stylesheet keys the column's `display` to, and the "Show
   *  sidebar" button the list header offers as the way back. */
  readonly sidebarCollapsed = computed(
    () => this.sidebarVisibility.hidden() && !this.screen.isNarrow(),
  );

  private readonly showSidebarButton =
    viewChild<ElementRef<HTMLButtonElement>>('showSidebarButton');
  /** Armed while the sidebar is visible, so the very first render does not pull
   *  focus onto the (absent) show button. */
  private sidebarWasVisible = false;

  /** When the sidebar collapses, focus the "Show sidebar" button so a keyboard
   *  user who clicked "Hide sidebar" is not dropped to `<body>`. The sidebar's
   *  own collapse button focuses itself the same way when the sidebar returns.
   *  `afterRenderEffect` because the button must be in the DOM already. */
  private readonly focusShowSidebarButton = afterRenderEffect(() => {
    if (!this.sidebarCollapsed()) {
      this.sidebarWasVisible = true;
      return;
    }
    if (!this.sidebarWasVisible) return;
    this.sidebarWasVisible = false;
    this.showSidebarButton()?.nativeElement.focus();
  });

  constructor() {
    // Reload the list and sidebar counts whenever the selection (not the open
    // entry) changes. A new list has no removed rows, so clear the collapsed
    // set with it, else a recycled id would render an incoming row already collapsed.
    effect(() => {
      if (!this.listPreferences.ready()) return;
      const query = queryFromSelection(this.selection());
      untracked(() => {
        this.entryActions.clearLeaving();
        this.entries.load(query);
        this.subs.loadIfStale();
      });
    });
    // Dismiss the mobile drawer once a new selection is chosen from it.
    effect(() => {
      this.selection();
      untracked(() => this.sidebarOpen.set(false));
    });
    // Warm the body store for the entries beside the one just opened. Keyed on
    // the id alone, so an unrelated list reload doesn't re-issue the prefetch.
    effect(() => {
      const id = this.routeState.openEntryId();
      if (id === null) return;
      untracked(() => {
        const list = this.entries.entries();
        const index = list.findIndex((entry) => entry.id === id);
        if (index === -1) return;
        const previous = list[index - 1];
        const next = list[index + 1];
        if (previous) this.bodyService.prefetch(previous.id);
        if (next) this.bodyService.prefetch(next.id);
      });
    });

    // Name the tab after the open article, or after the list when none is open.
    // The reader route carries no title of its own, so this is the only writer
    // while the reader is on screen.
    effect(() => {
      const entry = this.openEntry();
      if (entry !== null) {
        this.pageTitle.useText(entry.title);
        return;
      }
      this.pageTitle.useText(this.heading.title(), this.heading.titleCount().value);
    });

    // The single authority that reloads the list after a refresh (#502): the
    // onboarding sweep reloads on each landing slice, so a new user isn't
    // staring at an empty list (#127); a user-initiated refresh reloads once,
    // on finish, so it never flickers mid-sweep.
    effect(() => {
      const slice = this.refreshSvc.slice();
      const running = this.refreshSvc.running();
      untracked(() => {
        if (slice === 0) return; // nothing has reported yet
        if (!this.onboarding.sweeping() && running) return; // manual refresh: wait for finish
        this.subs.load();
        this.savedSearchesStore.load();
        // A refresh never touches tags, so reload them once when the run
        // finishes rather than on every onboarding slice (onDone reloaded them;
        // the old slice effect did not reload them at all).
        if (!running) this.tags.load();
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
  }

  ngOnInit(): void {
    this.savedSearchesStore.load();
    this.tags.load(); // the sidebar tag tree (order, empty tags) reads TagsStore
    if (!this.auth.user()) this.auth.loadMe().subscribe({ error: () => undefined });
    // Reopening the app resumes a for-you run left in flight by an earlier session.
    this.recs.resume();
    // Check once for a newer release; the sidebar shows the badge if there is
    // one. The backend caches the upstream lookup, so this call is cheap.
    this.versions.load();
  }

  ngAfterViewInit(): void {
    const headerElement = this.hdr()?.nativeElement as HTMLElement | undefined;
    if (headerElement && typeof ResizeObserver !== 'undefined') {
      // Floor the *fractional* rendered height, not `offsetHeight`: with the
      // mobile tag row present the bar's real height is fractional, and
      // rounding up drops elements anchored at `--app-bar-h` a sub-pixel
      // below the bar's true edge, opening a hairline on iOS Safari (#122).
      this.resizeObs = new ResizeObserver(() =>
        this.headerHeight.set(Math.floor(headerElement.getBoundingClientRect().height)),
      );
      this.resizeObs.observe(headerElement);
    }
    // This height drives the content area's top padding and the mobile
    // drawer's offset too, not just the header's slide. Until the observer's
    // first callback lands, the stylesheet's own 56px holds -- hence `headerHeight() || null`.
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
   * Both are constants from the panes' point of view: `--app-bar-h` never
   * changes with hidden state, and the bar never changes with an article
   * either (a full-screen article sits above it with its own toolbar, #128).
   * Set imperatively, not via `[style.--x]`, for certain compiler support.
   */
  private readonly _publishBarVars = effect(() => {
    const style = this.hostRef.nativeElement.style;
    const height = this.headerHeight();
    if (height > 0) style.setProperty('--app-bar-h', `${height}px`);
    style.setProperty('--app-bar-shift', this.headerHidden() ? `-${height}px` : '0px');
  });

  /**
   * The mobile drawer hangs below the header, so it must never open under a
   * retracted one -- that would leave a strip of backdrop where the bar
   * should be. On close, the header returns to what the *list's* scroll
   * position implies (unless the search bar is still open -- see the
   * constructor's `headerOverlayOpen` effect), asked of the list itself since
   * the last scroller to fire may have been an article's (#128). Leaving the
   * bar expanded over a scrolled-down list dead-zones touch-scroll in the
   * strip it overlays, reading as the list refusing to scroll until the swipe
   * starts below the bar.
   */
  setSidebarOpen(open: boolean): void {
    if (!open) this.sidebarOrganising.set(false);
    this.sidebarOpen.set(open);
  }

  /** The top bar's empty middle was tapped: send the list back to the top. */
  onScrollListTop(): void {
    this.list()?.scrollToTop();
  }

  /** Reader-view outputs are payload-less; apply them to the currently open entry. */
  withOpen(action: (entry: EntryDto) => void): void {
    const entry = this.openEntry();
    if (entry) action(entry);
  }

  onCloseReader(): void {
    void this.router.navigate([], {
      relativeTo: this.route,
      queryParams: { entry: null },
      queryParamsHandling: 'merge',
    });
  }

  onMarkAboveRead(ids: number[]): void {
    this.markRead.confirmMarkAboveRead(ids, () => this.list()?.hideAboveMarked(ids));
  }

  // Preserve the underlying list so clearing a direct search returns to it.
  onSearch(term: string): void {
    void this.router.navigate(['/'], {
      queryParams: { q: term || null, entry: null },
      queryParamsHandling: 'merge',
    });
  }

  /** The current search decoded into the pair a saved search stores: the
   *  visible term and the whole-word flag. Null outside a search — the one
   *  place that reads the trailing-space signal, so downstream never re-decodes it (#408). */
  private readonly searchedTermAndMode = computed(() => {
    const selection = this.selection();
    if (selection.kind !== 'search') return null;
    const raw = selection.term ?? '';

    // A phrase (wrapping quotes) overrides whole-word (a trailing space) when a
    // query carries both, exactly as the server decides it (#702), so the
    // whole-word flag is read only when the query is not a phrase.
    const phrase = isPhraseTerm(raw);

    return { term: visibleSearchTerm(raw), wholeWord: !phrase && isWholeWordTerm(raw), phrase };
  });

  /** The saved search matching the current selection, or null. A search's
   *  identity is its visible term plus its mode — the whole-word and phrase
   *  flags — so all three must match. */
  readonly currentSavedSearch = computed(() => {
    if (this.selection().kind === 'saved-search') return this.heading.activeSavedSearch();
    const current = this.searchedTermAndMode();
    if (current === null) return null;

    return (
      this.savedSearchesStore
        .savedSearches()
        .find(
          (saved) =>
            saved.term === current.term &&
            saved.wholeWord === current.wholeWord &&
            saved.phrase === current.phrase,
        ) ?? null
    );
  });

  protected readonly savedSearchActionLabel = computed(() =>
    this.currentSavedSearch() ? 'reader.removeSavedSearch' : 'reader.saveSearch',
  );

  /** Save the search being looked at, or drop it when already saved -- one
   *  command, because the header offers one button whose label/icon flip on
   *  this state. Saving toasts on real HTTP success; removing confirms first (#581). */
  onToggleSavedSearch(): void {
    const saved = this.currentSavedSearch();
    if (saved) {
      this.confirmRemoveSavedSearch(saved.id);

      return;
    }

    const current = this.searchedTermAndMode();
    if (!current) return;
    this.savedSearchesStore.createSavedSearch(current.term, current.wholeWord, current.phrase, () =>
      this.toast.show({
        message: this.i18n.translate('reader.searchSaved'),
        durationMs: CONFIRMATION_DURATION_MS,
      }),
    );
  }

  private confirmRemoveSavedSearch(id: number): void {
    const data: ConfirmData = {
      title: this.i18n.translate('reader.removeSavedSearchConfirm'),
      message: this.i18n.translate('reader.removeSavedSearchConfirmMessage'),
      confirmLabel: this.i18n.translate('reader.removeSavedSearch'),
    };
    this.confirm.confirmThen(data, () => {
      // Removing the search you are viewing by its slug path leaves that path
      // pointing at nothing, so fall back to the combined list; an unsaved
      // `?q=` search stays put and simply flips its button back to Save.
      const returnToCombined = this.viewingSavedSearch()
        ? () => void this.router.navigate(['/searches/saved/all'])
        : undefined;
      this.savedSearchesStore.removeSavedSearch(id, returnToCombined);
    });
  }

  /** The sidebar's per-search mail icon: confirm before flipping
   *  `includeInDigest`, with different copy for turning it on versus off. */
  confirmToggleDigest(row: SavedSearchDto): void {
    const enabling = !row.includeInDigest;
    const data: ConfirmData = enabling
      ? {
          title: this.i18n.translate('reader.digest.enableConfirm'),
          message: this.i18n.translate('reader.digest.enableConfirmMessage', {
            term: row.term,
          }),
          confirmLabel: this.i18n.translate('reader.digest.enableConfirmAction'),
        }
      : {
          title: this.i18n.translate('reader.digest.disableConfirm'),
          message: this.i18n.translate('reader.digest.disableConfirmMessage', {
            term: row.term,
          }),
          confirmLabel: this.i18n.translate('reader.digest.disableConfirmAction'),
        };
    this.confirm.confirmThen(data, () =>
      this.savedSearchesStore.setIncludeInDigest(row.id, enabling),
    );
  }

  /** The global refresh: sweep every due feed. The single reload authority
   *  (#502) reloads the list once the run finishes. */
  onRefresh(): void {
    this.refreshSvc.run();
  }

  /** Map the current selection to a refresh scope, or null where a scoped
   *  refresh doesn't apply (the cross-feed favorites/kept views). A subscription
   *  resolves to its underlying feed id — the API keys refresh by feed, and a
   *  subscription id is a different id space. */
  private refreshScope(selection = this.selection()): RefreshScope | null {
    switch (selection.kind) {
      case 'all':
        return {};
      case 'tag':
        return selection.id != null ? { tagId: selection.id } : null;
      case 'subscription': {
        const feedId = this.heading.selectedSubscription()?.feedId;
        return feedId != null ? { feedId } : null;
      }
      default:
        return null;
    }
  }

  /** The list-scoped refresh (header button + mobile pull): sweep only the feeds
   *  behind the current selection. The single reload authority (#502) reloads the
   *  list once the run finishes, so this path does not. */
  onScopedRefresh(): void {
    const scope = this.refreshScope();
    if (!scope) return;
    this.refreshSvc.run(undefined, scope);
  }

  /** The header button's start path: a for-you run is long and spends provider
   *  budget, so it's confirmed every time before it begins. Run, poll loop and
   *  stop live in `RecommendationsService`; this only guards the door. A
   *  leftover failed run can resume at its failed batch, but its candidate
   *  snapshot is frozen from when it started -- so the choice is the user's (#329). */
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
    this.confirm.confirmThen(data, () => this.recs.start());
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
      this.savedSearchesStore.load();
      void this.router.navigate(['/'], {
        queryParams: selectionQueryParams({ subscription: sub.id }),
        queryParamsHandling: 'merge',
      });
      // A feed discovery could read arrives with its entries already stored
      // (#290) -- nothing left to fetch, and asking again a second later is
      // what a rationing site answers with 429. Scope the fetch to that feed alone.
      if (sub.lastFetchedAt) {
        this.entries.load(queryFromSelection(this.selection()));
        return;
      }
      // The single reload authority (#502) reloads the list once the feed's
      // first fetch finishes, so this path does not.
      this.refreshSvc.run(undefined, { feedId: sub.feedId });
    });
  }
}
