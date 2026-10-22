import { Component, computed, input } from '@angular/core';
import { EntryPillsComponent } from '../../entry/entry-pills/entry-pills.component';
import { EntryActionsComponent } from '../../entry/entry-actions/entry-actions.component';
import { EntryDto, SubscriptionTagDto } from '../../models';
import { IconSize } from '../../../shared/icon/icon.component';
import { showsShortPill } from '../preview-image';

/**
 * The line a magazine card or a list row ends on: the entry's pills, with its
 * actions right-aligned against them. One component, not a row assembled per
 * host, so the wrap geometry has one definition everywhere. A grouped compact
 * entry has no pills, so its actions live on the kicker line.
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
  readonly imageShown = input(false);
  readonly size = input<Extract<IconSize, 'sm' | 'md'>>('sm');
  readonly shortPill = computed(() => showsShortPill(this.entry(), this.imageShown()));
}
