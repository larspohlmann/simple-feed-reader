// src/app/reader/magazine/entry-split.component.ts
import { Component, computed, input } from '@angular/core';
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

  /** The side box adapts to the image but stays bounded — a landscape crops to
   *  3:2, a portrait to 3:4. A portrait routed here from `hero`/`wide` (the
   *  planner refuses to stack a tall image above the text) therefore shows AS a
   *  portrait beside the text, not as a thin cropped sliver. Unknown dimensions
   *  keep the 3:2 default. */
  readonly aspect = computed(() => {
    const img = this.image();
    if (!img?.width || !img?.height) {
      return '3 / 2';
    }
    const height = Math.min(Math.max(img.height, (img.width * 2) / 3), (img.width * 4) / 3);
    return `${img.width} / ${Math.round(height)}`;
  });
}
