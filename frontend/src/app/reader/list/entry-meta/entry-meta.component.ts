import { Component, computed, inject, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { EntryPillsComponent } from '../../entry/entry-pills/entry-pills.component';
import { EntryActionsComponent } from '../../entry/entry-actions/entry-actions.component';
import { EntryDto, SubscriptionTagDto } from '../../models';
import { IconComponent, IconSize } from '../../../shared/icon/icon.component';
import { CompactCount } from '../../../core/i18n/compact-count';
import { pluralKey } from '../../../core/i18n/plural-key';
import { VotePillComponent } from '../../vote-pill/vote-pill.component';
import { showsShortPill } from '../preview-image';

/**
 * The line a magazine card or a list row ends on: the entry's pills, led by a Lemmy
 * post's score and comment count, with its actions right-aligned against them. One component, not a row assembled per
 * host, so the wrap geometry has one definition everywhere. A grouped compact
 * entry has no pills, so its actions live on the kicker line.
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
  private readonly compactCount = inject(CompactCount);
  protected readonly compactCommentCount = computed(() => {
    const count = this.entry().commentCount;
    return count === null ? null : this.compactCount.format(count);
  });
  protected readonly commentsKey = computed(() =>
    pluralKey('reader.discussionCounts.comments', this.entry().commentCount ?? 0),
  );
  readonly shortPill = computed(() => showsShortPill(this.entry(), this.imageShown()));
}
