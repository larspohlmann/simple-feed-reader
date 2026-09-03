import { Component, input, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent, IconSize } from '../../shared/icon/icon.component';
import { EntryDto } from '../models';

/**
 * The three per-entry actions — favorite, keep, mark read — as one control
 * cluster. Lives here, not repeated per block: a second copy (hero vs
 * entry-row) once made actions read as unreliable across the view (#414).
 *
 * Clicks stop propagating — the surrounding card is itself clickable and would
 * open the entry instead of toggling the flag. Enter/Space keydowns stop
 * propagating too, since every card binds its own keydown to open on keyboard
 * activation; neither calls `preventDefault()`, which would cancel the
 * button's own native activation.
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
   *  `md`; magazine blocks keep `sm`. Only these two are offered — the
   *  tap-target math is defined for both (see `glyph-md` in the stylesheet). */
  readonly size = input<Extract<IconSize, 'sm' | 'md'>>('sm');
  readonly favorite = output<EntryDto>();
  readonly keep = output<EntryDto>();
  readonly read = output<EntryDto>();
}
