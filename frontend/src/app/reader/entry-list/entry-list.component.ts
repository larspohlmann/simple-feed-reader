// src/app/reader/entry-list/entry-list.component.ts
import {
  Component,
  DestroyRef,
  ElementRef,
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
import { SpinnerComponent } from '../../shared/spinner/spinner.component';
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
import { ReadingLayout } from '../reading-layout.service';
import { EntryDto, SubscriptionTagDto, TagDto } from '../models';
import {
  Selection,
  canScopedRefresh,
  isSingleStreamView,
  isWholeWordTerm,
  sameSelection,
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

// Scroll-restore settle window: re-assert the target for at most this many frames,
// stopping early once the content height has held steady for this many in a row.
const MAX_SETTLE_FRAMES = 30;
const SETTLE_STABLE_FRAMES = 3;
// Ceiling the rubber-banded pull-to-refresh indicator approaches but never reaches.
const MAX_PULL = 100;
// How far (px) the content slides to reveal the spinner while a refresh runs — the
// held-open offset shared by the mobile pull and the header/sidebar Refresh buttons.
// Matches --space-7; published as --refresh-reveal so the stylesheet sizes the tray
// and its park offset from the same number.
export const REFRESH_REVEAL = 48;

/** A for-you run-boundary divider — a rendering-only block the entry list
 *  interleaves between per-run magazine block groups (#348). Kept out of
 *  MagazineBlock so the planner, which never emits it, stays unaware. */
interface RunHeaderBlock {
  kind: 'run-header';
  generatedAt: string;
}

/** What the magazine branch actually renders: planner blocks plus run dividers. */
type ListBlock = MagazineBlock | RunHeaderBlock;

@Component({
  selector: 'app-entry-list',
  imports: [
    NgTemplateOutlet,
    RouterLink,
    TranslocoPipe,
    IconComponent,
    SpinnerComponent,
    TagGlyphComponent,
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
  ],
  templateUrl: './entry-list.component.html',
  styleUrl: './entry-list.component.scss',
})
export class EntryListComponent implements OnDestroy {
  readonly title = input.required<string>();
  /** The tag the heading names, when the list is scoped to one. It carries the
   *  glyph and the colour the sidebar row already shows, so the same tag reads
   *  the same in both places; null for every other selection. */
  readonly titleTag = input<TagDto | null>(null);
  readonly entries = input.required<EntryDto[]>();
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
   *  magazine rows, list rows) so it scrolls away with the list rather than
   *  occupying a permanently reserved bar above it (#321). Owned by the shell,
   *  which is the only place that knows what belongs there. */
  readonly topBlock = input<TemplateRef<unknown> | null>(null);
  /** Rendered right-aligned in the list header, after the built-in tools. The
   *  shell passes a per-selection action here (the For You run/stop button)
   *  without this generic list knowing what the action is or which selection
   *  it belongs to — the same outlet arrangement as `topBlock`. */
  readonly headerActions = input<TemplateRef<unknown> | null>(null);

  readonly loadMore = output<void>();
  readonly markAllRead = output<void>();
  readonly refresh = output<void>();
  readonly favorite = output<EntryDto>();
  readonly keep = output<EntryDto>();
  readonly read = output<EntryDto>();
  readonly open = output<EntryDto>();
  /** The list scroller's offset, on every scroll. The shell's hide-on-scroll
   *  app bar listens to THIS and nothing else — a typed output instead of a
   *  capture-phase listener that heard every scroller under the shell and had
   *  to guess which mattered (#128). */
  readonly scrolled = output<number>();

  /** The refresh button + pull gesture are hidden in the cross-feed saved views. */
  readonly canRefresh = computed(() => canScopedRefresh(this.selection()));

  /** The current search's words, passed down to every row for marking. Empty
   *  outside a search. A trailing space in the term (the server's whole-word
   *  signal, #408 follow-up) would otherwise split into a trailing empty
   *  string — `.trim()` first so the last real word is the last entry. */
  readonly searchTerms = computed(() => this.selection().term?.trim().split(/\s+/) ?? []);

  /** The search term for the empty-state message — the trailing space is the
   *  server's whole-word-match signal, not part of what the user typed, so it
   *  must not appear in text a human reads (#408 follow-up). */
  readonly displayedSearchTerm = computed(() => visibleSearchTerm(this.selection().term ?? ''));

  /** Whether the current selection is a search whose trailing space put it in
   *  whole-word mode. The badge that surfaces this is the only display of the
   *  mode — without it, `punk` and `punk ` render the identical title while
   *  returning very different result sets, with nothing on screen to explain
   *  why (#408 follow-up). */
  readonly showWholeWordBadge = computed(() => {
    const s = this.selection();
    return s.kind === 'search' && isWholeWordTerm(s.term ?? '');
  });

  /** A search never renders as a magazine — its rows carry marked terms, and a
   *  spread would scatter them across eight block templates. */
  readonly effectiveLayout = computed(() =>
    this.selection().kind === 'search' ? 'list' : this.layout(),
  );

  private readonly language = inject(LanguageService);
  /** A localised "last refreshed 5 min ago" label for a single-feed selection
   *  or the for-you list, or null when it doesn't apply (neither, or never
   *  generated/fetched). */
  readonly lastRefreshedLabel = computed(() => {
    const iso = this.lastRefreshed();
    if (!isSingleStreamView(this.selection()) || !iso) return null;
    return relativeTime(iso, this.language.lang());
  });

  // Pull-to-refresh (mobile): pulling down past the top of the list scroller
  // rubber-bands an indicator; releasing past the threshold fires a scoped
  // refresh. Disabled on wide screens, in the saved views, and — like the
  // article's motion affordances — under prefers-reduced-motion.
  private readonly reduceMotion =
    typeof matchMedia !== 'undefined' && matchMedia('(prefers-reduced-motion: reduce)').matches;
  // `pulled` is the finger's raw travel; `pullArmed` and the trigger check below
  // arm off THIS, never off the rubber-banded revealOffset. Arming off the
  // damped value made the threshold a function of the indicator's ceiling — and
  // against a ceiling of 100 it took ~400px of pull to arm, so the gesture never
  // fired (#105).
  private readonly pulled = signal(0);
  /** True only during an active downward drag. Drives the no-transition class so
   *  the content tracks the finger, and gates the pull branch of revealOffset. */
  readonly dragging = signal(false);
  readonly pullArmed = computed(() => pullTriggersRefresh(this.pulled()));
  /** How far the content and the reveal tray are pushed down, in px. One source
   *  for three states: the finger during a drag, a fixed reveal while a refresh
   *  runs (from ANY trigger — pull, header button, or sidebar button, all of
   *  which set `refreshing()`), and 0 at rest. Suppressed under reduced motion. */
  readonly revealOffset = computed(() => {
    if (this.reduceMotion) return 0;
    if (this.dragging()) return rubberBand(this.pulled(), MAX_PULL);
    return this.refreshing() ? REFRESH_REVEAL : 0;
  });
  /** The transform applied to both the scroller and the tray. Extracted so the
   *  three bindings can't drift apart. */
  readonly revealTransform = computed(() => `translateY(${this.revealOffset()}px)`);

  /** The reveal only makes sense over the real list scroller. The skeleton and
   *  empty states have no content to slide, so a refresh started from those (e.g.
   *  tapping Refresh while the first load still spins, or refreshing an empty
   *  feed) must not paint the tray over them. */
  readonly revealVisible = computed(
    () => this.revealOffset() > 0 && !this.loading() && this.entries().length > 0,
  );
  private pullStartY = 0;
  private pullTracking = false;

  /** The loaded entries split into one group per recommendation run (#348). One
   *  run-less group for every non-for-you view, so those render exactly as before. */
  readonly runGroups = computed<RunGroup[]>(() => groupByRun(this.entries()));

  /** Whether a run group opens with a divider. Suppressed only for the run the
   *  header already names ("Last refreshed") — the newest completed run, matched
   *  by id. Every other run gets its divider, including at the very top when the
   *  newest run left nothing visible. Groups without a run id (every non-for-you
   *  view) never show one. */
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
  private readonly scroll = inject(ListScrollMemory);
  private readonly host = inject(ElementRef<HTMLElement>);
  private readonly catalog = inject(CatalogStore);
  private readonly destroyRef = inject(DestroyRef);

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

    const onResize = () => this.scheduleFocus();
    window.addEventListener('resize', onResize, { passive: true });
    this.destroyRef.onDestroy(() => {
      window.removeEventListener('resize', onResize);
    });
  }
  // On a narrow layout the list header collapses to a slim tag-name-only bar as
  // you scroll down the list, expanding again on scroll up (same direction logic
  // as the app header's hide-on-scroll). Always expanded on wide screens.
  readonly collapsed = signal(false);
  private lastScrollTop = 0;
  private focusRaf = 0;
  /** Drives the corner back-to-top button; set from the scroll handler. */
  readonly showToTop = signal(false);

  /**
   * The header's EXPANDED height, which is the space the scroller reserves for
   * it. Only measured while expanded: feeding the collapsed height back would
   * shrink the reservation and reintroduce exactly the jump this replaces
   * (#87). Rows scroll *under* the bar, so a collapsed bar reveals content
   * rather than leaving a gap.
   */
  readonly headerHeight = signal(0);
  private readonly listHdr = viewChild<ElementRef<HTMLElement>>('listHdr');
  private headerObs?: ResizeObserver;
  /** Focus target for the corner button on activation — see scrollToTop(). */
  private readonly listTitle = viewChild<ElementRef<HTMLElement>>('listTitle');

  /**
   * Published as `--list-bar-h` for the stylesheet to add to the app bar's own
   * reservation. A custom property rather than an inline padding binding
   * because four elements need the same sum, and the shell's half of it
   * (`--app-bar-h`) already arrives this way.
   */
  private readonly _publishBarHeight = effect(() => {
    const h = this.headerHeight();
    if (h > 0) this.host.nativeElement.style.setProperty('--list-bar-h', `${h}px`);
  });

  // A new selection reloads the list from the top, a resize past the wide
  // breakpoint restores the full-size header, and a layout toggle (list <->
  // magazine) swaps in a fresh #rows element that starts at 0 — all three make
  // the collapsed/showToTop state (and its lastScrollTop baseline) stale, so
  // reset them together.
  private readonly _resetCollapse = effect(() => {
    this.selection();
    this.screen.isWide();
    this.layout();
    this.collapsed.set(false);
    this.showToTop.set(false);
    this.lastScrollTop = 0;
  });

  private readonly _rescheduleFocus = effect(() => {
    this.screen.isWide();
    this.scheduleFocus();
  });

  onRowsScroll(e: Event): void {
    const el = e.target as HTMLElement | null;
    if (!el || typeof el.scrollTop !== 'number') return;
    const top = el.scrollTop;
    this.collapsed.set(
      nextHeaderHidden(this.collapsed(), this.lastScrollTop, top, this.screen.isWide()),
    );
    this.lastScrollTop = top;
    this.showToTop.set(top > BACK_TO_TOP_AFTER_PX);
    this.scrolled.emit(top);
    this.scheduleFocus();
    // Remember where the user is so a browser resume-reload (iOS/Brave discard the
    // tab and reload it) can drop them back here rather than at the top.
    if (this.rowsBelongToSelection()) this.scroll.save(this.selection(), top);
  }

  /** Whether the rows on screen are the ones the current selection asked for.
   *  They are not between a view switch and the arrival of that view's page —
   *  the outgoing list stays rendered meanwhile (#254) — and its scroll events
   *  must not be written to the incoming view's key (#267). Null means the list
   *  has never reloaded since mount, where the rows are the current view's. */
  private rowsBelongToSelection(): boolean {
    const rendered = this.renderedSelection;
    return rendered === null || sameSelection(rendered, this.selection());
  }

  /** Coalesce reading-focus recomputes to one per animation frame. */
  private scheduleFocus(): void {
    if (this.reduceMotion || this.focusRaf) return;
    this.focusRaf = requestAnimationFrame(() => {
      this.focusRaf = 0;
      this.applyFocus();
    });
  }

  /** Dim each list entry by its distance from the scroll viewport's centre.
   *  Only active on the narrow (mobile) layout — on wide screens any residual
   *  inline opacities are cleared. */
  private applyFocus(): void {
    const rows = this.rows()?.nativeElement;
    if (!rows) return;
    if (this.screen.isWide()) {
      for (const child of Array.from(rows.children) as HTMLElement[]) {
        child.style.opacity = '';
      }
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
    // Land focus on the title, not just wherever the button happened to be: the
    // button unmounts as soon as showToTop flips false, and an unmounted focused
    // element drops focus to <body>, stranding a keyboard/screen-reader user.
    // preventScroll is required for a different reason here than in the article
    // view: `.list-header` is a `position: absolute` sibling of `.rows`, not a
    // descendant of the scroller, so a default focus() couldn't touch the smooth
    // scroll above anyway — it would instead ask some *outer* ancestor to scroll
    // the (already fully visible) heading into view, which is equally unwanted.
    this.listTitle()?.nativeElement.focus({ preventScroll: true });
    // Say the bar is expanded now rather than waiting for a scroll event: this
    // way the tap expands it immediately instead of ~300ms later once the
    // animation lands, and a scroll gesture that interrupts the animation
    // partway (wheel/touch — see cancelSettle above) may never reach 0 at all.
    this.collapsed.set(false);
    // `lastScrollTop` deliberately keeps its pre-jump value. Zeroing it would
    // make the smooth scroll's own first event (still far down the list) read
    // as a large scroll *down* and immediately re-collapse the bar.
    // `showToTop` is likewise left to the scroll events: clearing it here would
    // only make the button blink out and back in as the animation passes the
    // threshold, which is how the article view behaves too.
    // Best-effort restore point in case a reload lands before the animation
    // finishes: `onRowsScroll` overwrites this on every frame of the smooth
    // scroll, so it's a floor for the reduced-motion/interrupted cases, not a
    // guarantee that 0 is what actually gets remembered.
    this.scroll.save(this.selection(), 0);
  }

  /** The list scroller's current offset. The shell derives the drawer-close
   *  header state from the list itself rather than from whichever scroller
   *  under it happened to fire last (#128). */
  currentScrollTop(): number {
    return this.rows()?.nativeElement.scrollTop ?? 0;
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

  side(block: MagazineBlock): 'left' | 'right' {
    return block.kind === 'split' ? block.imageSide : 'right';
  }

  grp(block: MagazineBlock): Extract<MagazineBlock, { kind: 'group' }> {
    return block as Extract<MagazineBlock, { kind: 'group' }>;
  }

  private readonly rows = viewChild<ElementRef<HTMLElement>>('rows');
  private readonly sentinel = viewChild<ElementRef<HTMLElement>>('sentinel');
  private observer?: IntersectionObserver;

  // (Re)attach the pull-to-refresh touch listeners whenever the scroll container
  // appears or is swapped (list <-> magazine, load <-> empty). touchmove is
  // non-passive so a committed pull can preventDefault the native overscroll.
  // Measure the bar so the scroller can reserve its height. Guarded by
  // `collapsed()` so only the expanded size is ever recorded.
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

  // Restore the remembered scroll offset when a fresh load finishes. Gated on the
  // loading edge (true -> false) so it fires once per genuine reload/selection —
  // never on "load more" (which toggles loadingMore, not loading) and never on
  // opening/closing an article (the list stays mounted beneath the overlay, so no
  // remount and no reload). That gating is what keeps the return-from-article
  // position exactly native, avoiding the earlier restore-glitch.
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

  // A view switch leaves the previous view's list (and its scroll offset) on
  // screen until the new page lands. Hand the scroller the incoming view's own
  // place right away, so the wait shows that view's window rather than the one
  // the user left. The load-complete restore above then repeats it exactly.
  private readonly _scrollOnSelectionChange = effect(() => {
    const selection = this.selection();
    untracked(() => {
      const el = this.rows()?.nativeElement;
      if (el) this.applyScroll(el, this.scroll.read(selection));
    });
  });

  private applyScroll(el: HTMLElement, top: number): void {
    this.cancelSettle();
    // Assign even for 0. The scroller outlives a view switch (the outgoing list
    // stays rendered, #254), so "this view has no remembered offset" has to put
    // it back at the top rather than leave the previous view's offset in place
    // (#267).
    el.scrollTop = top; // immediate rough landing so the list never flashes at the top
    // Seed the hide-on-scroll baseline so the very next scroll compares against
    // the restored position, not 0.
    this.lastScrollTop = el.scrollTop;
    // Only a target below the fold can be nudged off by late layout; the top is
    // where scroll-anchoring holds content anyway, so it needs no settle window.
    if (top > 0) this.settleTo(el, top);
  }

  // A resume-reload re-renders the whole list from scratch, and block heights firm
  // up over the next few frames (fonts, images, magazine planning). A single early
  // scrollTop set gets nudged off by the browser's scroll-anchoring as that happens,
  // so re-assert the target each frame until the content height stops changing —
  // then the final landing is exact. Aborts the moment the user scrolls (see the
  // wheel/touch listeners) so it never fights a real gesture.
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
