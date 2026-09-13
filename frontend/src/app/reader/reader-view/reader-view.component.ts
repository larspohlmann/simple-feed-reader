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
import { Observable, Subscription, timeout } from 'rxjs';
import { RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { IconComponent } from '../../shared/icon/icon.component';
import { ListActionDirective } from '../../shared/list-action/list-action.directive';
import { FaviconComponent } from '../../shared/favicon/favicon.component';
import { SpinnerComponent } from '../../shared/spinner/spinner.component';
import { LoadingOverlayComponent } from '../../shared/loading-overlay/loading-overlay.component';
import {
  BACK_TO_TOP_AFTER_PX,
  ToTopButtonComponent,
} from '../../shared/to-top-button/to-top-button.component';
import { SourceTagsComponent } from '../source-tags/source-tags.component';
import { PaywallNoticeComponent } from '../paywall-notice/paywall-notice.component';
import { WarningBoxComponent } from '../../shared/warning-box/warning-box.component';
import {
  EntryDto,
  ReaderArticle,
  ReaderContent,
  ReaderFailure,
  SubscriptionTagDto,
} from '../models';
import { ReaderContentService } from '../reader-content.service';
import { describeLoadError } from '../reader-load-error';
import { ReaderModeService } from '../reader-mode.service';
import { LanguageService } from '../../core/language.service';
import { LayoutService } from '../layout.service';
import { ListScrollMemory } from '../list-scroll-memory';
import { nextHeaderHidden } from '../header-scroll';
import { ARTICLE_FOCUS_CURVE, needsReadingTail, readingBlocks } from '../reading-focus';
import { ReadingFocusApplier } from '../reading-focus-applier';
import { articleOverflowsViewport, readingProgress } from '../reading-progress';
import {
  AXIS_LOCK_MIN,
  atBottom,
  isBackSwipe,
  overscrollTriggersBack,
  rubberBand,
} from '../reader-gestures';
import { formatDuration, relativeTime } from '../format';
import { markLeadParagraph } from '../lead-paragraph';
import { markInsetCards } from '../reader-cards';
import { fitReaderImages } from '../reader-image-fit';
import { highlightCodeBlocks } from '../code-highlight';
import { attachHlsStreams } from '../hls-streams';
import { upgradeMediaEmbeds } from '../media-embeds';
import { markNarrationPlayers } from '../reader-narration';
import { hydrateSlideshows } from '../reader-slideshow';
import { estimateReadingMinutes } from '../reading-time';
import { selectionQueryParams } from '../query';
import { ReadingFocusService } from '../../core/reading-focus.service';
import { AudioPlayerService } from '../audio-player.service';
import { firstAudioAttachment, toAudioTrack } from '../audio-attachment';

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
  imports: [
    IconComponent,
    ListActionDirective,
    FaviconComponent,
    SpinnerComponent,
    LoadingOverlayComponent,
    SourceTagsComponent,
    ToTopButtonComponent,
    RouterLink,
    TranslocoPipe,
    PaywallNoticeComponent,
    WarningBoxComponent,
  ],
  templateUrl: './reader-view.component.html',
  styleUrl: './reader-view.component.scss',
})
export class ReaderViewComponent {
  protected readonly selectionQueryParams = selectionQueryParams;

  readonly entry = input.required<EntryDto | null>();
  readonly tags = input<SubscriptionTagDto[]>([]);
  /** Full-screen reading (the mobile overlay) vs. the split pane. The article
   *  is its own layer: its own hide-on-scroll toolbar, slide-out back button,
   *  and return gestures. The shell's app bar stays beneath, untouched (#128). */
  readonly fullscreen = input(false);

  readonly favorite = output<void>();
  readonly keep = output<void>();
  readonly read = output<void>();
  readonly openOriginal = output<void>();
  // Semantic "back to list" output; not a DOM element's close event.
  // eslint-disable-next-line @angular-eslint/no-output-native
  readonly close = output<void>();

  private readonly content = viewChild<ElementRef<HTMLElement>>('content');
  /** Focus target for the corner button on activation — see scrollToTop(). */
  private readonly titleHeading = viewChild<ElementRef<HTMLElement>>('titleHeading');
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly reader = inject(ReaderContentService);
  private readonly i18n = inject(TranslocoService);
  protected readonly readerMode = inject(ReaderModeService);
  private readonly language = inject(LanguageService);
  private readonly scroll = inject(ListScrollMemory);
  protected readonly screen = inject(LayoutService);
  private readonly readingFocus = inject(ReadingFocusService);
  private readonly audioPlayer = inject(AudioPlayerService);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly formatDuration = formatDuration;

