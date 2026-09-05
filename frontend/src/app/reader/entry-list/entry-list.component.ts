import {
  Component,
  DestroyRef,
  ElementRef,
  NgZone,
  OnDestroy,
  TemplateRef,
  computed,
  effect,
  inject,
  input,
  output,
  signal,
  untracked,
  viewChild,
} from '@angular/core';
import { NgTemplateOutlet } from '@angular/common';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../shared/icon/icon.component';
import { ListActionDirective } from '../../shared/list-action/list-action.directive';
import { SpinnerComponent } from '../../shared/spinner/spinner.component';
import { FaviconComponent } from '../../shared/favicon/favicon.component';
import { LoadingOverlayComponent } from '../../shared/loading-overlay/loading-overlay.component';
import { TagGlyphComponent } from '../../shared/tag-glyph/tag-glyph.component';
import {
  BACK_TO_TOP_AFTER_PX,
  ToTopButtonComponent,
} from '../../shared/to-top-button/to-top-button.component';
import { EntryRowComponent } from '../entry-row/entry-row.component';
import { RecommendationStripComponent } from '../recommendation-strip/recommendation-strip.component';
import { RunHeaderComponent } from '../run-header/run-header.component';
import { groupByRun, RunGroup } from '../for-you-runs';
import { EntryHeroComponent } from '../magazine/entry-hero.component';
import { EntryCompactComponent } from '../magazine/entry-compact.component';
import { SourceGroupComponent } from '../magazine/source-group.component';
import { focusOpacityForSpan } from '../reading-focus';
import { EntrySplitComponent } from '../magazine/entry-split.component';
import { EntryWideComponent } from '../magazine/entry-wide.component';
import { EntryThumbComponent } from '../magazine/entry-thumb.component';
import { EntryQuoteComponent } from '../magazine/entry-quote.component';
import { EntryKickerComponent } from '../magazine/entry-kicker.component';
import { MagazineBlock } from '../magazine/magazine-block';
import { planMagazine } from '../magazine/magazine-planner';
import { ScrollOutsideZoneDirective } from '../scroll-outside-zone.directive';
import { ReadingLayout } from '../reading-layout.service';
import { EntryDto, SubscriptionTagDto, TagDto } from '../models';
import {
  Selection,
  canScopedRefresh,
  hasUnreadFilter,
  isDirectSearch,
  isSingleStreamView,
  isWholeWordTerm,
  isPhraseTerm,
  sameSelection,
  searchWords,
  visibleSearchTerm,
} from '../query';
import { atTop, pullTriggersRefresh, rubberBand } from '../reader-gestures';
import { relativeTime } from '../format';
import { LanguageService } from '../../core/language.service';
import { Problem } from '../../core/problem';
import { LayoutService } from '../layout.service';
import { CatalogStore } from '../../discover/catalog.store';
import { ListScrollMemory } from '../list-scroll-memory';
import { nextHeaderHidden } from '../header-scroll';
import { prefetchMargin } from '../paging';
import { ReadingFocusService } from '../../core/reading-focus.service';
import { MagazineStyleService } from '../../core/magazine-style.service';

// Scroll-restore settle window: re-assert the target for at most this many frames,
// stopping early once the content height has held steady for this many in a row.
const MAX_SETTLE_FRAMES = 30;
const SETTLE_STABLE_FRAMES = 3;
// Ceiling the rubber-banded pull-to-refresh indicator approaches but never reaches.
const MAX_PULL = 100;
// How far (px) content slides to reveal the spinner during any refresh trigger
// (pull, header/sidebar buttons). Matches --space-7; published as --refresh-reveal
// so the stylesheet sizes the tray and its park offset from the same number.
export const REFRESH_REVEAL = 48;
// How long a reload may run before it earns a spinner. A switch that lands
// sooner would only flash one, which reads as a glitch rather than as progress.
const RELOAD_SPINNER_DELAY_MS = 150;

/** The heading icon for each fixed view, matching its sidebar row's glyph so the
 *  list a reader lands in reads as the row they clicked (#411). Tag and
 *  subscription are absent — their heading already carries a glyph/favicon. */
