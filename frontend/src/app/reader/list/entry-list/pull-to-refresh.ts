import { DestroyRef, ElementRef, Signal, computed, effect, inject, signal } from '@angular/core';
import { atTop, pullTriggersRefresh, rubberBand } from '../../reader-gestures';

// Ceiling the rubber-banded pull-to-refresh indicator approaches but never reaches.
const MAX_PULL = 100;
// How far (px) content slides to reveal the spinner during any refresh trigger
// (pull, header/sidebar buttons). Matches --space-7; published as --refresh-reveal
// so the stylesheet sizes the tray and its park offset from the same number.
export const REFRESH_REVEAL = 48;

export interface PullToRefreshOptions {
  readonly scroller: () => HTMLElement | undefined;
  readonly enabled: () => boolean;
  readonly refreshing: Signal<boolean>;
  readonly reduceMotion: boolean;
  readonly onTrigger: () => void;
}

/** Pull-to-refresh (mobile): pulling past the top rubber-bands an indicator;
 *  releasing past the threshold fires a scoped refresh. Built in a field
 *  initializer, so its effect is created there. */
export class PullToRefresh {
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
    if (this.options.reduceMotion) return 0;
    if (this.dragging()) return rubberBand(this.pulled(), MAX_PULL);
    return this.options.refreshing() ? REFRESH_REVEAL : 0;
  });
  /** The transform applied to both the scroller and the tray. Extracted so the
   *  three bindings can't drift apart. `none` at rest, never `translateY(0px)`:
   *  any transform promotes the whole (very tall, far down) scroll content to
   *  one GPU layer, whose backing store iOS WebKit can fail to paint for a
   *  frame mid-scroll (#501). */
  readonly revealTransform = computed(() => {
    const offset = this.revealOffset();
    return offset === 0 ? 'none' : `translateY(${offset}px)`;
  });

  private pullStartY = 0;
  private pullTracking = false;
  private pullCleanup?: () => void;

  // (Re)attach the listeners when the scroll container appears or swaps;
  // touchmove is non-passive so a pull can preventDefault the overscroll.
  private readonly _wirePull = effect(() => {
    const element = this.options.scroller();
    this.pullCleanup?.();
    this.pullCleanup = undefined;
    if (!element) return;
    const start = (touchEvent: TouchEvent): void => this.onPullStart(touchEvent, element);
    const move = (touchEvent: TouchEvent): void => this.onPullMove(touchEvent, element);
    const end = (): void => this.onPullEnd();
    element.addEventListener('touchstart', start, { passive: true });
    element.addEventListener('touchmove', move, { passive: false });
    element.addEventListener('touchend', end);
    element.addEventListener('touchcancel', end);
    this.pullCleanup = () => {
      element.removeEventListener('touchstart', start);
      element.removeEventListener('touchmove', move);
      element.removeEventListener('touchend', end);
      element.removeEventListener('touchcancel', end);
    };
  });

  constructor(private readonly options: PullToRefreshOptions) {
    const host: HTMLElement = inject(ElementRef).nativeElement;
    host.style.setProperty('--refresh-reveal', `${REFRESH_REVEAL}px`);
    inject(DestroyRef).onDestroy(() => this.pullCleanup?.());
  }

  private onPullStart(touchEvent: TouchEvent, element: HTMLElement): void {
    // Only arm a pull that begins at the very top with a single finger.
    this.pullTracking =
      this.options.enabled() && touchEvent.touches.length === 1 && atTop(element.scrollTop);
    if (this.pullTracking) this.pullStartY = touchEvent.touches[0].clientY;
  }

  private onPullMove(touchEvent: TouchEvent, element: HTMLElement): void {
    if (!this.pullTracking || touchEvent.touches.length !== 1) return;
    const dy = touchEvent.touches[0].clientY - this.pullStartY;
    // A downward pull that is still anchored at the top rubber-bands the content;
    // anything else (upward, or the list has since scrolled) releases it and hands
    // the gesture back to normal scrolling.
    if (dy <= 0 || !atTop(element.scrollTop)) {
      if (this.dragging()) this.dragging.set(false);
      if (this.pulled() !== 0) this.pulled.set(0);
      return;
    }
    this.pulled.set(dy);
    this.dragging.set(true);
    touchEvent.preventDefault();
  }

  private onPullEnd(): void {
    if (!this.pullTracking) return;
    this.pullTracking = false;
    const trigger = pullTriggersRefresh(this.pulled());
    // Drop the drag: revealOffset now follows refreshing(). On an armed release the
    // trigger flips refreshing() true synchronously (RefreshService.run sets
    // running immediately, and the shell binds it as a plain signal), so the offset
    // hands straight off from the pull value to REFRESH_REVEAL with no 0-frame.
    this.dragging.set(false);
    this.pulled.set(0);
    if (trigger) this.options.onTrigger();
  }
}
