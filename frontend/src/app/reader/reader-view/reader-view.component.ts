// src/app/reader/reader-view/reader-view.component.ts
import {
  Component,
  DestroyRef,
  ElementRef,
  HostListener,
  computed,
  effect,
  inject,
  input,
  output,
  signal,
  viewChild,
} from '@angular/core';
import { Subscription, timeout } from 'rxjs';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../shared/icon/icon.component';
import { FaviconComponent } from '../../shared/favicon/favicon.component';
import { SpinnerComponent } from '../../shared/spinner/spinner.component';
import { SourceTagsComponent } from '../source-tags/source-tags.component';
import { EntryDto, ReaderArticle, SubscriptionTagDto } from '../models';
import { ReaderContentService } from '../reader-content.service';
import { ReaderModeService } from '../reader-mode.service';
import { focusOpacity, readingBlocks } from '../reading-focus';
import {
  AXIS_LOCK_MIN,
  atBottom,
  isBackSwipe,
  overscrollTriggersBack,
  rubberBand,
} from '../reader-gestures';
import { relativeTime } from '../format';

/** Give up on a hung extraction and fall back to feed content (backend caps a
 *  fetch at ~20s; this is the client-side backstop for a stalled connection). */
const READER_LOAD_TIMEOUT_MS = 30_000;
/** How far the rubber-banded overscroll pull may travel. */
const MAX_PULL = 160;
/** Slide-out/return animation before the list takes over. */
const LEAVE_ANIM_MS = 220;

@Component({
  selector: 'app-reader-view',
  imports: [IconComponent, FaviconComponent, SpinnerComponent, SourceTagsComponent, TranslocoPipe],
  templateUrl: './reader-view.component.html',
  styleUrl: './reader-view.component.scss',
})
export class ReaderViewComponent {
  readonly entry = input.required<EntryDto | null>();
  readonly tags = input<SubscriptionTagDto[]>([]);
  readonly hasPrev = input(false);
  readonly hasNext = input(false);
  /** Whether to render the in-article toolbar. False in full-screen reading,
   *  where the top bar hosts the back button, reader switch and prev/next;
   *  true in split-pane mode, where the reader has no shared top bar. */
  readonly showToolbar = input(true);

  readonly favorite = output<void>();
  readonly keep = output<void>();
  readonly read = output<void>();
  readonly prev = output<void>();
  readonly next = output<void>();
  // Semantic "back to list" output; not a DOM element's close event.
  // eslint-disable-next-line @angular-eslint/no-output-native
  readonly close = output<void>();

  private readonly content = viewChild<ElementRef<HTMLElement>>('content');
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly reader = inject(ReaderContentService);
  protected readonly readerMode = inject(ReaderModeService);
  private readonly destroyRef = inject(DestroyRef);

  // Reading-focus effect: the paragraph nearest the reading centre stays fully
  // opaque while the rest dims, refreshed on scroll. Skipped entirely when the
  // reader prefers reduced motion.
  private readonly reduceMotion =
    typeof matchMedia !== 'undefined' && matchMedia('(prefers-reduced-motion: reduce)').matches;
  private focusRaf = 0;

  // Touch gestures (full-screen only): a rightward swipe or a pull past the end
  // returns to the list. dragX follows a horizontal swipe; pull follows an
  // at-the-end overscroll (rubber-banded). `leaving` commits to going back.
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
  private leaveTimer = 0;

  protected readonly readerTransform = computed(
    () => `translate3d(${this.dragX()}px, ${-this.pull()}px, 0)`,
  );
  protected readonly readerTransition = computed(() =>
    !this.reduceMotion && this.snapping() ? `transform ${LEAVE_ANIM_MS}ms ease-out` : 'none',
  );
  protected readonly pulling = computed(() => this.pull() > 0);
  protected readonly pullArmed = computed(() => overscrollTriggersBack(this.pull()));

  // The open entry's object reference changes on every optimistic flag update
  // (favorite/keep/read), but its id does not. Tracking the loaded id lets the
  // load effect ignore those churns: no redundant re-fetch, and the Reader/
  // Original toggle survives an in-reader action instead of snapping back.
  private loadedId: number | null = null;
  private loadSub: Subscription | null = null;

  // Alias the shared mode signal so the template and computeds read it directly;
  // writes go through the ReaderModeService lifecycle methods below.
  readonly mode = this.readerMode.mode;
  private readonly state = signal<
    { status: 'idle' | 'loading' } | { status: 'ok'; article: ReaderArticle } | { status: 'failed' }
  >({ status: 'idle' });

  readonly loading = computed(() => this.state().status === 'loading');
  readonly failed = computed(() => this.state().status === 'failed');
  private readonly article = computed(() => {
    const s = this.state();
    return s.status === 'ok' ? s.article : null;
  });
  // A hero image for articles whose extracted body has none (only in reader mode;
  // the original-content view keeps its own inline images).
  readonly leadImage = computed(() =>
    this.mode() === 'reader' ? (this.article()?.leadImage ?? null) : null,
  );

  readonly displayHtml = computed(() => {
    const e = this.entry();
    if (!e) return '';
    const a = this.article();
    // Original mode falls back through summary: many feeds populate only one of
    // contentHtml/summary, so preferring contentHtml then summary avoids a blank
    // pane under the "showing the feed's summary" note.
    return this.mode() === 'reader' && a ? a.contentHtml : (e.contentHtml ?? e.summary ?? '');
  });

