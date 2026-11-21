import { Component, computed, inject, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { EntryPillsComponent } from '../../entry/entry-pills/entry-pills.component';
import { EntryActionHandler } from '../../entry/entry-actions/entry-action-handler';
import { EntryActionsComponent } from '../../entry/entry-actions/entry-actions.component';
import { EntryDto, SubscriptionTagDto } from '../../models';
import { IconComponent, IconSize } from '../../../shared/icon/icon.component';
import { CompactCount } from '../../../core/i18n/compact-count';
import { buildDiscussionCount } from '../../../core/i18n/discussion-count';
import { VotePillComponent } from '../../vote-pill/vote-pill.component';
import { showsShortPill } from '../preview-image';

/**
 * The line a magazine card or a list row ends on: the entry's pills, led by a social
 * post's score, comment and like counts, with its actions right-aligned against them. One
 * component, not a row assembled per host, so the wrap geometry has one definition
 * everywhere. A grouped compact entry has no pills, so its actions live on the
 * kicker line.
 */
@Component({
  selector: 'app-entry-meta',
  imports: [
    EntryPillsComponent,
    EntryActionsComponent,
    VotePillComponent,
    IconComponent,
    TranslocoPipe,
  ],
  templateUrl: './entry-meta.component.html',
  styleUrl: './entry-meta.component.scss',
})
export class EntryMetaComponent {
  readonly entry = input.required<EntryDto>();
  readonly tags = input<SubscriptionTagDto[]>([]);
  readonly imageShown = input(false);
  readonly size = input<Extract<IconSize, 'sm' | 'md'>>('sm');
  protected readonly actions = inject(EntryActionHandler);
  private readonly compactCount = inject(CompactCount);
  protected readonly comments = computed(() =>
    buildDiscussionCount(
      this.compactCount,
      this.entry().commentCount,
      'reader.discussionCounts.comments',
    ),
  );
  protected readonly likes = computed(() =>
    buildDiscussionCount(this.compactCount, this.entry().likeCount, 'reader.socialCounts.likes'),
  );
  readonly shortPill = computed(() => showsShortPill(this.entry(), this.imageShown()));
}
