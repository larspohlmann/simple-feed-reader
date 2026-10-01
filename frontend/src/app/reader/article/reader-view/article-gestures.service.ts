import {
  DestroyRef,
  ElementRef,
  Injectable,
  Signal,
  computed,
  inject,
  signal,
} from '@angular/core';
import {
  AXIS_LOCK_MIN,
  atBottom,
  isBackSwipe,
  overscrollTriggersBack,
  rubberBand,
} from '../../reader-gestures';
import { ArticleScrollRestore } from '../reading/article-scroll-restore.service';
import { prefersReducedMotion } from '../reading/reduced-motion';

/** How far the rubber-banded overscroll pull may travel. */
const MAX_PULL = 160;
/** Slide-out/return animation before the list takes over. */
const LEAVE_ANIM_MS = 220;

/** Players whose own horizontal drag (a scrubber, an embed) the back-swipe must yield to (#1057). */
const MEDIA_CONTROL_SELECTOR = 'audio, video, input[type="range"], .reader-embed';

/** Whether a touch began on a media player's own control rather than the article surface. */
function startsOnMediaControl(target: EventTarget | null): boolean {
  return target instanceof Element && target.closest(MEDIA_CONTROL_SELECTOR) !== null;
}

export interface ArticleGestureHost {
  readonly fullscreen: Signal<boolean>;
  readonly scroller: Signal<HTMLElement | undefined>;
  readonly close: () => void;
}

/** Touch gestures (full-screen only): a rightward swipe or a pull past the end
 *  returns to the list. dragX follows a horizontal swipe; pull follows an
 *  at-the-end overscroll (rubber-banded). `leaving` commits to going back. */
@Injectable()
export class ArticleGestures {
  private readonly layer = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;
  private readonly restore = inject(ArticleScrollRestore);
  private readonly reduceMotion = prefersReducedMotion();
  private host: ArticleGestureHost = {
    fullscreen: signal(false),
    scroller: signal(undefined),
    close: () => undefined,
  };

  private readonly dragX = signal(0);
  private readonly pull = signal(0);
  private readonly snapping = signal(false);
  readonly leaving = signal(false);
  private touchStartX = 0;
  private touchStartY = 0;
  private touchDx = 0;
  private touchDy = 0;
  private axis: 'none' | 'h' | 'v' = 'none';
  private atBottomOnStart = false;
  // A drag that begins on a player's own control (the scrubber, the embed) is
  // that control's to handle; the back-swipe must not steal it (#1057).
  private gestureSuppressed = false;
  private leaveTimer = 0;

  readonly swipeTransform = computed(() =>
    this.dragX() === 0 ? 'none' : `translate3d(${this.dragX()}px, 0, 0)`,
  );
  /** Moves the article alone, not the layer: the spinner the pull reveals sits at its end. */
  readonly pullTransform = computed(() =>
    this.pull() === 0 ? 'none' : `translate3d(0, ${-this.pull()}px, 0)`,
  );
  readonly snapTransition = computed(() =>
    !this.reduceMotion && this.snapping() ? `transform ${LEAVE_ANIM_MS}ms ease-out` : 'none',
  );
  readonly pulling = computed(() => this.pull() > 0);
  readonly pullArmed = computed(() => overscrollTriggersBack(this.pull()));

  constructor() {
    // A swipe may start anywhere on the article layer. touchmove is non-passive so a
    // committed horizontal swipe / at-end pull can preventDefault the scroll.
    const element = this.layer;
    const start = (touchEvent: TouchEvent) => this.onTouchStart(touchEvent);
    const move = (touchEvent: TouchEvent) => this.onTouchMove(touchEvent);
    const end = () => this.onTouchEnd();
    element.addEventListener('touchstart', start, { passive: true });
    element.addEventListener('touchmove', move, { passive: false });
    element.addEventListener('touchend', end);
    element.addEventListener('touchcancel', end);
    inject(DestroyRef).onDestroy(() => {
      element.removeEventListener('touchstart', start);
      element.removeEventListener('touchmove', move);
      element.removeEventListener('touchend', end);
      element.removeEventListener('touchcancel', end);
      if (this.leaveTimer) clearTimeout(this.leaveTimer);
    });
  }

