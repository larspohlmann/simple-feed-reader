import { DOCUMENT } from '@angular/common';
import {
  DestroyRef,
  ElementRef,
  Injectable,
  Injector,
  Signal,
  effect,
  inject,
  signal,
  untracked,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { debounceTime, fromEvent } from 'rxjs';
import { SelectedQuote } from './selected-quote';
import { selectionText, wordCount } from './selection-text';

export const QUOTE_MIN_WORDS = 3;
/** A drag in progress is no quote yet; the same rest delays the hide, so a tap that collapses it still lands. */
export const SELECTION_SETTLE_MS = 150;

export interface ArticleSelectionSources {
  readonly body: Signal<ElementRef<HTMLElement> | undefined>;
  /** A change ends the remembered quote: another article is open. */
  readonly key: Signal<unknown>;
  readonly socialPost: Signal<boolean>;
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
  private readonly liveQuote = signal<SelectedQuote | null>(null);
  private readonly keptQuote = signal<SelectedQuote | null>(null);
  private readonly liveRange = signal<Range | null>(null);
  private sources: ArticleSelectionSources | null = null;

  readonly quote = this.liveQuote.asReadonly();
  readonly remembered = this.keptQuote.asReadonly();
  readonly range = this.liveRange.asReadonly();

  connect(sources: ArticleSelectionSources): void {
    this.sources = sources;
    effect(
      () => {
        sources.key();
        untracked(() => this.forget());
      },
      { injector: this.injector },
    );
    fromEvent(this.document, 'selectionchange')
      .pipe(debounceTime(SELECTION_SETTLE_MS), takeUntilDestroyed(this.destroyRef))
      .subscribe(() => this.read());
  }

  private read(): void {
    const range = this.selectedRange();
    const quote = range === null || !this.inBody(range) ? null : this.quoteIn(range);
    this.liveRange.set(quote === null || range === null ? null : range.cloneRange());
    this.liveQuote.set(quote);
    if (range !== null && this.inBody(range)) this.keptQuote.set(quote);
  }

  private selectedRange(): Range | null {
    const selection = this.document.getSelection();
    if (selection === null || selection.isCollapsed || selection.rangeCount === 0) return null;
    return selection.getRangeAt(0);
  }

  private inBody(range: Range): boolean {
    const body = this.sources?.body()?.nativeElement;
    return body !== undefined && body.contains(range.commonAncestorContainer);
  }

  private quoteIn(range: Range): SelectedQuote | null {
    const { text, singleBlock } = selectionText(range);
    const words = wordCount(text);
    if (words < QUOTE_MIN_WORDS) return null;
    return { text, words, anchorable: singleBlock && this.sources?.socialPost() === false };
  }

  private forget(): void {
    this.liveQuote.set(null);
    this.keptQuote.set(null);
    this.liveRange.set(null);
  }
}