  /** The entry's first playable audio enclosure, surfaced as a listen control
   *  above the article; null when the feed declared none (#915). */
  protected readonly audioAttachment = computed(() =>
    firstAudioAttachment(this.entry()?.attachments ?? []),
  );

  protected listen(): void {
    const entry = this.entry();
    const attachment = this.audioAttachment();
    if (entry && attachment) this.audioPlayer.play(toAudioTrack(entry, attachment));
  }

  // Article scroll restore: a resume-reload reopens the entry at the top; re-seat
  // it where the user was. `pendingRestore` holds the target until it lands
  // (re-asserted across the content swap/image loads) or the user scrolls.
  private pendingRestore: { id: number; top: number } | null = null;
  private restoreRaf = 0;

  // Reading-focus: the paragraph nearest the reading centre stays fully opaque
  // while the rest dims. Skipped entirely when the setting is off or the reader
  // prefers reduced motion.
  private readonly reduceMotion =
    typeof matchMedia !== 'undefined' && matchMedia('(prefers-reduced-motion: reduce)').matches;
  private applier?: ReadingFocusApplier;
  private contentObs?: ResizeObserver;

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

  // The open entry's reference changes on every optimistic flag update, but its
  // id doesn't. Tracking the loaded id lets the load effect ignore those churns
  // — no re-fetch, and the Reader/Original toggle survives an in-reader action.
  private loadedId: number | null = null;
  private loadSub: Subscription | null = null;

  // Alias the shared mode signal so the template and computeds read it directly;
  // writes go through the ReaderModeService lifecycle methods below.
  readonly mode = this.readerMode.mode;
  private readonly state = signal<
    | { status: 'idle' | 'loading' }
    | { status: 'ok'; article: ReaderArticle }
    | { status: 'failed'; failure: ReaderFailure | null; error: unknown }
  >({ status: 'idle' });

  // Table of contents, built from the rendered article headings. Collapsed by
  // default (tocOpen); only shown once an article has enough headings.
  readonly toc = signal<TocEntry[]>([]);
  readonly showToc = computed(() => this.toc().length >= TOC_MIN_HEADINGS);
  readonly tocOpen = signal(false);

  /** Back-to-top affordance: revealed once the reader has scrolled past a screen. */
  readonly showToTop = signal(false);

  /** Full-screen only: the toolbar retracts scrolling down and returns
   *  scrolling up, exactly like the list's app bar over the list — driven by
   *  this article's own scroller alone. */
  readonly toolbarHidden = signal(false);
  private lastToolbarScrollTop = 0;

  // The article's scroll range, re-measured whenever the content or the pane
  // changes size — see measureScrollRange(). The reading tail and the progress
  // bar are both derived from it rather than measuring the DOM for themselves.
  private readonly contentBottom = signal(0);
  private readonly viewportHeight = signal(0);
  private readonly scrollTop = signal(0);

  /** Whether the article carries tail space below it. */
  readonly hasTail = computed(() => needsReadingTail(this.contentBottom(), this.viewportHeight()));

  /**
   * The article's length-and-position cue. On a phone it's the only one there is:
   * a mobile browser paints no scrollbar for the shell's nested scroller, so the
   * reader had no way to judge how long an article was (#238).
   */
  readonly showProgress = computed(() =>
    articleOverflowsViewport(this.contentBottom(), this.viewportHeight()),
  );
  readonly progressPercent = computed(
    () => readingProgress(this.scrollTop(), this.viewportHeight(), this.contentBottom()) * 100,
  );

  readonly loading = computed(() => this.state().status === 'loading');
  readonly failed = computed(() => this.state().status === 'failed');
  /** The diagnostic detail behind the fallback note's "show error" disclosure.
   *  A backend failure prefers the server's own cause (the fetch's HTTP status or
   *  transport message), falling back to the bare reason code when it sent none;
   *  a transport failure in the browser carries the complete HTTP message. */
  readonly errorDetail = computed<string | null>(() => {
    const s = this.state();
    if (s.status !== 'failed') return null;
    return s.failure ? (s.failure.detail ?? s.failure.reason) : describeLoadError(s.error);
  });
  private readonly article = computed(() => {
    const s = this.state();
    return s.status === 'ok' ? s.article : null;
  });
  /** The reader body is the free preview of a paywalled article (#785). The
   *  original view shows the feed's own teaser, which needs no such note. */
  readonly paywalled = computed(
    () => this.mode() === 'reader' && (this.article()?.paywalled ?? false),
  );
  readonly paywallUrl = computed(() => this.article()?.url || this.entry()?.url || null);
  /** Set when the original view's hero image fails to load, so a broken picture
   *  hides rather than leaving a torn placeholder. Reset on every entry change. */
  protected readonly heroFailed = signal(false);

