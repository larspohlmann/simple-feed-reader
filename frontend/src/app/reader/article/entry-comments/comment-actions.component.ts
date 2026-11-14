import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { IconButtonDirective } from '../../../shared/icon-button/icon-button.directive';
import { EntryCommentDto, EntryCommentVoteDto } from '../../entry-comment';
import { VotePillComponent } from '../../vote-pill/vote-pill.component';
import { VoteValue } from '../../vote-pill/vote';

/** What the reader does to one comment from its byline: vote on it, reply to it on Lemmy, delete their own. */
@Component({
  selector: 'app-comment-actions',
  imports: [TranslocoPipe, IconComponent, IconButtonDirective, VotePillComponent],
  templateUrl: './comment-actions.component.html',
  styleUrl: './comment-actions.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class CommentActionsComponent {
  readonly comment = input.required<EntryCommentDto>();
  readonly deletable = input(false);
  readonly voted = output<VoteValue>();
  readonly replyRequested = output<EntryCommentVoteDto>();
  readonly deleteRequested = output<void>();
}