const FIXED_VIEW_ICON: Partial<Record<Selection['kind'], string>> = {
  all: 'inbox',
  favorites: 'star',
  kept: 'bookmark',
  viewed: 'history',
  'for-you': 'auto_awesome',
  'saved-searches': 'saved_search',
  search: 'search',
};

/** A for-you run-boundary divider — a rendering-only block the entry list
 *  interleaves between per-run magazine block groups (#348). Kept out of
 *  MagazineBlock so the planner, which never emits it, stays unaware. */
interface RunHeaderBlock {
  kind: 'run-header';
  generatedAt: string;
}

/** What the magazine branch actually renders: planner blocks plus run dividers. */
type ListBlock = MagazineBlock | RunHeaderBlock;

/** How much the list holds, and what that number counts — travel together since
 *  the pill needs the value and the heading's accessible name needs what it
 *  counts. The shell resolves both once, for tab title and heading (#709). */
export interface TitleCount {
  readonly value: number;
  readonly counts: 'unread' | 'items';
}

@Component({
  selector: 'app-entry-list',
  imports: [
    NgTemplateOutlet,
    RouterLink,
    TranslocoPipe,
    IconComponent,
    ListActionDirective,
    SpinnerComponent,
    LoadingOverlayComponent,
    TagGlyphComponent,
    FaviconComponent,
    EntryRowComponent,
    RecommendationStripComponent,
    RunHeaderComponent,
    EntryHeroComponent,
    EntryCompactComponent,
    SourceGroupComponent,
    EntrySplitComponent,
    EntryWideComponent,
    EntryThumbComponent,
    EntryQuoteComponent,
    EntryKickerComponent,
    ToTopButtonComponent,
    ScrollOutsideZoneDirective,
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
   *  stale or false number (see `ReaderShellComponent.searchCountLabel`). */
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

  /** Rows the user can still see — loaded set minus the collapsed ones. The
   *  empty state keys on this, not `entries().length`, so collapsing the last
   *  row shows "nothing here" immediately, though it lingers until reload. */
  readonly visibleEntryCount = computed(
    () => this.entries().filter((e) => !this.leavingIds().has(e.id)).length,
  );
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
  /** The words the search engine actually matched, from
   *  `EntriesStore.matchedWords`. Empty outside a search, and also empty when
   *  the LIKE fallback (no engine installed) answered instead. */
  readonly matchedWords = input<string[]>([]);

  readonly loadMore = output<void>();
  readonly markAllRead = output<void>();
  readonly refresh = output<void>();
  readonly favorite = output<EntryDto>();
  readonly keep = output<EntryDto>();
  readonly read = output<EntryDto>();
  readonly open = output<EntryDto>();

  /** The refresh button + pull gesture are hidden in the cross-feed saved views. */
  readonly canRefresh = computed(() => canScopedRefresh(this.selection()));

  /** Whether this list offers the All posts / only unread switch. The rule is
   *  the selection vocabulary's, not this header's — the shell asks the same
   *  question when it builds the list query. */
  readonly hasUnreadFilter = computed(() => hasUnreadFilter(this.selection()));

  /** The number the heading shows, or 0 for the two cases that show none: a
   *  list with nothing in it, and a search — whose heading already carries its
   *  own result count, with its own rules about when it may be shown. */
  readonly headingCount = computed(() =>
    this.selection().kind === 'search' ? 0 : this.titleCount().value,
  );

  /** The current search's words, passed to every row for marking. Prefers what
   *  the engine actually matched, since it tolerates typos ("recieve" finds
   *  "receive"); falls back to the typed term's words when the page carries
   *  none (the no-engine LIKE fallback). Empty outside a search either way. */
  readonly searchTerms = computed(() => {
    const matched = this.matchedWords();
    return matched.length > 0 ? matched : searchWords(this.selection().term ?? '');
  });

  /** The search term for the empty-state message — the trailing space is the
   *  server's whole-word-match signal, not part of what the user typed, so it
   *  must not appear in text a human reads (#408 follow-up). */
  readonly displayedSearchTerm = computed(() => visibleSearchTerm(this.selection().term ?? ''));

  /** Whether the selection is a search whose trailing space puts it in
   *  whole-word mode. The badge is the only display of this — `punk` and
   *  `punk ` otherwise render identical titles for very different results (#408). */
  readonly showWholeWordBadge = computed(() => {
    const s = this.selection();
    const term = s.term ?? '';
    // A phrase overrides whole-word when both signals are present (#702), so a
    // phrase query shows only the phrase pill, never both.
    return s.kind === 'search' && isWholeWordTerm(term) && !isPhraseTerm(term);
  });

  /** Whether the selection is a phrase search (quoted query). The pill is the
   *  only sign the words matched as one exact run rather than each anywhere —
   *  mirrors the whole-word badge (#702). */
  readonly showPhraseBadge = computed(() => {
    const s = this.selection();
    return s.kind === 'search' && isPhraseTerm(s.term ?? '');
  });

  /** The heading's leading icon for a fixed view, or null for a tag or a
   *  subscription (their glyph and favicon already lead the heading) (#411). */
  readonly titleIcon = computed(() => FIXED_VIEW_ICON[this.selection().kind] ?? null);

  readonly effectiveLayout = computed(() =>
    isDirectSearch(this.selection()) ? 'list' : this.layout(),
  );

  /** Search rows dim their excerpt a shade — the marked term stays the row's
   *  focus, and the surrounding prose recedes behind it. */
  readonly isSearch = computed(() => this.selection().kind === 'search');

  private readonly language = inject(LanguageService);
  /** A localised "last refreshed 5 min ago" label for a single-feed selection
   *  or the for-you list, or null when it doesn't apply (neither, or never
   *  generated/fetched). */
  readonly lastRefreshedLabel = computed(() => {
    const iso = this.lastRefreshed();
    if (!isSingleStreamView(this.selection()) || !iso) return null;
    return relativeTime(iso, this.language.lang());
  });

  // Pull-to-refresh (mobile): pulling past the top rubber-bands an indicator;
  // releasing past the threshold fires a scoped refresh. Disabled on wide
  // screens, saved views, and under prefers-reduced-motion.
  private readonly reduceMotion =
    typeof matchMedia !== 'undefined' && matchMedia('(prefers-reduced-motion: reduce)').matches;
  // `pulled` is the finger's raw travel; `pullArmed` arms off THIS, never off the
  // rubber-banded revealOffset — arming off the damped value made the threshold
  // depend on the indicator's ceiling, so pull never reached it (#105).
  private readonly pulled = signal(0);
  /** True only during an active downward drag. Drives the no-transition class so
   *  the content tracks the finger, and gates the pull branch of revealOffset. */
  readonly dragging = signal(false);
  readonly pullArmed = computed(() => pullTriggersRefresh(this.pulled()));
  /** How far content and the reveal tray push down, in px — one source for
   *  three states: drag offset, a fixed reveal while any trigger sets
   *  `refreshing()`, and 0 at rest. Suppressed under reduced motion. */
  readonly revealOffset = computed(() => {
    if (this.reduceMotion) return 0;
    if (this.dragging()) return rubberBand(this.pulled(), MAX_PULL);
    return this.refreshing() ? REFRESH_REVEAL : 0;
  });
  /** The transform applied to both the scroller and the tray. Extracted so the
   *  three bindings can't drift apart. */
  readonly revealTransform = computed(() => `translateY(${this.revealOffset()}px)`);

  /** The reveal only makes sense over the real list scroller — the skeleton and
   *  empty states have no content to slide, so a refresh started from those must
   *  not paint the tray over them. */
  readonly revealVisible = computed(
    () => this.revealOffset() > 0 && !this.loading() && this.entries().length > 0,
  );
  private pullStartY = 0;
  private pullTracking = false;

  /** The loaded entries split into one group per recommendation run (#348). One
   *  run-less group for every non-for-you view, so those render exactly as before. */
  readonly runGroups = computed<RunGroup[]>(() => groupByRun(this.entries()));

  /** Whether a run group opens with a divider. Suppressed only for the run the
   *  header already names ("Last refreshed"), matched by id; every other run
   *  gets one, even at the top. No run id (non-for-you view) means never. */
  showRunHeader(group: RunGroup): boolean {
    return group.runId != null && group.runId !== this.newestRunId();
  }

  readonly blocks = computed<ListBlock[]>(() => {
    const groups = this.runGroups();
    // Only aggregated views collapse same-source runs into a group widget; a
    // single-stream view (a feed, or the for-you list) must not.
    const grouping = !isSingleStreamView(this.selection());
    const complete = !this.hasMore();

    // Fast path: no dividers (every non-for-you view, and a for-you list showing
    // only the newest run). Plan the whole list at once — identical to before.
    if (!groups.some((group) => this.showRunHeader(group))) {
      return planMagazine({ entries: this.entries(), grouping, complete });
    }

    const out: ListBlock[] = [];
    groups.forEach((group, index) => {
      if (this.showRunHeader(group)) {
        out.push({ kind: 'run-header', generatedAt: group.generatedAt! });
      }
      // Only the last loaded group may still grow on the next page; every earlier
      // group is provably complete (a different run follows it).
      const groupComplete = index === groups.length - 1 ? complete : true;
      out.push(...planMagazine({ entries: group.entries, grouping, complete: groupComplete }));
    });
    return out;
  });

  private readonly screen = inject(LayoutService);
  private readonly readingFocus = inject(ReadingFocusService);
  private readonly zone = inject(NgZone);
  private readonly scroll = inject(ListScrollMemory);
  private readonly host = inject(ElementRef<HTMLElement>);
  private readonly catalog = inject(CatalogStore);
  private readonly destroyRef = inject(DestroyRef);
  private readonly magazineStyle = inject(MagazineStyleService);

  /** Gates `.rows.magazine.airy`. Style first: computeds track dynamically, so
   *  a boxed account never takes a dependency on `entries()` at all (#723). */
  protected readonly isAiryMagazine = computed(
    () =>
      this.magazineStyle.style() === 'airy' &&
      this.effectiveLayout() === 'magazine' &&
      !(this.loading() && this.entries().length === 0) &&
      this.visibleEntryCount() !== 0,
  );

  /** True only once the catalog has been resolved AND has no entries.
   *  Unresolved reads as not-empty, so the /discover link is never hidden on a
   *  guess — it simply shows until the shell (which loads the catalog on the
   *  onboarding path) proves the catalog empty. */
  readonly catalogEmpty = computed(() => this.catalog.resolved() && !this.catalog.hasEntries());

  constructor() {
    // Capture so we hear the gesture even though scroll events fire on inner .rows;
    // passive so we never block scrolling. Both cancel an in-flight scroll restore.
    const host = this.host.nativeElement;
    host.addEventListener('wheel', this.onUserScrollIntent, { passive: true, capture: true });
    host.addEventListener('touchmove', this.onUserScrollIntent, { passive: true, capture: true });
    host.style.setProperty('--refresh-reveal', `${REFRESH_REVEAL}px`);

    const onResize = (): void => this.scheduleFocus();
    this.zone.runOutsideAngular(() =>
      window.addEventListener('resize', onResize, { passive: true }),
    );
    this.destroyRef.onDestroy(() => {
      window.removeEventListener('resize', onResize);
    });
  }
  // On narrow layouts the list header collapses to a slim bar on scroll-down,
  // expanding on scroll-up (always expanded on wide screens). The shell's app
  // bar mirrors this same signal; reset by `_resetCollapse` on selection change,
  // which is why switching lists returns the app bar to the top (#630).
  readonly collapsed = signal(false);
  private lastScrollTop = 0;
  private focusRaf = 0;

  /** Drives the corner back-to-top button; set from the scroll handler. */
  readonly showToTop = signal(false);

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
  private readonly listHdr = viewChild<ElementRef<HTMLElement>>('listHdr');
  private headerObs?: ResizeObserver;
  /** Focus target for the corner button on activation — see scrollToTop(). */
  private readonly listTitle = viewChild<ElementRef<HTMLElement>>('listTitle');

  /**
   * Published as `--list-bar-h` for the stylesheet to add to the app bar's own
   * reservation — a custom property since four elements need the same sum, and
   * the shell's half (`--app-bar-h`) already arrives this way.
   */
  private readonly _publishBarHeight = effect(() => {
    const h = this.headerHeight();
    if (h > 0) this.host.nativeElement.style.setProperty('--list-bar-h', `${h}px`);
  });

  // A new selection, a resize past the wide breakpoint, or a list<->magazine
  // layout toggle each make the collapsed/showToTop state (and lastScrollTop)
  // stale, so reset them together.
  private readonly _resetCollapse = effect(() => {
    this.selection();
    this.screen.isWide();
    this.layout();
    this.collapsed.set(false);
    this.showToTop.set(false);
    this.lastScrollTop = 0;
  });

  /**
   * The one subscriber that keeps the reading focus fresh. It reads every source
   * that can change which rows sit under the reading centre and recomputes once,
   * coalesced to a frame — the pass writes an inline opacity per row, so a row it
   * has never measured renders undimmed until something runs it.
   *
   * The sources, gathered here rather than wired up piecemeal so a new one is a
   * single line and never a place to forget (each miss was its own bug):
   *  - `readingFocus.enabled()` — the local setting that starts or clears the pass.
   *  - `screen.isWide()` — the breakpoint the fade is gated on.
   *  - `entries()` — a finished load or a load-more append.
   *  - `rows()` — the scroller element itself being replaced (skeleton -> list,
   *     list <-> magazine).
   *  - `selection()` — a view switch, whose retained outgoing list (#254) can
   *     otherwise keep its stale fade until the new page lands (#462).
   *  - `magazineStyle.style()` — boxed <-> airy only toggles a class on the same
   *     `#rows` element (so `rows()` does not fire), yet airy resizes every row;
   *     without this the fade stays computed against the old geometry.
   *  Imperative events (scroll, resize, row collapse) call `scheduleFocus()` directly.
   */
  private readonly _readingFocus = effect(() => {
    if (!this.readingFocus.enabled()) {
      if (this.focusRaf) cancelAnimationFrame(this.focusRaf);
      this.focusRaf = 0;
      this.clearFocus();
      return;
    }
    this.screen.isWide();
    this.entries();
    this.rows();
    this.selection();
    this.magazineStyle.style();
    this.scheduleFocus();
  });

  readonly onRowsScroll = (e: Event): void => {
    const el = e.target as HTMLElement | null;
    if (!el || typeof el.scrollTop !== 'number') return;
    const top = el.scrollTop;
    this.collapsed.set(
      nextHeaderHidden(this.collapsed(), this.lastScrollTop, top, this.screen.isWide()),
    );
    this.lastScrollTop = top;
    this.showToTop.set(top > BACK_TO_TOP_AFTER_PX);
    this.scheduleFocus();
    // Remember where the user is so a browser resume-reload (iOS/Brave discard the
    // tab and reload it) can drop them back here rather than at the top.
    if (this.rowsBelongToSelection()) this.scroll.save(this.selection(), top);
  };

  /** Whether the rows on screen match the current selection — false between a
   *  view switch and the new page's arrival, since the outgoing list stays
   *  rendered (#254) and must not write scroll to the incoming key (#267). */
  private rowsBelongToSelection(): boolean {
    const rendered = this.renderedSelection;
    return rendered === null || sameSelection(rendered, this.selection());
  }

  /** The reading-focus recompute, coalesced to one pass per animation frame. */
  private scheduleFocus(): void {
    if (this.reduceMotion || !this.readingFocus.enabled() || this.focusRaf) return;
    // Outside the zone: the pass writes inline styles and no signal, so its frame
    // must not end in a tick over every loaded block (#501).
    this.focusRaf = this.zone.runOutsideAngular(() =>
      requestAnimationFrame(() => {
        this.focusRaf = 0;
        this.applyFocus();
      }),
    );
  }

  /** A CSS animation finished inside the scroller. Only a saved-view row
   *  collapsing (#478) matters — it moves rows without firing a scroll, so this
   *  re-triggers dimming. `includes`: encapsulation puts the marker mid-string. */
  onContentSettled(event: AnimationEvent): void {
    if (event.animationName.includes('row-leave')) this.scheduleFocus();
  }

  /** Dim each list entry by its distance from the scroll viewport's centre.
   *  Only active on the narrow (mobile) layout — on wide screens any residual
   *  inline opacities are cleared. */
  private applyFocus(): void {
    const rows = this.rows()?.nativeElement;
    if (!rows) return;
    if (!this.readingFocus.enabled() || this.screen.isWide()) {
      this.clearFocus();
      return;
    }
    const viewport = rows.clientHeight;
    const rowsTop = rows.getBoundingClientRect().top;
    for (const child of Array.from(rows.children) as HTMLElement[]) {
      if (child.classList.contains('foot')) continue;
      const rect = child.getBoundingClientRect();
      const top = rect.top - rowsTop;
      // Fade by the row's span, not its centre: a source group taller than the
      // viewport must stay bright while it fills the screen, not dim to the
      // minimum because its off-screen centre is a viewport away (#213).
      child.style.opacity = String(focusOpacityForSpan(top, top + rect.height, viewport));
    }
  }

  private clearFocus(): void {
    const rows = this.rows()?.nativeElement;
    if (!rows) return;
    for (const child of Array.from(rows.children) as HTMLElement[]) child.style.opacity = '';
  }

  /**
   * Jump the list back to the top. Shared by the corner button and by the tap on
   * the empty middle of the app bar.
   */
  scrollToTop(): void {
    const el = this.rows()?.nativeElement;
    if (!el) return;
    // A scroll restore in flight re-asserts its own target every frame; the
    // user's jump has to win.
    this.cancelSettle();
    el.scrollTo({ top: 0, behavior: this.reduceMotion ? 'auto' : 'smooth' });
    // Land focus on the title, not wherever the button was — an unmounted button
    // drops focus to <body>. preventScroll avoids an outer-ancestor scroll, since
    // `.list-header` sits outside `.rows` and default focus() would trigger one.
    this.listTitle()?.nativeElement.focus({ preventScroll: true });
    // Say the bar is expanded now rather than waiting for a scroll event: the
    // tap expands it immediately instead of ~300ms later, and an interrupted
    // scroll gesture (wheel/touch — see cancelSettle) may never reach 0 at all.
    this.collapsed.set(false);
    // `lastScrollTop` deliberately keeps its pre-jump value — zeroing it would
    // read the smooth scroll's first event as a large scroll down and re-collapse
    // the bar. `showToTop` is likewise left to the scroll events (matches article).
    // Best-effort restore point in case a reload lands before the animation
    // finishes: `onRowsScroll` overwrites this every frame, so it's a floor for
    // the reduced-motion/interrupted cases, not a guarantee 0 gets remembered.
    this.scroll.save(this.selection(), 0);
  }

  tagsFor(subscriptionId: number): SubscriptionTagDto[] {
    return this.feedTags().get(subscriptionId) ?? [];
  }

  blockKey(block: ListBlock): string {
    if (block.kind === 'run-header') return `run-header:${block.generatedAt}`;
    return block.kind === 'group'
      ? `g${block.subscriptionId}:${block.entries[0].id}`
      : `${block.kind}:${block.entry.id}`;
  }

  /** Narrow a block to its entry-carrying form for the template. */
  entryOf(block: MagazineBlock): EntryDto {
    return (block as Extract<MagazineBlock, { entry: EntryDto }>).entry;
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

  side(block: MagazineBlock): 'left' | 'right' {
    return block.kind === 'split' ? block.imageSide : 'right';
  }

  grp(block: MagazineBlock): Extract<MagazineBlock, { kind: 'group' }> {
    return block as Extract<MagazineBlock, { kind: 'group' }>;
  }

  private readonly rows = viewChild<ElementRef<HTMLElement>>('rows');
  private readonly sentinel = viewChild<ElementRef<HTMLElement>>('sentinel');
  private observer?: IntersectionObserver;

  // (Re)attach pull-to-refresh listeners when the scroll container appears or
  // swaps; touchmove is non-passive so a pull can preventDefault the overscroll.
  // Also measures the bar (guarded by `collapsed()`) so the scroller reserves it.
  private readonly _measureHeader = effect(() => {
    const el = this.listHdr()?.nativeElement;
    this.headerObs?.disconnect();
    this.headerObs = undefined;
    if (!el || typeof ResizeObserver === 'undefined') return;
    const obs = new ResizeObserver(() => {
      if (!this.collapsed()) this.headerHeight.set(el.offsetHeight);
    });
    obs.observe(el);
    this.headerObs = obs;
  });

  private pullCleanup?: () => void;
  private readonly _wirePull = effect(() => {
    const el = this.rows()?.nativeElement;
    this.pullCleanup?.();
    this.pullCleanup = undefined;
    if (!el) return;
    const start = (e: TouchEvent): void => this.onPullStart(e, el);
    const move = (e: TouchEvent): void => this.onPullMove(e, el);
    const end = (): void => this.onPullEnd();
    el.addEventListener('touchstart', start, { passive: true });
    el.addEventListener('touchmove', move, { passive: false });
    el.addEventListener('touchend', end);
    el.addEventListener('touchcancel', end);
    this.pullCleanup = () => {
      el.removeEventListener('touchstart', start);
      el.removeEventListener('touchmove', move);
      el.removeEventListener('touchend', end);
      el.removeEventListener('touchcancel', end);
    };
  });

  private pullEnabled(): boolean {
    return this.canRefresh() && !this.screen.isWide() && !this.reduceMotion && !this.refreshing();
  }

  onPullStart(e: TouchEvent, el: HTMLElement): void {
    // Only arm a pull that begins at the very top with a single finger.
    this.pullTracking = this.pullEnabled() && e.touches.length === 1 && atTop(el.scrollTop);
    if (this.pullTracking) this.pullStartY = e.touches[0].clientY;
  }

  onPullMove(e: TouchEvent, el: HTMLElement): void {
    if (!this.pullTracking || e.touches.length !== 1) return;
    const dy = e.touches[0].clientY - this.pullStartY;
    // A downward pull that is still anchored at the top rubber-bands the content;
    // anything else (upward, or the list has since scrolled) releases it and hands
    // the gesture back to normal scrolling.
    if (dy <= 0 || !atTop(el.scrollTop)) {
      if (this.dragging()) this.dragging.set(false);
      if (this.pulled() !== 0) this.pulled.set(0);
      return;
    }
    this.pulled.set(dy);
    this.dragging.set(true);
    e.preventDefault();
  }

  onPullEnd(): void {
    if (!this.pullTracking) return;
    this.pullTracking = false;
    const trigger = pullTriggersRefresh(this.pulled());
    // Drop the drag: revealOffset now follows refreshing(). On an armed release the
    // emit below flips refreshing() true synchronously (RefreshService.run sets
    // running immediately, and the shell binds it as a plain signal), so the offset
    // hands straight off from the pull value to REFRESH_REVEAL with no 0-frame.
    this.dragging.set(false);
    this.pulled.set(0);
    if (trigger) this.refresh.emit();
  }

  // Re-observe whenever the sentinel appears/disappears (hasMore toggles it).
  private readonly _wire = effect(() => {
    const node = this.sentinel()?.nativeElement;
    const root = this.rows()?.nativeElement ?? null;
    this.observer?.disconnect();
    if (node && typeof IntersectionObserver !== 'undefined') {
      this.observer = new IntersectionObserver(
        (es) => {
          if (es.some((e) => e.isIntersecting) && this.hasMore() && !this.loadingMore())
            this.loadMore.emit();
        },
        { root, rootMargin: prefetchMargin(root?.clientHeight ?? 0) },
      );
      this.observer.observe(node);
    }
  });

  // Restore the remembered scroll offset when a fresh load finishes, gated on the
  // loading edge (true -> false) so it fires once per genuine reload/selection —
  // never "load more" or an article open/close (list stays mounted, no remount).
  private wasLoading = false;
  private readonly _restoreScroll = effect(() => {
    const loading = this.loading();
    const el = this.rows()?.nativeElement;
    if (loading) {
      this.wasLoading = true;
      return;
    }
    // Wait for the scroll container to render (it only exists once entries show),
    // then land the user back where they were before the page was reloaded.
    if (this.wasLoading && el) {
      this.wasLoading = false;
      this.renderedSelection = this.selection();
      this.applyScroll(el, this.scroll.read(this.selection()));
    }
  });

  /** The selection whose entries are on screen — see rowsBelongToSelection(). */
  private renderedSelection: Selection | null = null;

  // A view switch leaves the previous view's list on screen until the new page
  // lands. Hand the scroller the incoming view's place right away, so the wait
  // shows that view's window, not the one left. The restore above repeats it.
  private readonly _scrollOnSelectionChange = effect(() => {
    const selection = this.selection();
    untracked(() => {
      const el = this.rows()?.nativeElement;
      if (el) this.applyScroll(el, this.scroll.read(selection));
    });
  });

  private applyScroll(el: HTMLElement, top: number): void {
    this.cancelSettle();
    // Assign even for 0 — the scroller outlives a view switch (outgoing list
    // stays rendered, #254), so "no remembered offset" must put it back at the
    // top rather than leave the previous view's offset in place (#267).
    el.scrollTop = top; // immediate rough landing so the list never flashes at the top
    // Seed the hide-on-scroll baseline so the very next scroll compares against
    // the restored position, not 0.
    this.lastScrollTop = el.scrollTop;
    // Only a target below the fold can be nudged off by late layout; the top is
    // where scroll-anchoring holds content anyway, so it needs no settle window.
    if (top > 0) this.settleTo(el, top);
  }

  // A resume-reload re-renders the list from scratch; block heights firm up over
  // the next frames, nudging off a single early scrollTop via scroll-anchoring.
  // Re-assert each frame until heights stabilize; aborts on a real user scroll.
  private settleRaf = 0;
  private settleAbort = false;
  private settleTo(el: HTMLElement, target: number): void {
    if (typeof requestAnimationFrame === 'undefined') return;
    this.settleAbort = false;
    let frames = 0;
    let stableFrames = 0;
    let lastHeight = -1;
    const step = (): void => {
      if (this.settleAbort) return;
      el.scrollTop = target;
      this.lastScrollTop = el.scrollTop;
      const height = el.scrollHeight;
      stableFrames = height === lastHeight ? stableFrames + 1 : 0;
      lastHeight = height;
      if (++frames < MAX_SETTLE_FRAMES && stableFrames < SETTLE_STABLE_FRAMES) {
        this.settleRaf = requestAnimationFrame(step);
      }
    };
    this.settleRaf = requestAnimationFrame(step);
  }

  private cancelSettle(): void {
    this.settleAbort = true;
    if (this.settleRaf && typeof cancelAnimationFrame !== 'undefined') {
      cancelAnimationFrame(this.settleRaf);
    }
    this.settleRaf = 0;
  }

  /** A real scroll gesture during the settle window wins over the restore. */
  private readonly onUserScrollIntent = (): void => this.cancelSettle();

  ngOnDestroy(): void {
    if (this.focusRaf && typeof cancelAnimationFrame !== 'undefined') {
      cancelAnimationFrame(this.focusRaf);
    }
    this.observer?.disconnect();
    this.headerObs?.disconnect();
    this.pullCleanup?.();
    this.cancelSettle();
    const host = this.host.nativeElement;
    host.removeEventListener('wheel', this.onUserScrollIntent, { capture: true });
    host.removeEventListener('touchmove', this.onUserScrollIntent, { capture: true });
  }
}
