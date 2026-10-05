import {
  Component,
  ElementRef,
  OnDestroy,
  TemplateRef,
  computed,
  effect,
  inject,
  input,
  output,
  signal,
  viewChild,
} from '@angular/core';
import { NgTemplateOutlet } from '@angular/common';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { ErrorBannerComponent } from '../../../shared/error-banner/error-banner.component';
import { SpinnerComponent } from '../../../shared/spinner/spinner.component';
import { LoadingOverlayComponent } from '../../../shared/loading-overlay/loading-overlay.component';
import { ToTopButtonComponent } from '../../../shared/to-top-button/to-top-button.component';
import {
  ProgressRailComponent,
  ProgressRailOrientation,
} from '../../../shared/progress-rail/progress-rail.component';
import { EntryRowComponent } from '../entry-row/entry-row.component';
import { RecommendationStripComponent } from '../recommendation-strip/recommendation-strip.component';
import { RunHeaderComponent } from '../run-header/run-header.component';
import { MagazineBlock } from '../magazine/magazine-block';
import { MagazineBlockComponent, NO_TAGS } from '../magazine/magazine-block.component';
import { ListHeaderComponent, TitleCount } from '../list-header/list-header.component';
import { ListEmptyStateComponent } from '../list-empty-state/list-empty-state.component';
import { ScrollOutsideZoneDirective } from '../../scroll/scroll-outside-zone.directive';
import { ReadingLayout } from '../../reading-layout.service';
import { EntryDto, ListOrder, SubscriptionTagDto, TagDto } from '../../models';
import { Selection, canScopedRefresh, isDirectSearch, searchWords } from '../../query/query';
import { Problem } from '../../../core/problem';
import { LayoutService } from '../../layout.service';
import { prefetchMargin } from '../paging';
import { MagazineStyleService } from '../../../core/preferences/magazine-style.service';
import {
  entriesAboveFold,
  foldedGroupTailsAbove,
  measureEntries,
  withHiddenDuplicates,
} from './above-fold';
import { ListBlock } from './hidden-overlay';
import { ListContent } from './list-content';
import { PullToRefresh } from './pull-to-refresh';
import { ListScrollState } from './list-scroll-state';
import { ListReadingFocus } from './list-reading-focus';
import { ListProgressRail } from './list-progress-rail';

// How long a reload may run before it earns a spinner. A switch that lands
// sooner would only flash one, which reads as a glitch rather than as progress.
const RELOAD_SPINNER_DELAY_MS = 150;

@Component({
  selector: 'app-entry-list',
  imports: [
    NgTemplateOutlet,
    TranslocoPipe,
    IconComponent,
    ErrorBannerComponent,
    SpinnerComponent,
    LoadingOverlayComponent,
    EntryRowComponent,
    RecommendationStripComponent,
    RunHeaderComponent,
    MagazineBlockComponent,
    ListHeaderComponent,
    ListEmptyStateComponent,
    ToTopButtonComponent,
    ScrollOutsideZoneDirective,
    ProgressRailComponent,
  ],
  templateUrl: './entry-list.component.html',
  styleUrl: './entry-list.component.scss',
})
export class EntryListComponent implements OnDestroy {
  readonly title = input.required<string>();
  /** The search title's lead, quoted term, and count pill, kept as three pieces
   *  (#581 round 2) so the count renders as a pill instead of inline text. Only
   *  meaningful for a search; empty/null otherwise, where `title()` is unsplit. */
  readonly searchTitlePrefix = input<string>('');
  readonly searchTitleTerm = input<string>('');
  /** The pill's text (e.g. `"86"` or `"86+"`), or null to render no pill —
   *  null while the search is still in flight, so the count never flashes a
   *  stale or false number (see `ListHeading.searchCountLabel`). */
  readonly searchCountLabel = input<string | null>(null);
  /** How much this list holds, as a quiet pill beside the name; 0 renders
   *  nothing, matching the sidebar's dropped badge. A search ignores this and
   *  uses `searchCountLabel` instead, which carries its own "+" rule. */
  readonly titleCount = input<TitleCount>({ value: 0, counts: 'items' });
  /** How many saved searches the account keeps. The combined view's empty state
   *  distinguishes "you have none" from "yours match nothing", and only the
   *  shell holds that number. */
  readonly savedSearchCount = input(0);
  /** The tag the heading names, when the list is scoped to one. It carries the
   *  glyph and the colour the sidebar row already shows, so the same tag reads
   *  the same in both places; null for every other selection. */
  readonly titleTag = input<TagDto | null>(null);
  /** The favicon of the feed the heading names, when scoped to one subscription
   *  — mirrors the sidebar row's icon so heading and row read as the same feed.
   *  Null, and unrendered, for every other selection. */
  readonly titleFaviconUrl = input<string | null>(null);
  readonly entries = input.required<EntryDto[]>();
  /** Ids of rows collapsed out of the list (un-favourited/un-kept/marked-unread
   *  in their saved view). A leaving row fades then collapses in place, staying
   *  in `entries` so the magazine plan keeps its shape; a reload clears it. */
  readonly leavingIds = input<ReadonlySet<number>>(new Set());

