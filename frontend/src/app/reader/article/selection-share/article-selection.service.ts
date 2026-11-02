import { DOCUMENT } from '@angular/common';
import {
  DestroyRef,
  ElementRef,
  Injectable,
  Injector,
  NgZone,
  Signal,
  computed,
  effect,
  inject,
  signal,
  untracked,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { debounceTime, fromEvent } from 'rxjs';
import { SelectedQuote } from './selected-quote';
import { selectionText, wordsOf } from './selection-text';

export const QUOTE_MIN_WORDS = 3;
/** A drag in progress is no quote yet; the same rest delays the hide, so a tap that collapses it still lands. */
export const SELECTION_SETTLE_MS = 150;

interface LiveSelection {
  quote: SelectedQuote;
  range: Range;
}

export interface ArticleSelectionSources {
  readonly body: Signal<ElementRef<HTMLElement> | undefined>;
  /** A change ends the remembered quote: another article is open. */
  readonly key: Signal<unknown>;
  readonly socialPost?: Signal<boolean>;
}

/**
 * The reader's selection inside the article body, as a quote to share. `remembered` outlives a collapse — a tap on a
 * phone collapses the selection before the share it starts — until another selection or another article ends it.
 */
@Injectable()
export class ArticleSelection {
  private readonly document = inject(DOCUMENT);
  private readonly injector = inject(Injector);
  private readonly destroyRef = inject(DestroyRef);
  private readonly zone = inject(NgZone);
  private readonly live = signal<LiveSelection | null>(null, { equal: sameSelection });
  private readonly keptQuote = signal<SelectedQuote | null>(null);
  private sources: ArticleSelectionSources | null = null;

  readonly quote = computed(() => this.live()?.quote ?? null);
  readonly remembered = this.keptQuote.asReadonly();
  readonly range = computed(() => this.live()?.range ?? null);

  connect(sources: ArticleSelectionSources): void {
    this.sources = sources;
    effect(
      () => {
        sources.key();
        untracked(() => this.forget());
      },
      { injector: this.injector },
    );
    this.zone.runOutsideAngular(() =>
      fromEvent(this.document, 'selectionchange')
        .pipe(debounceTime(SELECTION_SETTLE_MS), takeUntilDestroyed(this.destroyRef))
        .subscribe(() => this.read()),
    );
  }

  /** On a phone the native selection callout floats over whatever the quote opens; collapsing drops it. */
  releasePageSelection(): void {
    this.document.getSelection()?.removeAllRanges();
  }

  private read(): void {
    const range = this.rangeInBody();
    const quote = range && this.quoteIn(range);
    this.live.set(quote && range ? { quote, range: range.cloneRange() } : null);
    if (range !== null) this.keptQuote.set(quote);
  }

  private rangeInBody(): Range | null {
    const selection = this.document.getSelection();
    if (selection === null || selection.isCollapsed || selection.rangeCount === 0) return null;
    const range = selection.getRangeAt(0);
    return this.inBody(range) ? range : null;
  }

  private inBody(range: Range): boolean {
    const body = this.sources?.body()?.nativeElement;
    return body !== undefined && body.contains(range.commonAncestorContainer);
  }

  private quoteIn(range: Range): SelectedQuote | null {
    const { text, singleBlock } = selectionText(range);
    const words = wordsOf(text).length;
    if (words < QUOTE_MIN_WORDS) return null;
    return { text, words, anchorable: singleBlock && this.sources?.socialPost?.() !== true };
  }

  private forget(): void {
    this.live.set(null);
    this.keptQuote.set(null);
  }
}

function sameSelection(left: LiveSelection | null, right: LiveSelection | null): boolean {
  if (left === null || right === null) return left === right;
  return (
    left.quote.text === right.quote.text &&
    left.quote.anchorable === right.quote.anchorable &&
    left.range.compareBoundaryPoints(Range.START_TO_START, right.range) === 0 &&
    left.range.compareBoundaryPoints(Range.END_TO_END, right.range) === 0
  );
}
