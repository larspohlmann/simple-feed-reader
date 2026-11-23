import { entryLinkNetwork } from '../../own-instance/entry-link-facts';
import { OwnInstanceHrefDirective } from '../../own-instance/own-instance-href.directive';
import { ChangeDetectionStrategy, Component, computed, inject, input, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { CommentsService, commentTotal } from './comments.service';
import { EntryDto } from '../../models';

/** The meta line's link to an entry's discussion: its own comments, or the source's thread. */
@Component({
  selector: 'app-entry-discussion-link',
  imports: [OwnInstanceHrefDirective, TranslocoPipe, IconComponent],
  templateUrl: './entry-discussion-link.component.html',
  styleUrl: './entry-discussion-link.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class EntryDiscussionLinkComponent {
  readonly entry = input.required<EntryDto>();

  protected readonly linkNetwork = computed(() => entryLinkNetwork(this.entry()));
  readonly jump = output<void>();

  private readonly comments = inject(CommentsService);

  protected readonly commentCount = computed(() =>
    commentTotal(this.entry(), this.comments.state(this.entry())()),
  );
}