  connect(host: ArticleGestureHost): void {
    this.host = host;
  }

  onTouchStart(touchEvent: TouchEvent): void {
    this.restore.abort(); // the user is taking over; stop restoring
    if (!this.tracking(touchEvent)) return;
    this.gestureSuppressed = startsOnMediaControl(touchEvent.target);
    if (this.gestureSuppressed) return;
    const touch = touchEvent.touches[0];
    this.touchStartX = touch.clientX;
    this.touchStartY = touch.clientY;
    this.touchDx = 0;
    this.touchDy = 0;
    this.axis = 'none';
    const scroller = this.host.scroller();
    this.atBottomOnStart = scroller !== undefined && atBottom(scroller);
    this.snapping.set(false);
  }

  onTouchMove(touchEvent: TouchEvent): void {
    if (this.gestureSuppressed || !this.tracking(touchEvent)) return;
    const touch = touchEvent.touches[0];
    const deltaX = touch.clientX - this.touchStartX;
    const deltaY = touch.clientY - this.touchStartY;
    this.touchDx = deltaX;
    this.touchDy = deltaY;
    if (!this.lockAxis(deltaX, deltaY)) return;
    this.followDrag(deltaX, deltaY, touchEvent);
  }

  onTouchEnd(): void {
    if (this.gestureSuppressed) {
      this.gestureSuppressed = false;
      return;
    }
    if (!this.host.fullscreen() || this.leaving()) return;
    const axis = this.axis;
    this.axis = 'none';
    this.snapping.set(true);
    if (axis === 'h' && isBackSwipe(this.touchDx, this.touchDy)) {
      this.dragX.set(typeof window !== 'undefined' ? window.innerWidth : 999);
      this.pull.set(0);
      this.leave();
    } else if (axis === 'v' && overscrollTriggersBack(this.pull())) {
      this.leave(); // hold the pull spinner while we go back
    } else {
      this.dragX.set(0);
      this.pull.set(0);
    }
  }

  /** The full-screen back button's slide-out: the same as a back-swipe. */
  slideBack(): void {
    if (this.leaving()) return;
    this.snapping.set(true);
    this.pull.set(0);
    this.dragX.set(typeof window !== 'undefined' ? window.innerWidth : 999);
    this.leave();
  }

  /** A single-finger touch on a full-screen article that is not already leaving. */
  private tracking(touchEvent: TouchEvent): boolean {
    return this.host.fullscreen() && !this.leaving() && touchEvent.touches.length === 1;
  }

  /** Decide the drag's axis once it has travelled far enough; false until then. */
  private lockAxis(deltaX: number, deltaY: number): boolean {
    if (this.axis !== 'none') return true;
    if (Math.abs(deltaX) < AXIS_LOCK_MIN && Math.abs(deltaY) < AXIS_LOCK_MIN) return false;
    this.axis = Math.abs(deltaX) > Math.abs(deltaY) ? 'h' : 'v';
    return true;
  }

  private followDrag(deltaX: number, deltaY: number, touchEvent: TouchEvent): void {
    if (this.axis === 'h') {
      const rightward = Math.max(0, deltaX); // rightward-only "back" swipe
      this.dragX.set(rightward);
      if (rightward > 0) touchEvent.preventDefault();
    } else if (this.atBottomOnStart && deltaY < 0) {
      // Pulling up past the article's end.
      this.pull.set(rubberBand(-deltaY, MAX_PULL));
      touchEvent.preventDefault();
    }
  }

  /** Commit to returning to the list once the leave animation has played. */
  private leave(): void {
    this.leaving.set(true);
    this.leaveTimer = window.setTimeout(
      () => this.host.close(),
      this.reduceMotion ? 0 : LEAVE_ANIM_MS,
    );
  }
}
