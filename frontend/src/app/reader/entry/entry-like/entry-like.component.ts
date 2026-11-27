import { Component, computed, inject, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { PendingAction } from '../../../core/rx/pending-action';
import { CompactCount } from '../../../core/i18n/compact-count';
import { buildDiscussionCount } from '../../../core/i18n/discussion-count';
import { IconComponent, IconSize } from '../../../shared/icon/icon.component';
import { PendingDotsComponent } from '../../../shared/pending-dots/pending-dots.component';
import { FlagToggleDirective } from '../../../shared/flag-toggle/flag-toggle.directive';
import { EntryDto } from '../../models';
import { likeableSocial, takesLocalLike } from '../../entry-social';
import { EntryActionHandler } from '../entry-actions/entry-action-handler';

/**
 * The like of an entry: on its network with the like count where the reader can like there (#1600),
 * kept here otherwise (#2587). A bare count shows where the like cannot be given.
 */
@Component({
  selector: 'app-entry-like',
  imports: [IconComponent, PendingDotsComponent, TranslocoPipe, FlagToggleDirective],
  templateUrl: './entry-like.component.html',
  styleUrl: './entry-like.component.scss',
  host: { '[class.glyph-md]': "size() === 'md'" },
})
export class EntryLikeComponent {
  readonly entry = input.required<EntryDto>();
  readonly size = input<Extract<IconSize, 'sm' | 'md'>>('sm');
  protected readonly toggle = computed(() => {
    const entry = this.entry();
    const social = likeableSocial(entry);
    if (social) return { isLiked: social.isLiked, target: social.network };
    return takesLocalLike(entry) ? { isLiked: entry.isLiked, target: 'local' } : null;
  });
  private readonly compactCount = inject(CompactCount);
  protected readonly likes = computed(() =>
    buildDiscussionCount(this.compactCount, this.entry().likeCount, 'reader.socialCounts.likes'),
  );
  private readonly actions = inject(EntryActionHandler);
  protected readonly liking = new PendingAction<true>();

  protected toggleLike(): void {
    this.liking.run(true, () => this.actions.like(this.entry()));
  }
}
