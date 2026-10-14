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
import { mediumRatio } from '../../../../entry-medium';

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

  protected readonly ratio = mediumRatio;
}
