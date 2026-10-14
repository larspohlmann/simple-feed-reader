import { ChangeDetectionStrategy, Component, computed } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { EntryKickerLineComponent } from '../../entry-kicker-line.component';
import { EntryMetaComponent } from '../../../entry-meta/entry-meta.component';
import { EntryDuplicatesComponent } from '../../entry-duplicates.component';
import { EntryBlockBase } from '../../entry-block-base';
import { sharedMedia } from '../../magazine-slot-fit';
import { OpensEntryDirective } from '../../../opens-entry.directive';
import { PostMediaComponent } from '../../../../media/post-media/post-media.component';
import { QuotedPostComponent } from '../../../../quoted-post/quoted-post.component';
import { EntryMediumDto } from '../../../../entry-medium';

const UNKNOWN_RATIO = 16 / 9;

/** A post that quotes another (#1612): its own text and media, then the quoted post nested. */
@Component({
  selector: 'app-entry-quoting',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    OpensEntryDirective,
    EntryKickerLineComponent,
    EntryMetaComponent,
    EntryDuplicatesComponent,
    PostMediaComponent,
    QuotedPostComponent,
    TranslocoPipe,
  ],
  templateUrl: './entry-quoting.component.html',
  styleUrl: './entry-quoting.component.scss',
})
export class EntryQuotingComponent extends EntryBlockBase {
  readonly media = computed(() => sharedMedia(this.entry()));

  protected ratio(medium: EntryMediumDto): number {
    return medium.width && medium.height ? medium.width / medium.height : UNKNOWN_RATIO;
  }
}
