import {
  DestroyRef,
  ElementRef,
  NgZone,
  Signal,
  effect,
  inject,
  signal,
  untracked,
} from '@angular/core';
import { BACK_TO_TOP_AFTER_PX } from '../../../shared/to-top-button/to-top-button.component';
import { LayoutService } from '../../layout.service';
import { ListScrollMemory } from '../../scroll/list-scroll-memory';
import { nextHeaderHidden } from '../../scroll/header-scroll';
import { ReadingLayout } from '../../reading-layout.service';
import { Selection, sameSelection } from '../../query/query';

// Scroll-restore settle window: re-assert the target for at most this many frames,
// stopping early once the content height has held steady for this many in a row.
const MAX_SETTLE_FRAMES = 30;
const SETTLE_STABLE_FRAMES = 3;

export interface ListScrollStateOptions {
  readonly scroller: () => HTMLElement | undefined;
  readonly selection: Signal<Selection>;
  readonly loading: Signal<boolean>;
  readonly layout: Signal<ReadingLayout>;
  readonly reduceMotion: boolean;
  /** Whether an entry is fully scrolled past the fold of this scroller. */
  readonly aboveFold: (scroller: HTMLElement) => boolean;
  /** A fresh load finished and its rows are on screen. */
  readonly onReloaded: () => void;
}

/** The list scroller's state: the collapsing header, the corner buttons, the
 *  remembered offset and its restore. Built in a field initializer, so its
 *  effects are created there. */
export class ListScrollState {
  private readonly screen = inject(LayoutService);
  private readonly scroll = inject(ListScrollMemory);
  private readonly zone = inject(NgZone);

  // On narrow layouts the list header collapses to a slim bar on scroll-down,
  // expanding on scroll-up (always expanded on wide screens). The shell's app
  // bar mirrors this same signal; reset by `_resetCollapse` on selection change,
  // which is why switching lists returns the app bar to the top (#630).
  readonly collapsed = signal(false);
  private lastScrollTop = 0;

  /** Drives the corner back-to-top button; set from the scroll handler. */
  readonly showToTop = signal(false);

  /** Whether at least one entry is fully scrolled past the fold — gates the
   *  lower-left button so it never offers a no-op. Set cheaply from the scroll
   *  handler; the click-time collection does the real, full-list work. */
  readonly hasAboveFold = signal(false);

  // A new selection, a resize past the wide breakpoint, or a list<->magazine
  // layout toggle each make the collapsed/showToTop state (and lastScrollTop)
  // stale, so reset them together.
  private readonly _resetCollapse = effect(() => {
    this.options.selection();
    this.screen.isWide();
    this.options.layout();
    this.collapsed.set(false);
    this.showToTop.set(false);
    this.hasAboveFold.set(false);
    this.lastScrollTop = 0;
  });

  /** The selection whose entries are on screen — see rowsBelongToSelection(). */
  private renderedSelection: Selection | null = null;

  // Restore the remembered scroll offset when a fresh load finishes, gated on the
  // loading edge (true -> false) so it fires once per genuine reload/selection —
  // never "load more" or an article open/close (list stays mounted, no remount).
  private wasLoading = false;
  private readonly _restoreScroll = effect(() => {
    const loading = this.options.loading();
    const element = this.options.scroller();
    if (loading) {
      this.wasLoading = true;
      return;
    }
    // Wait for the scroll container to render (it only exists once entries show),
    // then land the user back where they were before the page was reloaded.
    if (this.wasLoading && element) {
      this.wasLoading = false;
      this.options.onReloaded();
      this.renderedSelection = this.options.selection();
      this.applyScroll(element, this.scroll.read(this.options.selection()));
    }
  });

  // A view switch leaves the previous view's list on screen until the new page
  // lands. Hand the scroller the incoming view's place right away, so the wait
  // shows that view's window, not the one left. The restore above repeats it.
  private readonly _scrollOnSelectionChange = effect(() => {
    const selection = this.options.selection();
    untracked(() => {
      const element = this.options.scroller();
      if (element) this.applyScroll(element, this.scroll.read(selection));
    });
  });

  // A resume-reload re-renders the list from scratch; block heights firm up over
  // the next frames, nudging off a single early scrollTop via scroll-anchoring.
  // Re-assert each frame until heights stabilize; aborts on a real user scroll.
  private settleRaf = 0;
  private settleAbort = false;

  constructor(private readonly options: ListScrollStateOptions) {
    // Capture so we hear the gesture even though scroll events fire on inner .rows;
    // passive so we never block scrolling. Both cancel an in-flight scroll restore.
    const host: HTMLElement = inject(ElementRef).nativeElement;
    host.addEventListener('wheel', this.onUserScrollIntent, { passive: true, capture: true });
    host.addEventListener('touchmove', this.onUserScrollIntent, { passive: true, capture: true });
    inject(DestroyRef).onDestroy(() => {
      this.cancelSettle();
      host.removeEventListener('wheel', this.onUserScrollIntent, { capture: true });
      host.removeEventListener('touchmove', this.onUserScrollIntent, { capture: true });
    });
  }

