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

  /** A portrait cover keeps its own shape; a landscape side box is never wider than
   *  3:2. Unknown dimensions keep the 3:2 default. */
  readonly aspect = computed(() => {
    const portrait = this.portraitRatio();
    if (portrait !== null) {
      return portrait;
    }
    const img = this.image();
    if (!img?.width || !img?.height) {
      return '3 / 2';
    }
    return `${img.width} / ${Math.round(Math.max(img.height, (img.width * 2) / 3))}`;
  });
}
