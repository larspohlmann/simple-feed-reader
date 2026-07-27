// src/app/reader/magazine/entry-quote.component.ts
import { Component, computed } from '@angular/core';
import { textSnippet } from '../preview-image';
import { FaviconComponent } from '../../shared/favicon/favicon.component';
import { SourceTagsComponent } from '../source-tags/source-tags.component';
import { EntryBlockBase } from './entry-block-base';

@Component({
  selector: 'app-entry-quote',
  imports: [FaviconComponent, SourceTagsComponent],
  templateUrl: './entry-quote.component.html',
  styleUrl: './entry-quote.component.scss',
})
export class EntryQuoteComponent extends EntryBlockBase {
  /** The pull-quote is the first sentence, not a clamped paragraph: a clamp
   *  ends mid-word, which reads as a rendering bug at this type size. */
  readonly lead = computed(() => {
    const text = textSnippet(this.entry().summary || this.entry().contentHtml);
    const stop = text.search(/[.!?](\s|$)/);
    return stop === -1 ? text : text.slice(0, stop + 1);
  });
}
