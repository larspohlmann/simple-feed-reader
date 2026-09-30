import {
  DestroyRef,
  ElementRef,
  Injectable,
  Injector,
  Signal,
  computed,
  effect,
  inject,
  signal,
  untracked,
} from '@angular/core';
import { READER_SCROLLER } from '../../scroll/reader-scroller';
import { LanguageService } from '../../../core/i18n/language.service';
import { ReadingFocusService } from '../../../core/preferences/reading-focus.service';
import { LayoutService } from '../../layout.service';
import { ARTICLE_FOCUS_CURVE, needsReadingTail, readingBlocks } from './reading-focus';
import { ReadingFocusApplier } from './reading-focus-applier';
import { sectionedUnits } from './reading-sections';
import { articleOverflowsViewport, readingProgress } from './reading-progress';
import { prefersReducedMotion } from './reduced-motion';

type ElementQuery = Signal<ElementRef<HTMLElement> | undefined>;

function isPresent(element: HTMLElement | undefined): element is HTMLElement {
  return element !== undefined;
}

/** The article's reading scope — the body plus its comments: the reading-focus
 *  dimming, the tail space below it and the progress bar. `connect` and
 *  `observeResizes` create its effects, so the host decides where they fall in
 *  its own effect order. */
@Injectable()
export class ReadingScope {
  private readonly scroller = inject(READER_SCROLLER);
  private readonly readingFocus = inject(ReadingFocusService);
  private readonly screen = inject(LayoutService);
  private readonly language = inject(LanguageService);
  private readonly injector = inject(Injector);
  private readonly destroyRef = inject(DestroyRef);
  // Reading-focus: the paragraph nearest the reading centre stays fully opaque
  // while the rest dims. Skipped entirely when the setting is off or the reader
  // prefers reduced motion.
  private readonly reduceMotion = prefersReducedMotion();
  private content: ElementQuery = signal(undefined);
  private comments: ElementQuery = signal(undefined);
  private applier?: ReadingFocusApplier;
  private scopeObserver?: ResizeObserver;

  // The reading scope's extent, re-measured whenever the content, the comments
  // or the pane changes size — see measureScrollRange(). The tail keys on the
  // scope's bottom (body + comments); the progress bar keys on the body alone.
  private readonly contentBottom = signal(0);
  private readonly readingBottom = signal(0);
  private readonly viewportHeight = signal(0);
  private readonly scrollTop = signal(0);

  /** Whether the article carries tail space below it. */
  readonly hasTail = computed(() => needsReadingTail(this.readingBottom(), this.viewportHeight()));

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

  constructor() {
    this.destroyRef.onDestroy(() => {
      this.scopeObserver?.disconnect();
      this.applier?.destroy();
    });
  }

  /** Bind the article body and comments, and create the focus effects. */
  connect(content: ElementQuery, comments: ElementQuery): void {
    this.content = content;
    this.comments = comments;
    // The applier is rebuilt whenever `content` itself is (re)created — per
    // article and on the reader/original swap — destroying the old one first.
    effect(
      () => {
        const element = this.content()?.nativeElement;
        this.applier?.destroy();
        this.applier = undefined;
        if (!element) return;
        this.applier = new ReadingFocusApplier({
          scroller: this.scroller,
          blocks: () =>
            [element, this.commentsHost()].filter(isPresent).flatMap((root) => readingBlocks(root)),
          curve: ARTICLE_FOCUS_CURVE,
          isActive: () =>
            this.readingFocus.enabled() && !this.screen.isWide() && !this.reduceMotion,
          units: sectionedUnits(() => this.language.lang()),
        });
      },
      { injector: this.injector },
    );

    effect(
      () => {
        if (this.readingFocus.enabled()) this.applier?.refresh();
        else this.applier?.clear();
      },
      { injector: this.injector },
    );
  }

  /** Re-measure as the scope's size settles: a viewport resize, and the body
   *  or the comments changing height after first paint. */
  observeResizes(): void {
    // A viewport resize changes whether the article still needs tail space —
    // the applier observes its own geometry and needs no nudge here.
    const onResize = () => {
      this.measureScrollRange();
    };
    window.addEventListener('resize', onResize, { passive: true });
    this.destroyRef.onDestroy(() => window.removeEventListener('resize', onResize));

    // The scope's height firms up after first paint (images, fonts, the original→reader
    // swap), and the comments load after the article, past the applier's last refresh.
    effect(
      () => {
        const content = this.content()?.nativeElement;
        const comments = this.comments()?.nativeElement;
        this.scopeObserver?.disconnect();
        this.scopeObserver = undefined;
        if (typeof ResizeObserver === 'undefined') return;
        const targets = [content, comments].filter(isPresent);
        if (targets.length === 0) return;
        const observer = new ResizeObserver(() => this.refresh());
        for (const target of targets) observer.observe(target);
        this.scopeObserver = observer;
      },
      { injector: this.injector },
    );
  }

  /** The content re-rendered: re-seat the focus and re-measure. */
  refresh(): void {
    this.applier?.refresh();
    this.measureScrollRange();
  }

  trackScroll(top: number): void {
    this.scrollTop.set(top);
  }

  reset(): void {
    this.scrollTop.set(0);
  }

  /**
   * Measure how far the article and its comments reach inside the pane. Takes
   * the article's own content box — never the panel's, which already includes
   * the tail and would feed the measurement back into itself.
   */
  private measureScrollRange(): void {
    this.viewportHeight.set(this.scroller.clientHeight);
    const content = untracked(this.content)?.nativeElement;
    if (!content) {
      this.contentBottom.set(0);
      this.readingBottom.set(0);
      return;
    }
    this.contentBottom.set(this.bottomInScroller(content));
    this.readingBottom.set(this.bottomInScroller(this.commentsHost() ?? content));
  }

  private bottomInScroller(element: HTMLElement): number {
    const host = this.scroller;
    return (
      element.getBoundingClientRect().bottom - host.getBoundingClientRect().top + host.scrollTop
    );
  }

  // `blocks()` runs inside effects, which must not rerun when the comments mount.
  private commentsHost(): HTMLElement | undefined {
    return untracked(this.comments)?.nativeElement;
  }
}