  readonly loading = input.required<boolean>();
  readonly loadingMore = input.required<boolean>();
  readonly error = input.required<Problem | null>();
  readonly hasMore = input.required<boolean>();
  readonly canMarkAllRead = input.required<boolean>();
  readonly selection = input.required<Selection>();
  readonly openEntryId = input.required<number | null>();
  readonly layout = input<ReadingLayout>('list');
  /** Feed tags keyed by subscription id, used to render each entry's tag pills. */
  readonly feedTags = input<Map<number, SubscriptionTagDto[]>>(new Map());
  /** True while any refresh runs — disables the button and spins its icon. */
  readonly refreshing = input<boolean>(false);
  /** The selected feed's last-fetched time (ISO), or null. Only meaningful for a
   *  single-feed selection; drives the header's "Last refreshed" hint. */
  readonly lastRefreshed = input<string | null>(null);
  /** When the selected feed is next due to be fetched (ISO), or null. Only a
   *  real feed has a next fetch — the for-you list and a gone feed carry none. */
  readonly nextRefresh = input<string | null>(null);
  /** The id of the run whose picks the header already names ("Last refreshed").
   *  For the for-you list only; that one run's boundary divider is suppressed.
   *  Null off the for-you view, where entries carry no run id anyway (#348). */
  readonly newestRunId = input<number | null>(null);
  /** Rendered at the top of whichever content branch is live (empty state,
   *  magazine or list rows) so it scrolls away with the list instead of
   *  occupying a fixed bar above it (#321). Owned by the shell. */
  readonly topBlock = input<TemplateRef<unknown> | null>(null);
  /** Rendered right-aligned in the list header, after the built-in tools — the
   *  shell's per-selection actions (For You run/stop, saved-search Save/Remove),
   *  keeping this generic list unaware of them (#581); same pattern as `topBlock`. */
  readonly headerActions = input<TemplateRef<unknown> | null>(null);
  /** Rendered at the head of the list header's tools, before the built-in
   *  ones. Same arrangement as `headerActions`, at the other end of the row:
   *  what belongs there is the shell's business, where it sits is this list's. */
  readonly leadingActions = input<TemplateRef<unknown> | null>(null);
  /** Rendered before the title, at the very start of the heading row. The shell
   *  puts its "Show sidebar" button here when the sidebar is collapsed; the list
   *  only owns the slot, not what fills it. */
  readonly titleLeading = input<TemplateRef<unknown> | null>(null);
  /** The words the search engine actually matched, from
   *  `EntriesStore.matchedWords`. Empty outside a search, and also empty when
   *  the LIKE fallback (no engine installed) answered instead. */
  readonly matchedWords = input<string[]>([]);

  readonly loadMore = output<void>();
  /** The error banner's retry: replays whichever request failed (the shell wires
   *  it to `EntriesStore.retry`). */
  readonly retry = output<void>();
  /** The error banner's dismiss: clears the banner without a request. */
  readonly dismiss = output<void>();
  readonly markAllRead = output<void>();
  readonly unreadOnlyChange = output<boolean>();
  readonly orderChange = output<ListOrder>();
  /** The above-fold ids captured at click — a snapshot, so scrolling or a
   *  background refresh while the confirm dialog is open cannot change the set. */
  readonly markAboveRead = output<number[]>();
  readonly refresh = output<void>();

  /** The refresh pull gesture is off in the cross-feed saved views. */
  private readonly canRefresh = computed(() => canScopedRefresh(this.selection()));

  /** A direct (unsaved) search is the one selection that keeps its short header
   *  labels and list layout; every other list, saved-search results included,
   *  drops to icon-only actions. */
  readonly directSearch = computed(() => isDirectSearch(this.selection()));

  /** The current search's words, passed to every row for marking. Prefers what
   *  the engine actually matched, since it tolerates typos ("recieve" finds
   *  "receive"); falls back to the typed term's words when the page carries
   *  none (the no-engine LIKE fallback). Empty outside a search either way. */
  readonly searchTerms = computed(() => {
    const matched = this.matchedWords();
    return matched.length > 0 ? matched : searchWords(this.selection().term ?? '');
  });

  readonly effectiveLayout = computed(() => (this.directSearch() ? 'list' : this.layout()));

  /** Search rows dim their excerpt a shade — the marked term stays the row's
   *  focus, and the surrounding prose recedes behind it. */
  readonly isSearch = computed(() => this.selection().kind === 'search');

