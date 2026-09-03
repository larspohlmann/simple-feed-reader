import { Component, computed, input } from '@angular/core';
import { EntryKickerLineComponent } from './entry-kicker-line.component';
import { EntryMetaComponent } from '../entry-meta/entry-meta.component';
import { EntryImageBlockBase } from './entry-image-block-base';

@Component({
  selector: 'app-entry-split',
  imports: [EntryKickerLineComponent, EntryMetaComponent],
  templateUrl: './entry-split.component.html',
  styleUrl: './entry-split.component.scss',
})
export class EntrySplitComponent extends EntryImageBlockBase {
  readonly imageSide = input<'left' | 'right'>('right');

  /** The side box adapts to the image but stays bounded — landscape crops to
   *  3:2, portrait to 3:4. A portrait routed here from `hero`/`wide` shows AS a
   *  portrait, not a thin cropped sliver. Unknown dimensions keep the 3:2 default. */
  readonly aspect = computed(() => {
    const img = this.image();
    if (!img?.width || !img?.height) {
      return '3 / 2';
    }
    const height = Math.min(Math.max(img.height, (img.width * 2) / 3), (img.width * 4) / 3);
    return `${img.width} / ${Math.round(height)}`;
  });
}
