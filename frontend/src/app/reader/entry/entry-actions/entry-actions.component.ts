import { Component, inject, input } from '@angular/core';
import { EntryActionHandler } from './entry-action-handler';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent, IconSize } from '../../../shared/icon/icon.component';
import { FlagToggleDirective } from '../../../shared/flag-toggle/flag-toggle.directive';
import { EntryDto } from '../../models';
import { AudioPlayerService } from '../../audio-player.service';
import { EntryAudio } from '../../audio/entry-audio';

/**
 * The per-entry actions — favorite, keep, mark read, led by play and
 * add-to-playlist on an audio entry (#1436) — as one control cluster. Lives
 * here, not repeated per block: a second copy (hero vs entry-row) once made
 * actions read as unreliable across the view (#414).
 *
 * Clicks stop propagating — the surrounding card is itself clickable and would
 * open the entry instead of toggling the flag. Enter/Space keydowns stop
 * propagating too, since every card binds its own keydown to open on keyboard
 * activation; neither calls `preventDefault()`, which would cancel the
 * button's own native activation.
 */
@Component({
  selector: 'app-entry-actions',
  imports: [IconComponent, TranslocoPipe, FlagToggleDirective],
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
  protected readonly entryAudio = new EntryAudio(this.entry, inject(AudioPlayerService));
  protected readonly actions = inject(EntryActionHandler);
}
