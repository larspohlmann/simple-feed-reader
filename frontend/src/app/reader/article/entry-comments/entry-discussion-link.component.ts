import { ChangeDetectionStrategy, Component, computed, inject, input, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { CommentsService } from './comments.service';
import { EntryDto } from '../../models';

/** The meta line's link to an entry's discussion: its own comments, or the source's thread. */
@Component({
  selector: 'app-entry-discussion-link',
  imports: [TranslocoPipe, IconComponent],
  templateUrl: './entry-discussion-link.component.html',
  styleUrl: './entry-discussion-link.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class EntryDiscussionLinkComponent {
  readonly entry = input.required<EntryDto>();
  readonly jump = output<void>();

  private readonly comments = inject(CommentsService);

  protected readonly commentCount = computed(() => {
    const entry = this.entry();
    if (!entry.comments) return null;
    const state = this.comments.state(entry.id)();
    return state.status === 'ok' ? state.comments.length + state.omitted : entry.commentCount;
  });
}
