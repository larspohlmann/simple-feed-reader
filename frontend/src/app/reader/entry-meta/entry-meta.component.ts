// src/app/reader/entry-meta/entry-meta.component.ts
import { Component, input, output } from '@angular/core';
import { SourceTagsComponent } from '../source-tags/source-tags.component';
import { EntryActionsComponent } from '../entry-actions/entry-actions.component';
import { EntryDto, SubscriptionTagDto } from '../models';

/**
 * The line a magazine card ends on: the feed's tag pills, and the entry's own
 * actions right-aligned against them. One component rather than a row assembled
 * in each block, so the geometry — where the icons sit when the pills wrap, and
 * where they sit when the card has spare height — has exactly one definition
 * for every magazine block that ends on this line, `entry-compact` included.
 */
@Component({
  selector: 'app-entry-meta',
  imports: [SourceTagsComponent, EntryActionsComponent],
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
