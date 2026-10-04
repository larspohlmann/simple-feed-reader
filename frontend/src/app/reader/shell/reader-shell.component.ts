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
import { EntryActionHandler } from '../entry/entry-actions/entry-action-handler';
import { ReaderRouteState } from './reader-route-state.service';
import { ListHeading } from './list-heading.service';
import { EntryStateActions } from './entry-state-actions.service';
import { MarkReadActions } from './mark-read-actions.service';
import { ReaderOnboarding } from './reader-onboarding.service';
import { PasskeyFirstBootOffer } from './passkey-first-boot-offer.service';
import { SavedSearchToggle } from './saved-search-toggle.service';
import { RefreshActions } from './refresh-actions.service';
import { ListReload } from './list-reload.service';
import { ReaderTabTitle } from './reader-tab-title.service';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { SubscriptionsStore } from '../state/subscriptions.store';
import { TagsStore } from '../state/tags.store';
import { EntriesStore } from '../state/entries.store';
import { RefreshService } from '../state/refresh.service';
import { RecommendationsService } from '../state/recommendations.service';
import { SavedSearchesStore } from '../state/saved-searches.store';
import { AiAvailabilityService } from '../../core/ai-availability.service';
import { DigestService } from '../../core/preferences/digest.service';
import { VersionService } from '../../core/version.service';
import { ReadingLayoutService } from '../reading-layout.service';
import { LayoutService } from '../layout.service';
import { SidebarVisibilityService } from './sidebar-visibility.service';
import { isDirectSearch } from '../query/query';
import { UnreadFilterService } from '../list/unread-filter.service';
import { ListOrderService } from '../list/list-order.service';
import { ListPreferences } from '../list/list-preferences.service';
import { ListScrollReset } from '../scroll/list-scroll-reset';
import { EntryDto, SubscriptionTagDto, TagDto } from '../models';
import { ReaderHeaderComponent } from './header/reader-header.component';
import { SidebarComponent } from './sidebar/sidebar.component';
import { EntryListComponent } from '../list/entry-list/entry-list.component';
import { ReaderViewComponent } from '../article/reader-view/reader-view.component';
import { AudioPlayerBarComponent } from './audio-player-bar/audio-player-bar.component';
import { ManageActions } from '../feeds/manage/manage-actions.service';
import { DrawerSwipeDirective } from './drawer-swipe.directive';
import { PaneResizeDirective } from './pane-resize.directive';
import { SidebarCountsPoll } from './sidebar-counts-poll.service';
import { IconComponent } from '../../shared/icon/icon.component';
import { IconButtonDirective } from '../../shared/icon-button/icon-button.directive';
import { ListActionDirective } from '../../shared/list-action/list-action.directive';
import { FeedIntroComponent } from './feed-intro/feed-intro.component';
import { TranslocoPipe } from '@jsverse/transloco';

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
    SavedSearchToggle,
    RefreshActions,
    ListReload,
    ReaderTabTitle,
    { provide: EntryActionHandler, useExisting: EntryStateActions },
  ],
})
export class ReaderShellComponent implements OnInit, AfterViewInit, OnDestroy {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
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
  readonly savedSearch = inject(SavedSearchToggle);
  readonly refresh = inject(RefreshActions);

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

  private readonly listReload = inject(ListReload);

  /** Dismiss the mobile drawer once a new selection is chosen from it. */
  private readonly _closeDrawerOnSelection = effect(() => {
    this.selection();
    untracked(() => this.sidebarOpen.set(false));
  });

  private readonly tabTitle = inject(ReaderTabTitle);

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
}
