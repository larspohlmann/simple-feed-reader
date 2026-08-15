import { Component, input, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../shared/icon/icon.component';
import { EntryDto } from '../models';

/**
 * The three per-entry actions — favorite, keep, mark read — as one control
 * cluster. Every magazine card carries it, so it lives here rather than being
 * repeated per block: it used to exist twice (hero and entry-row) and the
 * second copy is what made the actions read as unreliable across the view
 * (#414).
 *
 * Clicks stop propagating, because the card around it is itself clickable and
 * would otherwise open the entry instead of toggling the flag.
 */
@Component({
  selector: 'app-entry-actions',
  imports: [IconComponent, TranslocoPipe],
  templateUrl: './entry-actions.component.html',
  styleUrl: './entry-actions.component.scss',
})
export class EntryActionsComponent {
  readonly entry = input.required<EntryDto>();
  readonly favorite = output<EntryDto>();
  readonly keep = output<EntryDto>();
  readonly read = output<EntryDto>();
}