  readonly onScroll = (scrollEvent: Event): void => {
    const element = scrollEvent.target as HTMLElement | null;
    if (!element || typeof element.scrollTop !== 'number') return;
    const top = element.scrollTop;
    this.collapsed.set(
      nextHeaderHidden({
        previousHidden: this.collapsed(),
        lastTop: this.lastScrollTop,
        top,
        isWide: this.screen.isWide(),
      }),
    );
    this.lastScrollTop = top;
    const pastTop = top > BACK_TO_TOP_AFTER_PX;
    this.showToTop.set(pastTop);
    const scroller = this.options.scroller();
    this.hasAboveFold.set(pastTop && !!scroller && this.options.aboveFold(scroller));
    // Remember where the user is so a browser resume-reload (iOS/Brave discard the
    // tab and reload it) can drop them back here rather than at the top.
    if (this.rowsBelongToSelection()) this.scroll.save(this.options.selection(), top);
  };

  /**
   * Jump the list back to the top; false when there is no scroller. Shared by
   * the corner button and by the tap on the empty middle of the app bar.
   */
  scrollToTop(): boolean {
    const element = this.options.scroller();
    if (!element) return false;
    // A scroll restore in flight re-asserts its own target every frame; the
    // user's jump has to win.
    this.cancelSettle();
    element.scrollTo({ top: 0, behavior: this.options.reduceMotion ? 'auto' : 'smooth' });
    // Say the bar is expanded now rather than waiting for a scroll event: the
    // tap expands it immediately instead of ~300ms later, and an interrupted
    // scroll gesture (wheel/touch — see cancelSettle) may never reach 0 at all.
    this.collapsed.set(false);
    // `lastScrollTop` deliberately keeps its pre-jump value — zeroing it would
    // read the smooth scroll's first event as a large scroll down and re-collapse
    // the bar. `showToTop` is likewise left to the scroll events (matches article).
    // Best-effort restore point in case a reload lands before the animation
    // finishes: `onScroll` overwrites this every frame, so it's a floor for
    // the reduced-motion/interrupted cases, not a guarantee 0 gets remembered.
    this.scroll.save(this.options.selection(), 0);
    return true;
  }

  /** Land at the top at once, with the bar expanded and the corner buttons gone. */
  landAtTop(): void {
    this.collapsed.set(false);
    this.showToTop.set(false);
    this.hasAboveFold.set(false);
    const element = this.options.scroller();
    if (!element) return;
    this.scroll.save(this.options.selection(), 0);
    this.zone.runOutsideAngular(() =>
      requestAnimationFrame(() => {
        element.scrollTop = 0;
        this.lastScrollTop = 0;
      }),
    );
  }

  applyScroll(element: HTMLElement, top: number): void {
    this.cancelSettle();
    // Assign even for 0 — the scroller outlives a view switch (outgoing list
    // stays rendered, #254), so "no remembered offset" must put it back at the
    // top rather than leave the previous view's offset in place (#267).
    element.scrollTop = top; // immediate rough landing so the list never flashes at the top
    // Seed the hide-on-scroll baseline so the very next scroll compares against
    // the restored position, not 0.
    this.lastScrollTop = element.scrollTop;
    // Only a target below the fold can be nudged off by late layout; the top is
    // where scroll-anchoring holds content anyway, so it needs no settle window.
    if (top > 0) this.settleTo(element, top);
  }

  cancelSettle(): void {
    this.settleAbort = true;
    if (this.settleRaf && typeof cancelAnimationFrame !== 'undefined') {
      cancelAnimationFrame(this.settleRaf);
    }
    this.settleRaf = 0;
  }

  /** Whether the rows on screen match the current selection — false between a
   *  view switch and the new page's arrival, since the outgoing list stays
   *  rendered (#254) and must not write scroll to the incoming key (#267). */
  private rowsBelongToSelection(): boolean {
    const rendered = this.renderedSelection;
    return rendered === null || sameSelection(rendered, this.options.selection());
  }

  private settleTo(element: HTMLElement, target: number): void {
    if (typeof requestAnimationFrame === 'undefined') return;
    this.settleAbort = false;
    let frames = 0;
    let stableFrames = 0;
    let lastHeight = -1;
    const step = (): void => {
      if (this.settleAbort) return;
      element.scrollTop = target;
      this.lastScrollTop = element.scrollTop;
      const height = element.scrollHeight;
      stableFrames = height === lastHeight ? stableFrames + 1 : 0;
      lastHeight = height;
      if (++frames < MAX_SETTLE_FRAMES && stableFrames < SETTLE_STABLE_FRAMES) {
        this.settleRaf = requestAnimationFrame(step);
      }
    };
    this.settleRaf = requestAnimationFrame(step);
  }

  /** A real scroll gesture during the settle window wins over the restore. */
  private readonly onUserScrollIntent = (): void => this.cancelSettle();
}
