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
import { LanguageService } from '../../core/language.service';
import { ListScrollMemory } from '../list-scroll-memory';
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

// Article scroll-restore settle: re-assert the target for at most this many frames
// per content render, stopping early once the height has held steady this long.
const ARTICLE_SETTLE_FRAMES = 60;
const ARTICLE_SETTLE_STABLE = 4;

/** How far the reader must scroll before the back-to-top button appears. */
const BACK_TO_TOP_AFTER_PX = 500;

/** Below this many headings an article is too short to warrant a contents list. */
const TOC_MIN_HEADINGS = 3;

/** One heading in the article's table of contents. */
interface TocEntry {
  id: string;
  text: string;
  /** Heading level (2–4) — drives the TOC indentation. */
  level: number;
}

/** A stable, DOM-id-safe slug for a heading's anchor. */
function slugify(text: string): string {
  return (
    text
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '') || 'section'
  );
}

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
  private readonly language = inject(LanguageService);
  private readonly scroll = inject(ListScrollMemory);
  private readonly destroyRef = inject(DestroyRef);

  // Article scroll restore: a browser resume-reload reopens the entry from the URL
  // at the top; re-seat it where the user was reading. `pendingRestore` holds the
  // target until it lands (re-asserted across the original→reader content swap and
  // image loads) or the user scrolls, whichever comes first.
  private pendingRestore: { id: number; top: number } | null = null;
  private restoreRaf = 0;

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

  // Table of contents, built from the rendered article headings. Collapsed by
  // default (tocOpen); only shown once an article has enough headings.
  readonly toc = signal<TocEntry[]>([]);
  readonly showToc = computed(() => this.toc().length >= TOC_MIN_HEADINGS);
  readonly tocOpen = signal(false);

  /** Back-to-top affordance: revealed once the reader has scrolled past a screen. */
  readonly showToTop = signal(false);

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
      this.cancelRestore();
      // A new article starts at the top, with a fresh, collapsed contents list.
      this.toc.set([]);
      this.tocOpen.set(false);
      this.showToTop.set(false);
      if (!e) {
        this.pendingRestore = null;
        this.state.set({ status: 'idle' });
        return;
      }
      // Arm a scroll restore for this entry if we remember a position for it.
      const savedTop = this.scroll.readEntry(e.id);
      this.pendingRestore = savedTop > 0 ? { id: e.id, top: savedTop } : null;
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
        this.buildToc(host);
        this.scheduleFocus();
        // Content just (re-)rendered — re-seat a pending scroll restore. Runs on
        // the original render and again when the reader content swaps in.
        if (this.pendingRestore?.id === this.entry()?.id) this.startRestore();
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
    // A real wheel/touch gesture hands scrolling back to the user, cancelling any
    // in-flight restore so it never fights them.
    const abortRestore = (): void => {
      this.pendingRestore = null;
    };
    el.addEventListener('wheel', abortRestore, { passive: true });
    this.destroyRef.onDestroy(() => {
      el.removeEventListener('touchstart', start);
      el.removeEventListener('touchmove', move);
      el.removeEventListener('touchend', end);
      el.removeEventListener('touchcancel', end);
      el.removeEventListener('wheel', abortRestore);
      this.cancelRestore();
      if (this.leaveTimer) clearTimeout(this.leaveTimer);
    });
  }

  onTouchStart(e: TouchEvent): void {
    this.pendingRestore = null; // the user is taking over; stop restoring
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

  /** Full-screen back button: play the same slide-out-to-the-right as a
   *  back-swipe (rather than cutting straight to the list), then return. */
  slideBack(): void {
    if (this.leaving()) return;
    this.snapping.set(true);
    this.pull.set(0);
    this.dragX.set(typeof window !== 'undefined' ? window.innerWidth : 999);
    this.leave();
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
    const scrollTop = this.host.nativeElement.scrollTop;
    this.showToTop.set(scrollTop > BACK_TO_TOP_AFTER_PX);
    // Remember the reading position so a resume-reload can restore it. Skip while
    // a restore is in flight: the content may still be short and its clamped
    // scrollTop would overwrite the good target.
    const id = this.entry()?.id;
    if (id != null && !this.pendingRestore && !this.leaving()) {
      this.scroll.saveEntry(id, scrollTop);
    }
  }

  /** Jump the reading pane back to the top of the article. */
  scrollToTop(): void {
    this.pendingRestore = null; // don't let a restore fight the jump
    this.host.nativeElement.scrollTo({ top: 0, behavior: this.reduceMotion ? 'auto' : 'smooth' });
  }

  /**
   * Re-assert the pending scroll target across the frames where the article's
   * height is still settling (original→reader swap, images loading), stopping
   * once the height holds steady, the budget is spent, or the user takes over.
   */
  private startRestore(): void {
    this.cancelRestore();
    const p = this.pendingRestore;
    if (!p) return;
    // Rough landing right away so the restore holds even where rAF is throttled
    // (e.g. a backgrounded tab); the loop below then refines it as height settles.
    this.host.nativeElement.scrollTop = p.top;
    if (typeof requestAnimationFrame === 'undefined') return;
    let frames = 0;
    let stable = 0;
    let lastHeight = -1;
    const step = (): void => {
      const p = this.pendingRestore;
      const el = this.host.nativeElement;
      if (!p || p.id !== this.entry()?.id) return; // aborted or entry changed
      el.scrollTop = p.top;
      const height = el.scrollHeight;
      stable = height === lastHeight ? stable + 1 : 0;
      lastHeight = height;
      if (++frames < ARTICLE_SETTLE_FRAMES && stable < ARTICLE_SETTLE_STABLE) {
        this.restoreRaf = requestAnimationFrame(step);
      }
    };
    this.restoreRaf = requestAnimationFrame(step);
  }

  private cancelRestore(): void {
    if (this.restoreRaf && typeof cancelAnimationFrame !== 'undefined') {
      cancelAnimationFrame(this.restoreRaf);
    }
    this.restoreRaf = 0;
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

  /** Extract the article's headings into a contents list, giving each a unique
   *  id to anchor the jump. */
  private buildToc(host: HTMLElement): void {
    const used = new Set<string>();
    const entries: TocEntry[] = [];
    for (const h of Array.from(host.querySelectorAll<HTMLElement>('h2, h3, h4'))) {
      const text = (h.textContent ?? '').trim();
      if (text === '') continue;
      let id = h.id || slugify(text);
      for (let n = 2; used.has(id); n++) id = `${slugify(text)}-${n}`;
      used.add(id);
      h.id = id;
      entries.push({ id, text, level: Number(h.tagName[1]) });
    }
    this.toc.set(entries);
  }

  /** Scroll the reading pane to a heading, clearing the sticky bar (split-pane). */
  scrollToHeading(id: string): void {
    const el = this.content()?.nativeElement.querySelector<HTMLElement>(`#${CSS.escape(id)}`);
    if (!el) return;
    this.pendingRestore = null; // a jump takes over from any in-flight restore
    const host = this.host.nativeElement;
    const offset = this.showToolbar() ? 52 : 8;
    const top = el.getBoundingClientRect().top - host.getBoundingClientRect().top + host.scrollTop;
    host.scrollTo({
      top: Math.max(0, top - offset),
      behavior: this.reduceMotion ? 'auto' : 'smooth',
    });
  }

  toggleMode(): void {
    this.readerMode.toggle();
  }

  when(e: EntryDto): string {
    return relativeTime(e.publishedAt ?? e.createdAt, this.language.lang());
  }
}
