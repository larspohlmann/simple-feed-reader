import { Component, computed, input } from '@angular/core';
import { EntryPillsComponent } from '../../entry/entry-pills/entry-pills.component';
import { EntryActionsComponent } from '../../entry/entry-actions/entry-actions.component';
import { EntryDto, SubscriptionTagDto } from '../../models';

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
  /** The card shows the entry's image, which carries a Short's badge in place of the pill. */
  readonly imageShown = input(false);
  readonly shortPill = computed(() => this.entry().isShort && !this.imageShown());
}
