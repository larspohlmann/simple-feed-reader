import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { IconButtonDirective } from '../../../shared/icon-button/icon-button.directive';
import { EntryCommentDto } from '../../entry-comment';
import { VotePillComponent } from '../../vote-pill/vote-pill.component';

/** A comment's byline extras: its read-only score, and the delete for the reader's own reply. */
@Component({
  selector: 'app-comment-actions',
  imports: [TranslocoPipe, IconComponent, IconButtonDirective, VotePillComponent],
  templateUrl: './comment-actions.component.html',
  styleUrl: './comment-actions.component.scss',
  host: { class: 'scales-with-text' },
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class CommentActionsComponent {
  readonly comment = input.required<EntryCommentDto>();
  readonly deletable = input(false);
  readonly deleteRequested = output<void>();
}
