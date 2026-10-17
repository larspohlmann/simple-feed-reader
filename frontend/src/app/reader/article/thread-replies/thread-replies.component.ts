import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { EntryDto } from '../../models';
import { mediumRatio } from '../../entry-medium';
import { LinkCardComponent } from '../../link-card/link-card.component';
import { PostMediaComponent } from '../../media/post-media/post-media.component';
import { LinkReaderNavigation } from '../../shell/link-reader-navigation.service';
import { ThreadLineComponent } from '../../../shared/thread-line/thread-line.component';
import { ThreadLinePostComponent } from '../../../shared/thread-line/thread-line-post.component';
import { ThreadCountComponent } from '../../../shared/thread-line/thread-count.component';
import { threadTotal } from '../../thread-post';

@Component({
  selector: 'app-thread-replies',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    LinkCardComponent,
    PostMediaComponent,
    TranslocoPipe,
    ThreadLineComponent,
    ThreadLinePostComponent,
    ThreadCountComponent,
  ],
  templateUrl: './thread-replies.component.html',
  styleUrl: './thread-replies.component.scss',
})
export class ThreadRepliesComponent {
  readonly entry = input.required<EntryDto>();
  protected readonly navigation = inject(LinkReaderNavigation);

  protected readonly total = computed(() => threadTotal(this.entry()));

  protected ratioOf(media: EntryDto['thread'][number]['media']): number {
    return mediumRatio(media[0]);
  }
}
