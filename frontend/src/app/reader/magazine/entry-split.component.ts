// src/app/reader/magazine/entry-split.component.ts
import { Component, computed, input } from '@angular/core';
import { textSnippet } from '../preview-image';
import { FaviconComponent } from '../../shared/favicon/favicon.component';
import { SourceTagsComponent } from '../source-tags/source-tags.component';
import { EntryImageBlockBase } from './entry-image-block-base';

@Component({
  selector: 'app-entry-split',
  imports: [FaviconComponent, SourceTagsComponent],
  templateUrl: './entry-split.component.html',
  styleUrl: './entry-split.component.scss',
})
export class EntrySplitComponent extends EntryImageBlockBase {
  readonly imageSide = input<'left' | 'right'>('right');
  readonly snippet = computed(() => textSnippet(this.entry().summary || this.entry().contentHtml));
}
