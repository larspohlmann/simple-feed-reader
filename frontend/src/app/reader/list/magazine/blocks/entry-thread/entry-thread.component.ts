import { ChangeDetectionStrategy, Component, computed } from '@angular/core';
import { NgTemplateOutlet } from '@angular/common';
import { EntryKickerLineComponent } from '../../entry-kicker-line.component';
import { EntryMetaComponent } from '../../../entry-meta/entry-meta.component';
import { EntryDuplicatesComponent } from '../../entry-duplicates.component';
import { EntryBlockBase } from '../../entry-block-base';
import { TranslocoPipe } from '@jsverse/transloco';
import { LinkCardComponent } from '../../../../link-card/link-card.component';
import { LinkCardPlaceholderComponent } from '../../../../link-card/link-card-placeholder.component';
import { PostMediaComponent } from '../../../../media/post-media/post-media.component';
import { QuotedPostComponent } from '../../../../quoted-post/quoted-post.component';
import { EntryMediumDto, entryMediumRatio } from '../../../../entry-medium';
import { sharesQuotedPost, threadRootMedia } from '../../magazine-slot-fit';
import { OpensEntryDirective } from '../../../opens-entry.directive';
import { ThreadLineComponent } from '../../../../../shared/thread-line/thread-line.component';
import { ThreadLinePostComponent } from '../../../../../shared/thread-line/thread-line-post.component';
import { ThreadCountComponent } from '../../../../../shared/thread-line/thread-count.component';
import { threadTotal } from '../../../../thread-post';

@Component({
  selector: 'app-entry-thread',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    NgTemplateOutlet,
    OpensEntryDirective,
    EntryKickerLineComponent,
    EntryMetaComponent,
    EntryDuplicatesComponent,
    LinkCardComponent,
    LinkCardPlaceholderComponent,
    PostMediaComponent,
    QuotedPostComponent,
    TranslocoPipe,
    ThreadLineComponent,
    ThreadLinePostComponent,
    ThreadCountComponent,
  ],
  templateUrl: './entry-thread.component.html',
  styleUrl: './entry-thread.component.scss',
})
export class EntryThreadComponent extends EntryBlockBase {
  protected readonly total = computed(() => threadTotal(this.entry()));
  protected readonly rootMedia = computed(() => threadRootMedia(this.entry()));
  protected readonly rootQuote = computed(() =>
    sharesQuotedPost(this.entry()) ? this.entry().quote : null,
  );

  protected ratio(medium: EntryMediumDto): number {
    return entryMediumRatio(this.entry(), medium);
  }
}
