// src/app/reader/magazine/entry-quote.component.ts
import { Component, computed } from '@angular/core';
import { textSnippet } from '../preview-image';
import { EntryKickerLineComponent } from './entry-kicker-line.component';
import { EntryMetaComponent } from '../entry-meta/entry-meta.component';
import { EntryBlockBase } from './entry-block-base';

@Component({
  selector: 'app-entry-quote',
  imports: [EntryKickerLineComponent, EntryMetaComponent],
  templateUrl: './entry-quote.component.html',
  styleUrl: './entry-quote.component.scss',
})
export class EntryQuoteComponent extends EntryBlockBase {
  /** The pull-quote is the first sentence, not a clamped paragraph: a clamp
   *  ends mid-word, which reads as a rendering bug at this type size. */
  // Accepted imprecision: an abbreviation like "e.g. " ends the quote early
  // because the regex stops at the first period-plus-space — not worth the
  // complexity of an abbreviation-aware sentence splitter.
  readonly lead = computed(() => {
    const text = textSnippet(this.entry().summary || this.entry().contentHtml);
    const stop = text.search(/[.!?](\s|$)/);
    return stop === -1 ? text : text.slice(0, stop + 1);
  });
}
