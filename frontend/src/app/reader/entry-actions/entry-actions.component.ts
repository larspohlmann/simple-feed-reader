import { Component, input, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent, IconSize } from '../../shared/icon/icon.component';
import { EntryDto } from '../models';

/**
 * The three per-entry actions — favorite, keep, mark read — as one control
 * cluster. Every magazine card carries it, so it lives here rather than being
 * repeated per block: it used to exist twice (hero and entry-row) and the
 * second copy is what made the actions read as unreliable across the view
 * (#414).
 *
 * Clicks stop propagating, because the card around it is itself clickable and
 * would otherwise open the entry instead of toggling the flag. Enter and Space
 * keydowns stop propagating too, for the same reason — every card also binds
 * `keydown.enter`/`keydown.space` on itself to open on keyboard activation.
 * Neither keydown calls `preventDefault()`: that would cancel the button's own
 * native activation (Enter fires its click on keydown, Space on keyup), which
 * is what must still happen for the action to fire.
 */
@Component({
  selector: 'app-entry-actions',
  imports: [IconComponent, TranslocoPipe],
  templateUrl: './entry-actions.component.html',
  styleUrl: './entry-actions.component.scss',
  host: { '[class.glyph-md]': "size() === 'md'" },
})
export class EntryActionsComponent {
  readonly entry = input.required<EntryDto>();
  /** Glyph size for the three icons. The standard list (`entry-row`) renders
   *  `md`; every magazine block keeps the default `sm`. Only these two steps
   *  are offered, because the cluster's coarse-pointer tap-target math is
   *  defined for both (see the `glyph-md` branch in the stylesheet). */
  readonly size = input<Extract<IconSize, 'sm' | 'md'>>('sm');
  readonly favorite = output<EntryDto>();
  readonly keep = output<EntryDto>();
  readonly read = output<EntryDto>();
}
