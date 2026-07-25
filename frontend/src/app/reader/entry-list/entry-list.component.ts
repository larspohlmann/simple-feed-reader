// src/app/reader/entry-list/entry-list.component.ts
import {
  Component,
  ElementRef,
  OnDestroy,
  computed,
  effect,
  inject,
  input,
  output,
  signal,
  viewChild,
} from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../shared/icon/icon.component';
import { SpinnerComponent } from '../../shared/spinner/spinner.component';
import {
  BACK_TO_TOP_AFTER_PX,
  ToTopButtonComponent,
} from '../../shared/to-top-button/to-top-button.component';
import { EntryRowComponent } from '../entry-row/entry-row.component';
import { EntryHeroComponent } from '../magazine/entry-hero.component';
import { EntryCompactComponent } from '../magazine/entry-compact.component';
import { SourceGroupComponent } from '../magazine/source-group.component';
import { MagazineBlock, planMagazine } from '../magazine/magazine-planner';
import { ReadingLayout } from '../reading-layout.service';
import { EntryDto, SubscriptionTagDto } from '../models';
import { Selection, canScopedRefresh } from '../query';
import { atTop, pullTriggersRefresh, rubberBand } from '../reader-gestures';
import { relativeTime } from '../format';
import { LanguageService } from '../../core/language.service';
import { Problem } from '../../core/problem';
import { LayoutService } from '../layout.service';
import { ListScrollMemory } from '../list-scroll-memory';
import { nextHeaderHidden } from '../header-scroll';
import { prefetchMargin } from '../paging';

// Scroll-restore settle window: re-assert the target for at most this many frames,
// stopping early once the content height has held steady for this many in a row.
const MAX_SETTLE_FRAMES = 30;
const SETTLE_STABLE_FRAMES = 3;
// Ceiling the rubber-banded pull-to-refresh indicator approaches but never reaches.
const MAX_PULL = 100;

