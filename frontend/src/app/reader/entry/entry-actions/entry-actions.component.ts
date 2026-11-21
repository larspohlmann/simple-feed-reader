import { Component, computed, inject, input } from '@angular/core';
import { EntryActionHandler } from './entry-action-handler';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent, IconSize } from '../../../shared/icon/icon.component';
import { PendingDotsComponent } from '../../../shared/pending-dots/pending-dots.component';
import { FlagToggleDirective } from '../../../shared/flag-toggle/flag-toggle.directive';
import { EntryDto } from '../../models';
import { likeableSocial } from '../../entry-social';
import { CompactCount } from '../../../core/i18n/compact-count';
import { buildDiscussionCount } from '../../../core/i18n/discussion-count';
import { AudioPlayerService } from '../../audio-player.service';
import { EntryAudio } from '../../audio/entry-audio';
import { ListenLauncher } from '../../audio/listen-launcher';

/**
 * The per-entry actions — favorite, like on the post's network with its count (#1600), keep,
 * mark read, delete my own post (#1972), led by the declared duration, play and
 * add-to-playlist on an audio entry (#1436, #1449) — as one control cluster.
 * Lives here, not repeated per block: a second copy (hero vs entry-row) once
 * made actions read as unreliable across the view (#414).
 *
 * Clicks stop propagating — the surrounding card is itself clickable and would
 * open the entry instead of toggling the flag. Enter/Space keydowns stop
 * propagating too, since every card binds its own keydown to open on keyboard
 * activation; neither calls `preventDefault()`, which would cancel the
 * button's own native activation.
 */
@Component({
  selector: 'app-entry-actions',
  imports: [IconComponent, PendingDotsComponent, TranslocoPipe, FlagToggleDirective],
  templateUrl: './entry-actions.component.html',
  styleUrl: './entry-actions.component.scss',
  host: { '[class.glyph-md]': "size() === 'md'" },
})
export class EntryActionsComponent {
  readonly entry = input.required<EntryDto>();
  /** Glyph size for the icons. The standard list (`entry-row`) renders
   *  `md`; magazine blocks keep `sm`. Only these two are offered — the
   *  tap-target math is defined for both (see `glyph-md` in the stylesheet). */
  readonly size = input<Extract<IconSize, 'sm' | 'md'>>('sm');
  /** Off in the article view, whose own Listen row offers the same two controls. */
  readonly audio = input(true);
  /** Off in the article view, whose counts row below the post carries the like (#1615). */
  readonly like = input(true);
  protected readonly likeable = computed(() => {
    return this.like() ? likeableSocial(this.entry()) : null;
  });
  private readonly compactCount = inject(CompactCount);
  protected readonly likes = computed(() =>
    this.like()
      ? buildDiscussionCount(this.compactCount, this.entry().likeCount, 'reader.socialCounts.likes')
      : null,
  );
  protected readonly entryAudio = new EntryAudio(
    { entry: this.entry },
    inject(AudioPlayerService),
    inject(ListenLauncher),
  );
  protected readonly actions = inject(EntryActionHandler);
  protected readonly likePending = computed(() => this.actions.isLikeOnTheWire(this.entry()));
}
