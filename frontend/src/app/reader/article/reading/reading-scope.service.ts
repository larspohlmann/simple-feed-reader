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
import { LanguageService } from '../../../core/i18n/language.service';
import { ReadingFocusService } from '../../../core/preferences/reading-focus.service';
import { LayoutService } from '../../layout.service';
import { ARTICLE_FOCUS_CURVE, needsReadingTail, readingBlocks } from './reading-focus';
import { ReadingFocusApplier } from './reading-focus-applier';
import { sectionedUnits } from './reading-sections';
import { prefersReducedMotion } from '../../../core/reduced-motion';

type ElementQuery = Signal<ElementRef<HTMLElement> | undefined>;

/** A section below the article body; `joinsFocus` puts its blocks under the reading-focus dimming. */
export interface TrailingPart {
  readonly query: ElementQuery;
  readonly joinsFocus: boolean;
  /** The height the part will reach once its content arrives, or null when it shows its final content. */
  readonly expectedHeight?: (part: HTMLElement, readingFontSize: number) => number | null;
}

export interface ReadingScopeParts {
  readonly scroller: Signal<HTMLElement | undefined>;
  readonly content: ElementQuery;
  /** The sections below the body, in document order. */
  readonly trailing: readonly TrailingPart[];
}

function bottomWithin(scroller: HTMLElement, element: HTMLElement): number {
  return (
    element.getBoundingClientRect().bottom -
    scroller.getBoundingClientRect().top +
    scroller.scrollTop
  );
}

function isPresent(element: HTMLElement | undefined): element is HTMLElement {
  return element !== undefined;
}

/** The article's reading scope — the body and the sections below it: the reading-focus
 *  dimming, the tail space below it and where the progress rail ends. `connect` and
 *  `observeResizes` create its effects, so the host decides where they fall in
 *  its own effect order. */
@Injectable()
export class ReadingScope {
  private readonly readingFocus = inject(ReadingFocusService);
  private readonly screen = inject(LayoutService);
  private readonly language = inject(LanguageService);
  private readonly injector = inject(Injector);
  private readonly destroyRef = inject(DestroyRef);
  // Reading-focus: the paragraphs in the reading band stay fully opaque
  // while the rest dims. Skipped entirely when the setting is off or the reader
  // prefers reduced motion.
  private readonly reduceMotion = prefersReducedMotion();
  private scroller: Signal<HTMLElement | undefined> = signal(undefined);
  private content: ElementQuery = signal(undefined);
  private trailing: readonly TrailingPart[] = [];
  private applier?: ReadingFocusApplier;
  private scopeObserver?: ResizeObserver;

  // The tail keys on what is on screen; the progress rail also counts what is still on the way.
  private readonly readingBottom = signal(0);
  private readonly measuredReadingEnd = signal(0);
  private readonly viewportHeight = signal(0);

  /** Where the reading scope will end inside the pane, for the progress rail. */
  readonly readingEnd = this.measuredReadingEnd.asReadonly();

  /** Whether the article carries tail space below it. */
  readonly hasTail = computed(() => needsReadingTail(this.readingBottom(), this.viewportHeight()));

  constructor() {
    this.destroyRef.onDestroy(() => {
      this.scopeObserver?.disconnect();
      this.applier?.destroy();
    });
  }

  /** Bind the scope's parts, and create the focus effects. */
  connect(parts: ReadingScopeParts): void {
    this.scroller = parts.scroller;
    this.content = parts.content;
    this.trailing = parts.trailing;
    const focusParts = parts.trailing.filter((part) => part.joinsFocus);
    // The applier is rebuilt whenever `content` itself is (re)created — per
    // article and on the reader/original swap — destroying the old one first.
    effect(
      () => {
        const scroller = this.scroller();
        const element = this.content()?.nativeElement;
        this.applier?.destroy();
        this.applier = undefined;
        if (!scroller || !element) return;
        this.applier = new ReadingFocusApplier({
          scroller,
          blocks: () =>
            [element, ...this.presentElements(focusParts)].flatMap((root) => readingBlocks(root)),
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

  /** Re-measure as the scope's size settles: a viewport resize, and any part
   *  changing height after first paint. */
  observeResizes(): void {
    // A viewport resize changes whether the article still needs tail space —
    // the applier observes its own geometry and needs no nudge here.
    const onResize = () => {
      this.measureScrollRange();
    };
    window.addEventListener('resize', onResize, { passive: true });
    this.destroyRef.onDestroy(() => window.removeEventListener('resize', onResize));

    // The scope's height firms up after first paint (images, fonts, the original→reader
    // swap), and the trailing parts load after the article, past the applier's last refresh.
    effect(
      () => {
        const targets = [this.content, ...this.trailing.map((part) => part.query)]
          .map((query) => query()?.nativeElement)
          .filter(isPresent);
        this.scopeObserver?.disconnect();
        this.scopeObserver = undefined;
        if (typeof ResizeObserver === 'undefined') return;
        if (targets.length === 0) return;
        const observer = new ResizeObserver(() => this.refresh());
        for (const target of targets) observer.observe(target);
        this.scopeObserver = observer;
      },
      { injector: this.injector },
    );

    // An expected height follows its part's loading state, which no resize announces.
    effect(
      () => {
        for (const part of this.trailing) part.query();
        this.measureScrollRange();
      },
      { injector: this.injector },
    );
  }

  /** The content re-rendered: re-seat the focus and re-measure. */
  refresh(): void {
    this.applier?.refresh();
    this.measureScrollRange();
  }

  /**
   * Measure how far the body and the whole scope reach inside the pane. Takes
   * the article's own content box — never the panel's, which already includes
   * the tail and would feed the measurement back into itself.
   */
  private measureScrollRange(): void {
    const scroller = untracked(this.scroller);
    const content = untracked(this.content)?.nativeElement;
    if (!scroller || !content) {
      this.viewportHeight.set(0);
      this.readingBottom.set(0);
      this.measuredReadingEnd.set(0);
      return;
    }
    const lastPart = this.presentElements(this.trailing).at(-1) ?? content;
    const readingBottom = bottomWithin(scroller, lastPart);
    this.viewportHeight.set(scroller.clientHeight);
    this.readingBottom.set(readingBottom);
    this.measuredReadingEnd.set(readingBottom + this.expectedGrowth(content));
  }

  private expectedGrowth(content: HTMLElement): number {
    const readingFontSize = parseFloat(getComputedStyle(content).fontSize);
    return this.trailing.reduce((growth, part) => {
      const element = untracked(part.query)?.nativeElement;
      const expected =
        element && part.expectedHeight ? part.expectedHeight(element, readingFontSize) : null;
      if (!element || expected === null) return growth;
      return growth + Math.max(0, expected - element.getBoundingClientRect().height);
    }, 0);
  }

  // Untracked: `blocks()` runs inside effects, which must not rerun when a trailing part mounts.
  private presentElements(parts: readonly TrailingPart[]): HTMLElement[] {
    return parts.map((part) => untracked(part.query)?.nativeElement).filter(isPresent);
  }
}