@Component({
  selector: 'app-entry-list',
  imports: [
    RouterLink,
    TranslocoPipe,
    IconComponent,
    SpinnerComponent,
    EntryRowComponent,
    EntryHeroComponent,
    EntryCompactComponent,
    SourceGroupComponent,
    ToTopButtonComponent,
  ],
  templateUrl: './entry-list.component.html',
  styleUrl: './entry-list.component.scss',
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
  readonly layout = input<ReadingLayout>('list');
  /** Feed tags keyed by subscription id, used to render each entry's tag pills. */
  readonly feedTags = input<Map<number, SubscriptionTagDto[]>>(new Map());
  /** True while any refresh runs — disables the button and spins its icon. */
  readonly refreshing = input<boolean>(false);
  /** The selected feed's last-fetched time (ISO), or null. Only meaningful for a
   *  single-feed selection; drives the header's "Last refreshed" hint. */
  readonly lastRefreshed = input<string | null>(null);

  readonly loadMore = output<void>();
  readonly markAllRead = output<void>();
  readonly refresh = output<void>();
  readonly favorite = output<EntryDto>();
  readonly keep = output<EntryDto>();
  readonly read = output<EntryDto>();
  readonly open = output<EntryDto>();

  /** The refresh button + pull gesture are hidden in the cross-feed saved views. */
  readonly canRefresh = computed(() => canScopedRefresh(this.selection()));

  private readonly language = inject(LanguageService);
  /** A localised "last refreshed 5 min ago" label for a single-feed selection,
   *  or null when it doesn't apply (not a feed, or never fetched). Wide-only
   *  visibility is handled in CSS. */
  readonly lastRefreshedLabel = computed(() => {
    const iso = this.lastRefreshed();
    if (this.selection().kind !== 'subscription' || !iso) return null;
    return relativeTime(iso, this.language.lang());
  });

  // Pull-to-refresh (mobile): pulling down past the top of the list scroller
  // rubber-bands an indicator; releasing past the threshold fires a scoped
  // refresh. Disabled on wide screens, in the saved views, and — like the
  // article's motion affordances — under prefers-reduced-motion.
  private readonly reduceMotion =
    typeof matchMedia !== 'undefined' && matchMedia('(prefers-reduced-motion: reduce)').matches;
  readonly pull = signal(0);
  readonly pullArmed = computed(() => pullTriggersRefresh(this.pull()));
  private pullStartY = 0;
  private pullTracking = false;

  readonly blocks = computed<MagazineBlock[]>(() =>
    planMagazine(this.entries(), this.selection().kind !== 'subscription', !this.hasMore()),
  );

  private readonly screen = inject(LayoutService);
  private readonly scroll = inject(ListScrollMemory);
  private readonly host = inject(ElementRef<HTMLElement>);

  constructor() {
    // Capture so we hear the gesture even though scroll events fire on inner .rows;
    // passive so we never block scrolling. Both cancel an in-flight scroll restore.
    const host = this.host.nativeElement;
    host.addEventListener('wheel', this.onUserScrollIntent, { passive: true, capture: true });
    host.addEventListener('touchmove', this.onUserScrollIntent, { passive: true, capture: true });
  }
  // On a narrow layout the list header collapses to a slim tag-name-only bar as
  // you scroll down the list, expanding again on scroll up (same direction logic
  // as the app header's hide-on-scroll). Always expanded on wide screens.
  readonly collapsed = signal(false);
  private lastScrollTop = 0;
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

  onRowsScroll(e: Event): void {
    const el = e.target as HTMLElement | null;
    if (!el || typeof el.scrollTop !== 'number') return;
    const top = el.scrollTop;
    this.collapsed.set(
      nextHeaderHidden(this.collapsed(), this.lastScrollTop, top, this.screen.isWide()),
    );
    this.lastScrollTop = top;
    this.showToTop.set(top > BACK_TO_TOP_AFTER_PX);
    // Remember where the user is so a browser resume-reload (iOS/Brave discard the
    // tab and reload it) can drop them back here rather than at the top.
    this.scroll.save(this.selection(), top);
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

  tagsFor(subscriptionId: number): SubscriptionTagDto[] {
    return this.feedTags().get(subscriptionId) ?? [];
  }

  blockKey(b: MagazineBlock): string {
    return b.kind === 'group'
      ? `group-${b.subscriptionId}-${b.entries[0].id}`
      : `${b.kind}-${b.entry.id}`;
  }
  hero(b: MagazineBlock) {
    return b as Extract<MagazineBlock, { kind: 'hero' }>;
  }
  feat(b: MagazineBlock) {
    return b as Extract<MagazineBlock, { kind: 'feature' }>;
  }
  compact(b: MagazineBlock) {
    return b as Extract<MagazineBlock, { kind: 'compact' }>;
  }
  grp(b: MagazineBlock) {
    return b as Extract<MagazineBlock, { kind: 'group' }>;
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
    // A downward pull that is still anchored at the top rubber-bands the
    // indicator; anything else (upward, or the list has since scrolled) releases
    // it and hands the gesture back to normal scrolling.
    if (dy <= 0 || !atTop(el.scrollTop)) {
      if (this.pull() !== 0) this.pull.set(0);
      return;
    }
    this.pull.set(rubberBand(dy, MAX_PULL));
    e.preventDefault();
  }

  onPullEnd(): void {
    if (!this.pullTracking) return;
    this.pullTracking = false;
    const trigger = pullTriggersRefresh(this.pull());
    this.pull.set(0);
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
      this.applyScroll(el, this.scroll.read(this.selection()));
    }
  });

  private applyScroll(el: HTMLElement, top: number): void {
    this.cancelSettle();
    // Seed the hide-on-scroll baseline so the very next scroll compares against
    // the restored position, not 0.
    if (top <= 0) {
      this.lastScrollTop = el.scrollTop;
      return;
    }
    el.scrollTop = top; // immediate rough landing so the list never flashes at the top
    this.lastScrollTop = el.scrollTop;
    this.settleTo(el, top);
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
    this.observer?.disconnect();
    this.headerObs?.disconnect();
    this.pullCleanup?.();
    this.cancelSettle();
    const host = this.host.nativeElement;
    host.removeEventListener('wheel', this.onUserScrollIntent, { capture: true });
    host.removeEventListener('touchmove', this.onUserScrollIntent, { capture: true });
  }
}