  constructor() {
    effect(() => {
      const e = this.entry();
      const id = e?.id ?? null;
      // Only react to a genuine entry change — not to a same-entry reference
      // churn from an optimistic flag update (which must not cancel an in-flight
      // load, re-fetch, or reset the mode toggle).
      if (id === this.loadedId) return;
      this.loadedId = id;
      this.loadSub?.unsubscribe();
      this.readerMode.reset();
      if (!e) {
        this.state.set({ status: 'idle' });
        return;
      }
      this.state.set({ status: 'loading' });
      this.loadSub = this.reader
        .load(e.id)
        .pipe(timeout({ first: READER_LOAD_TIMEOUT_MS }))
        .subscribe({
          next: (c) => {
            if (c.status === 'ok') {
              this.state.set({ status: 'ok', article: c });
              this.readerMode.enableToggle();
            } else {
              this.state.set({ status: 'failed' });
              this.readerMode.setOriginalOnly();
            }
          },
          error: () => {
            this.state.set({ status: 'failed' });
            this.readerMode.setOriginalOnly();
          },
        });
    });
    this.destroyRef.onDestroy(() => this.loadSub?.unsubscribe());

    // Re-decorate external links and re-seat the reading focus whenever the
    // rendered HTML changes (new article, or Reader/Original toggle).
    effect(() => {
      this.displayHtml();
      queueMicrotask(() => {
        const host = this.content()?.nativeElement;
        if (!host) return;
        for (const a of Array.from(host.querySelectorAll('a'))) {
          // Leave in-page fragment anchors alone; only external links open in a new tab.
          if ((a.getAttribute('href') ?? '').startsWith('#')) continue;
          if (a.target !== '_blank') {
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
          }
        }
        this.scheduleFocus();
      });
    });

    if (!this.reduceMotion) {
      const onResize = () => this.scheduleFocus();
      window.addEventListener('resize', onResize, { passive: true });
      this.destroyRef.onDestroy(() => {
        window.removeEventListener('resize', onResize);
        if (this.focusRaf) cancelAnimationFrame(this.focusRaf);
      });
    }

    // Touch listeners live on the scroll host. touchmove is non-passive so a
    // committed horizontal swipe / at-end pull can preventDefault the scroll.
    const el = this.host.nativeElement;
    const start = (e: TouchEvent) => this.onTouchStart(e);
    const move = (e: TouchEvent) => this.onTouchMove(e);
    const end = () => this.onTouchEnd();
    el.addEventListener('touchstart', start, { passive: true });
    el.addEventListener('touchmove', move, { passive: false });
    el.addEventListener('touchend', end);
    el.addEventListener('touchcancel', end);
    this.destroyRef.onDestroy(() => {
      el.removeEventListener('touchstart', start);
      el.removeEventListener('touchmove', move);
      el.removeEventListener('touchend', end);
      el.removeEventListener('touchcancel', end);
      if (this.leaveTimer) clearTimeout(this.leaveTimer);
    });
  }

  onTouchStart(e: TouchEvent): void {
    if (this.showToolbar() || this.leaving() || e.touches.length !== 1) return;
    const t = e.touches[0];
    this.touchStartX = t.clientX;
    this.touchStartY = t.clientY;
    this.touchDx = 0;
    this.touchDy = 0;
    this.axis = 'none';
    const el = this.host.nativeElement;
    this.atBottomOnStart = atBottom(el.scrollTop, el.clientHeight, el.scrollHeight);
    this.snapping.set(false);
  }

  onTouchMove(e: TouchEvent): void {
    if (this.showToolbar() || this.leaving() || e.touches.length !== 1) return;
    const t = e.touches[0];
    const dx = t.clientX - this.touchStartX;
    const dy = t.clientY - this.touchStartY;
    this.touchDx = dx;
    this.touchDy = dy;
    if (this.axis === 'none') {
      if (Math.abs(dx) < AXIS_LOCK_MIN && Math.abs(dy) < AXIS_LOCK_MIN) return;
      this.axis = Math.abs(dx) > Math.abs(dy) ? 'h' : 'v';
    }
    if (this.axis === 'h') {
      const x = Math.max(0, dx); // rightward-only "back" swipe
      this.dragX.set(x);
      if (x > 0) e.preventDefault();
    } else if (this.atBottomOnStart && dy < 0) {
      // Pulling up past the article's end.
      this.pull.set(rubberBand(-dy, MAX_PULL));
      e.preventDefault();
    }
  }

  onTouchEnd(): void {
    if (this.showToolbar() || this.leaving()) return;
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

  /** Commit to returning to the list once the leave animation has played. */
  private leave(): void {
    this.leaving.set(true);
    this.leaveTimer = window.setTimeout(
      () => this.close.emit(),
      this.reduceMotion ? 0 : LEAVE_ANIM_MS,
    );
  }

  @HostListener('scroll')
  protected onScroll(): void {
    this.scheduleFocus();
  }

  /** Coalesce focus recomputes to one per animation frame. */
  private scheduleFocus(): void {
    if (this.reduceMotion || this.focusRaf) return;
    this.focusRaf = requestAnimationFrame(() => {
      this.focusRaf = 0;
      this.applyFocus();
    });
  }

  /** Dim each article block by its distance from the scroll viewport's centre. */
  private applyFocus(): void {
    const content = this.content()?.nativeElement;
    if (!content) return;
    const scroller = this.host.nativeElement;
    const viewport = scroller.clientHeight;
    const hostTop = scroller.getBoundingClientRect().top;
    for (const block of readingBlocks(content)) {
      const rect = block.getBoundingClientRect();
      const center = rect.top - hostTop + rect.height / 2;
      block.style.opacity = String(focusOpacity(center, viewport));
    }
  }

  toggleMode(): void {
    this.readerMode.toggle();
  }

  when(e: EntryDto): string {
    return relativeTime(e.publishedAt ?? e.createdAt);
  }
}
