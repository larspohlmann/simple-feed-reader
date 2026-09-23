import { Component, input, output } from '@angular/core';
import { EntryPillsComponent } from '../entry-pills/entry-pills.component';
import { EntryActionsComponent } from '../entry-actions/entry-actions.component';
import { EntryDto, SubscriptionTagDto } from '../models';

/**
 * The line a magazine card ends on: the entry's pills, with its own
 * actions right-aligned against them. One component, not a row assembled per
 * block, so the wrap/spare-height geometry has one definition everywhere. A
 * grouped compact entry has no pills, so its actions live on the kicker line.
 */
@Component({
  selector: 'app-entry-meta',
  imports: [EntryPillsComponent, EntryActionsComponent],
  templateUrl: './entry-meta.component.html',
  styleUrl: './entry-meta.component.scss',
})
export class EntryMetaComponent {
  readonly entry = input.required<EntryDto>();
  readonly tags = input<SubscriptionTagDto[]>([]);
  readonly favorite = output<EntryDto>();
  readonly keep = output<EntryDto>();
  readonly read = output<EntryDto>();
}
