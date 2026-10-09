import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { EntryKickerLineComponent } from '../../entry-kicker-line.component';
import { EntryMetaComponent } from '../../../entry-meta/entry-meta.component';
import { EntryDuplicatesComponent } from '../../entry-duplicates.component';
import { EntryImageBlockBase } from '../../entry-image-block-base';
import { RenditionsDirective } from '../../../renditions.directive';
import { ShortBadgeComponent } from '../../../short-badge/short-badge.component';
import { SPLIT_SIDE_IMAGE_SIZES } from '../../../rendition-sizes';

@Component({
  selector: 'app-entry-split',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    EntryKickerLineComponent,
    EntryMetaComponent,
    EntryDuplicatesComponent,
    RenditionsDirective,
    ShortBadgeComponent,
  ],
  templateUrl: './entry-split.component.html',
  styleUrl: './entry-split.component.scss',
})
export class EntrySplitComponent extends EntryImageBlockBase {
  protected readonly splitSideImageSizes = SPLIT_SIDE_IMAGE_SIZES;
  readonly imageSide = input<'left' | 'right'>('right');

  /** The side box adapts to the image but stays bounded — landscape crops to
   *  3:2, portrait to 3:4. A portrait routed here from `hero`/`wide` shows AS a
   *  portrait, not a thin cropped sliver. Unknown dimensions keep the 3:2 default;
   *  a Short's box is the stylesheet's. */
  readonly aspect = computed(() => {
    if (this.entry().isShort) {
      return null;
    }
    const img = this.image();
    if (!img?.width || !img?.height) {
      return '3 / 2';
    }
    const height = Math.min(Math.max(img.height, (img.width * 2) / 3), (img.width * 4) / 3);
    return `${img.width} / ${Math.round(height)}`;
  });
}