  /** The payload the heroes come from. Null while loading, and after a
   *  transport error, where no payload arrived at all. */
  private readonly heroSource = computed<ReaderContent | null>(() => {
    const s = this.state();
    if (s.status === 'ok') return s.article;
    if (s.status === 'failed') return s.failure;
    return null;
  });

  /**
   * The picture that leads the ORIGINAL view. The reader view carries its own
   * lead inside contentHtml now (#681), so it needs no hero element here; only
   * the original view, which renders the raw feed body, still gets one.
   */
  readonly hero = computed(() => {
    const source = this.heroSource();
    if (source === null || this.mode() === 'reader') return null;
    const image = source.originalHero;
    return image === null || this.heroFailed() ? null : image;
  });

  /** Estimated minutes to read the displayed text; null hides the meta chip. */
  readonly readingMinutes = computed(() => estimateReadingMinutes(this.displayHtml()));

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
      this.readerMode.reset();
      this.cancelRestore();
      // A new article starts at the top, with a fresh, collapsed TOC and its
      // toolbar presented — this instance is reused, and a retracted toolbar
      // must not carry over to the next article.
      this.toc.set([]);
      this.tocOpen.set(false);
      this.heroFailed.set(false);
      this.showToTop.set(false);
      this.toolbarHidden.set(false);
      this.lastToolbarScrollTop = 0;
      this.scrollTop.set(0);
      if (!e) {
        this.loadSub?.unsubscribe();
        this.pendingRestore = null;
        this.state.set({ status: 'idle' });
        return;
      }
      // Arm a scroll restore for this entry if we remember a position for it.
      const savedTop = this.scroll.readEntry(e.id);
      this.pendingRestore = savedTop > 0 ? { id: e.id, top: savedTop } : null;
      this.runLoad(this.reader.load(e.id));
    });
    this.destroyRef.onDestroy(() => this.loadSub?.unsubscribe());

    // The applier is rebuilt whenever `content` itself is (re)created — per
    // article and on the reader/original swap — destroying the old one first.
    effect(() => {
      const content = this.content()?.nativeElement;
      this.applier?.destroy();
      this.applier = undefined;
      if (!content) return;
      this.applier = new ReadingFocusApplier({
        scroller: this.host.nativeElement,
        blocks: () => readingBlocks(content),
        curve: ARTICLE_FOCUS_CURVE,
        isActive: () => this.readingFocus.enabled() && !this.screen.isWide() && !this.reduceMotion,
      });
    });

    effect(() => {
      if (this.readingFocus.enabled()) this.applier?.refresh();
      else this.applier?.clear();
    });

    // Re-decorate external links and re-seat the reading focus whenever the
    // rendered HTML changes (new article, or Reader/Original toggle).
    effect(() => {
      this.displayHtml();
      // Depend on the container too, not just the HTML: when extraction fails,
      // displayHtml() recomputes to the same string and never notifies, so the
      // container replacing the placeholder is the only render signal (#101).
      if (!this.content()) return;
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
        markLeadParagraph(host);
        markInsetCards(host);
        fitReaderImages(host);
        void highlightCodeBlocks(host);
        upgradeMediaEmbeds(host);
        markNarrationPlayers(host, this.i18n.translate('reader.narrationPlayer'));
        hydrateSlideshows(host, {
          previous: this.i18n.translate('reader.slideshowPrevious'),
          next: this.i18n.translate('reader.slideshowNext'),
          position: (current, total) =>
            this.i18n.translate('reader.slideshowPosition', { current, total }),
        });
        attachHlsStreams(host);
        this.buildToc(host);
        this.applier?.refresh();
        this.measureScrollRange();
        // Content just (re-)rendered — re-seat a pending scroll restore. Runs on
        // the original render and again when the reader content swaps in.
        if (this.pendingRestore?.id === this.entry()?.id) this.startRestore();
      });
    });

    // A viewport resize changes whether the article still needs tail space —
    // the applier observes its own geometry and needs no nudge here.
    const onResize = () => {
      this.measureScrollRange();
    };
    window.addEventListener('resize', onResize, { passive: true });
    this.destroyRef.onDestroy(() => window.removeEventListener('resize', onResize));

    // The article's height firms up after first paint (images, fonts, the
    // original→reader swap), and whether it overflows can change with it.
    effect(() => {
      const el = this.content()?.nativeElement;
      this.contentObs?.disconnect();
      this.contentObs = undefined;
      if (!el || typeof ResizeObserver === 'undefined') return;
      const obs = new ResizeObserver(() => this.measureScrollRange());
      obs.observe(el);
      this.contentObs = obs;
    });
    this.destroyRef.onDestroy(() => this.contentObs?.disconnect());
    this.destroyRef.onDestroy(() => this.applier?.destroy());

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

  /** Subscribe to a content source (initial load or cache-busting reload),
   *  driving the loading → ok/failed lifecycle. Reader/original mode is
   *  untouched here — only a genuine entry change resets it. */
  private runLoad(source: Observable<ReaderContent>): void {
    this.loadSub?.unsubscribe();
    this.state.set({ status: 'loading' });
    this.loadSub = source.pipe(timeout({ first: READER_LOAD_TIMEOUT_MS })).subscribe({
      next: (c) => {
        if (c.status === 'ok') {
          this.state.set({ status: 'ok', article: c });
          this.readerMode.enableToggle();
        } else {
          this.state.set({ status: 'failed', failure: c, error: null });
          this.readerMode.setOriginalOnly();
        }
      },
      error: (error: unknown) => {
        // A timeout or a transport error leaves no payload, so this article
        // shows the feed's content with no hero. Keep the error so the reader
        // can reveal the complete HTTP message behind the fallback note.
        this.state.set({ status: 'failed', failure: null, error });
        this.readerMode.setOriginalOnly();
      },
    });
  }

  onTouchStart(e: TouchEvent): void {
    this.pendingRestore = null; // the user is taking over; stop restoring
    if (!this.fullscreen() || this.leaving() || e.touches.length !== 1) return;
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
    if (!this.fullscreen() || this.leaving() || e.touches.length !== 1) return;
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
    if (!this.fullscreen() || this.leaving()) return;
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

  /** The toolbar's back button. Full-screen it plays the same slide-out as a
   *  back-swipe rather than cutting straight to the list; the split pane has
   *  no overlay to slide, so it closes directly. */
  onBack(): void {
    if (this.fullscreen()) this.slideBack();
    else this.close.emit();
  }

  private slideBack(): void {
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
    const scrollTop = this.host.nativeElement.scrollTop;
    this.scrollTop.set(scrollTop);
    this.showToTop.set(scrollTop > BACK_TO_TOP_AFTER_PX);
    if (this.fullscreen()) {
      // `isWide` is false by definition here: full-screen reading only exists
      // on the narrow layout, and the split pane keeps its toolbar put.
      this.toolbarHidden.set(
        nextHeaderHidden(this.toolbarHidden(), this.lastToolbarScrollTop, scrollTop, false),
      );
    }
    this.lastToolbarScrollTop = scrollTop;
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
    // Land focus on the title, not wherever the button was — an unmounted button
    // drops focus to <body>. preventScroll is needed since the heading is still
    // off-screen (why the button showed); a plain focus() would cancel the scroll.
    this.titleHeading()?.nativeElement.focus({ preventScroll: true });
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

  /**
   * Measure how far the article reaches inside its pane. Takes the article's own
   * content box — never the panel's, which already includes the tail and would
   * feed the measurement back into itself.
   */
  private measureScrollRange(): void {
    const host = this.host.nativeElement;
    this.viewportHeight.set(host.clientHeight);
    const content = this.content()?.nativeElement;
    if (!content) {
      this.contentBottom.set(0);
      return;
    }
    this.contentBottom.set(
      content.getBoundingClientRect().bottom - host.getBoundingClientRect().top + host.scrollTop,
    );
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
    const offset = this.fullscreen() ? 8 : 52;
    const top = el.getBoundingClientRect().top - host.getBoundingClientRect().top + host.scrollTop;
    host.scrollTo({
      top: Math.max(0, top - offset),
      behavior: this.reduceMotion ? 'auto' : 'smooth',
    });
  }

  toggleMode(): void {
    this.readerMode.toggle();
  }

  /** Drop the open article from the browser cache and refetch it. */
  refreshArticle(): void {
    const e = this.entry();
    if (!e) return;
    this.runLoad(this.reader.reload(e.id));
  }

  when(e: EntryDto): string {
    return relativeTime(e.publishedAt ?? e.createdAt, this.language.lang());
  }
}