  private readonly reduceMotion =
    typeof matchMedia !== 'undefined' && matchMedia('(prefers-reduced-motion: reduce)').matches;
  private readonly screen = inject(LayoutService);
  private readonly host = inject(ElementRef<HTMLElement>);
  private readonly magazineStyle = inject(MagazineStyleService);

  private readonly rows = viewChild<ElementRef<HTMLElement>>('rows');
  private readonly sentinel = viewChild<ElementRef<HTMLElement>>('sentinel');
  private readonly listHdr = viewChild<ElementRef<HTMLElement>>('listHdr');
  private readonly header = viewChild(ListHeaderComponent);
  private readonly railRef = viewChild(ProgressRailComponent, { read: ElementRef });
  private readonly scroller = (): HTMLElement | undefined => this.rows()?.nativeElement;

  readonly content = new ListContent({
    entries: this.entries,
    hasMore: this.hasMore,
    selection: this.selection,
    newestRunId: this.newestRunId,
    leavingIds: this.leavingIds,
  });

  /**
   * Whether this list carries the wait cue for its own reload (dim, then veil).
   * A search excludes this: it reloads on every keystroke, and the search field
   * already spins its own icon — dimming there read as a flicker, not progress.
   */
  readonly reloadCue = computed(() => this.loading() && this.selection().kind !== 'search');

  /**
   * Whether the reload overlay is up. A reload keeps outgoing rows on screen
   * (#254); past a delay, this says plainly that new content is coming. Only
   * for a reload — the first load has skeletons, paging has its own footer.
   */
  readonly reloadSpinner = signal(false);
  private readonly _armReloadSpinner = effect((onCleanup) => {
    if (!this.reloadCue() || this.entries().length === 0) {
      this.reloadSpinner.set(false);
      return;
    }
    const timer = setTimeout(() => this.reloadSpinner.set(true), RELOAD_SPINNER_DELAY_MS);
    onCleanup(() => clearTimeout(timer));
  });

  /**
   * The header's EXPANDED height — the space the scroller reserves for it.
   * Only measured while expanded: feeding back the collapsed height would
   * shrink the reservation and reintroduce the jump this replaces (#87).
   */
  readonly headerHeight = signal(0);
  private headerObs?: ResizeObserver;

  /**
   * Published as `--list-bar-h` for the stylesheet to add to the app bar's own
   * reservation — a custom property since four elements need the same sum, and
   * the shell's half (`--app-bar-h`) already arrives this way.
   */
  private readonly _publishBarHeight = effect(() => {
    const headerHeight = this.headerHeight();
    if (headerHeight > 0)
      this.host.nativeElement.style.setProperty('--list-bar-h', `${headerHeight}px`);
  });

  private readonly readingFocus = new ListReadingFocus({
    scroller: this.scroller,
    rendered: this.content.rendered,
    entries: this.entries,
    selection: this.selection,
    reduceMotion: this.reduceMotion,
  });

  // Measures the bar (guarded by `collapsed()`) so the scroller reserves it.
  private readonly _measureHeader = effect(() => {
    const element = this.listHdr()?.nativeElement;
    this.headerObs?.disconnect();
    this.headerObs = undefined;
    if (!element || typeof ResizeObserver === 'undefined') return;
    const observer = new ResizeObserver(() => {
      if (!this.collapsed()) this.headerHeight.set(element.offsetHeight);
    });
    observer.observe(element);
    this.headerObs = observer;
  });

  readonly pull = new PullToRefresh({
    scroller: this.scroller,
    enabled: () =>
      this.canRefresh() && !this.screen.isWide() && !this.reduceMotion && !this.refreshing(),
    refreshing: this.refreshing,
    reduceMotion: this.reduceMotion,
    onTrigger: () => this.refresh.emit(),
  });

  private observer?: IntersectionObserver;
  // Re-observe whenever the sentinel appears/disappears (hasMore toggles it).
  private readonly _wire = effect(() => {
    const node = this.sentinel()?.nativeElement;
    const root = this.rows()?.nativeElement ?? null;
    this.observer?.disconnect();
    if (node && typeof IntersectionObserver !== 'undefined') {
      this.observer = new IntersectionObserver(
        (es) => {
          if (
            es.some((intersection) => intersection.isIntersecting) &&
            this.hasMore() &&
            !this.loadingMore()
          )
            this.loadMore.emit();
        },
        { root, rootMargin: prefetchMargin(root?.clientHeight ?? 0) },
      );
      this.observer.observe(node);
    }
  });

  readonly scrolling = new ListScrollState({
    scroller: this.scroller,
    selection: this.selection,
    loading: this.loading,
    layout: this.layout,
    reduceMotion: this.reduceMotion,
    aboveFold: (scroller) => this.hasEntryAboveFold(scroller),
    onReloaded: () => this.content.clearHidden(),
  });

