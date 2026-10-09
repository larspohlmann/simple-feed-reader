import { ChangeDetectionStrategy, Component } from '@angular/core';
import { EntryKickerLineComponent } from '../../entry-kicker-line.component';
import { EntryMetaComponent } from '../../../entry-meta/entry-meta.component';
import { EntryDuplicatesComponent } from '../../entry-duplicates.component';
import { EntryImageBlockBase } from '../../entry-image-block-base';
import { RenditionsDirective } from '../../../renditions.directive';
import { ShortBadgeComponent } from '../../../short-badge/short-badge.component';
import { COVER_BOX_SIZES } from '../../../rendition-sizes';

@Component({
  selector: 'app-entry-thumb',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    EntryKickerLineComponent,
    EntryMetaComponent,
    EntryDuplicatesComponent,
    RenditionsDirective,
    ShortBadgeComponent,
  ],
  templateUrl: './entry-thumb.component.html',
  styleUrl: './entry-thumb.component.scss',
})
export class EntryThumbComponent extends EntryImageBlockBase {
  protected readonly coverBoxSizes = COVER_BOX_SIZES;
}
