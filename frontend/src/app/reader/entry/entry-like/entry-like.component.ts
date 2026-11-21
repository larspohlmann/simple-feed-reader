import { Component, computed, inject, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { PendingAction } from '../../../core/rx/pending-action';
import { CompactCount } from '../../../core/i18n/compact-count';
import { buildDiscussionCount } from '../../../core/i18n/discussion-count';
import { IconComponent, IconSize } from '../../../shared/icon/icon.component';
import { PendingDotsComponent } from '../../../shared/pending-dots/pending-dots.component';
import { FlagToggleDirective } from '../../../shared/flag-toggle/flag-toggle.directive';
import { EntryDto } from '../../models';
import { likeableSocial } from '../../entry-social';
import { EntryActionHandler } from '../entry-actions/entry-action-handler';

/**
 * A social post's like on its network with the like count (#1600), or the bare count
 * when the reader cannot like there. Renders nothing for an entry with neither.
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
  protected readonly likeable = computed(() => likeableSocial(this.entry()));
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