  private readonly listTotal = computed(() => {
    const count = this.titleCount().value;
    return this.selection().kind === 'search' || count === 0 ? null : count;
  });

  private readonly loadedTotal = computed(() => (this.hasMore() ? null : this.entries().length));

  readonly progressRail = new ListProgressRail({
    scroller: this.scroller,
    rail: () => this.railRef()?.nativeElement,
    rendered: this.content.rendered,
    shownEntries: this.content.renderedVisibleCount,
    complete: this.content.complete,
    total: this.listTotal,
    loadedTotal: this.loadedTotal,
    loading: this.loading,
  });

  readonly railOrientation = computed<ProgressRailOrientation>(() =>
    this.screen.isWide() ? 'horizontal' : 'vertical',
  );

  readonly sideRail = computed(
    () => this.progressRail.overflows() && this.railOrientation() === 'vertical',
  );

  readonly onRowsScroll = (event: Event): void => {
    this.scrolling.onScroll(event);
    this.progressRail.paint();
  };

  /** The list header's collapsed state; the shell's app bar mirrors it (#630). */
  readonly collapsed = this.scrolling.collapsed;

  /** The reveal only makes sense over the real list scroller — the skeleton and
   *  empty states have no content to slide, so a refresh started from those must
   *  not paint the tray over them. */
  readonly revealVisible = computed(
    () => this.pull.revealOffset() > 0 && !this.loading() && this.entries().length > 0,
  );

  /** Gates `.rows.magazine.airy`. Style first: computeds track dynamically, so
   *  a boxed account never takes a dependency on `entries()` at all (#723). */
  protected readonly isAiryMagazine = computed(
    () =>
      this.magazineStyle.style() === 'airy' &&
      this.effectiveLayout() === 'magazine' &&
      !(this.loading() && this.entries().length === 0) &&
      this.content.visibleEntryCount() !== 0,
  );

  /**
   * Jump the list back to the top. Shared by the corner button and by the tap on
   * the empty middle of the app bar.
   */
  scrollToTop(): void {
    if (!this.scrolling.scrollToTop()) return;
    // Land focus on the title, not wherever the button was — an unmounted button
    // drops focus to <body>.
    this.header()?.focusTitle();
  }

  onMarkAboveRead(): void {
    const ids = this.collectAboveFoldIds();
    if (ids.length === 0) return;
    this.markAboveRead.emit(withHiddenDuplicates(ids, this.entries()));
  }

  /** Freeze & remove: hide the just-marked blocks from the render without
   *  re-planning, and land the boundary at the top. entries() is untouched, so
   *  the planner does not re-run and the blocks below keep their positions. */
  hideAboveMarked(ids: number[]): void {
    this.scrolling.cancelSettle();
    this.content.hide(ids);
    this.scrolling.landAtTop();
  }

  tagsFor(subscriptionId: number): SubscriptionTagDto[] {
    return this.feedTags().get(subscriptionId) ?? NO_TAGS;
  }

  blockKey(block: ListBlock): string {
    if (block.kind === 'run-header') return `run-header:${block.generatedAt}`;
    return block.kind === 'group'
      ? `g${block.subscriptionId}:${block.entries[0].id}`
      : `${block.kind}:${block.entry.id}`;
  }

  /** The entry a recommendation strip should read, or null for a group block
   *  (which carries several entries and no single reason to show). */
  strippableEntry(block: MagazineBlock): EntryDto | null {
    return block.kind === 'group' ? null : block.entry;
  }

  /** Whether a single-entry magazine block is animating out of the list. A group
   *  block never leaves as a unit — one of its entries leaving just re-plans the
   *  widget — so it is never marked leaving. */
  isBlockLeaving(block: MagazineBlock): boolean {
    return block.kind !== 'group' && this.leavingIds().has(block.entry.id);
  }

  ngOnDestroy(): void {
    this.observer?.disconnect();
    this.headerObs?.disconnect();
  }

  private foldTop(scroller: HTMLElement): number {
    const header = this.listHdr()?.nativeElement.getBoundingClientRect().bottom ?? 0;
    return Math.max(scroller.getBoundingClientRect().top, header);
  }

  private collectAboveFoldIds(): number[] {
    const scroller = this.rows()?.nativeElement;
    if (!scroller) return [];
    const measured = measureEntries(scroller);
    const above = entriesAboveFold(measured, this.foldTop(scroller));
    const groups = this.content.visibleBlocks().filter((block) => block.kind === 'group');
    const rendered = new Set(measured.map((measurement) => measurement.id));
    return [...above, ...foldedGroupTailsAbove(new Set(above), rendered, groups)];
  }

  private hasEntryAboveFold(scroller: HTMLElement): boolean {
    const first = scroller.querySelector('[data-entry-id]');
    return !!first && first.getBoundingClientRect().bottom <= this.foldTop(scroller);
  }
}
