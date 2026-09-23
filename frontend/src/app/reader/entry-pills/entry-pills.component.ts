import { Component, input } from '@angular/core';
import { SourceTagsComponent } from '../source-tags/source-tags.component';
import { SavedSearchPillsComponent } from '../saved-search-pills/saved-search-pills.component';
import { SavedSearchMembershipDto, SubscriptionTagDto } from '../models';

/** An entry's tag pills, then its saved-search pills, as one wrapping list. */
@Component({
  selector: 'app-entry-pills',
  imports: [SourceTagsComponent, SavedSearchPillsComponent],
  templateUrl: './entry-pills.component.html',
  styleUrl: './entry-pills.component.scss',
})
export class EntryPillsComponent {
  readonly tags = input.required<SubscriptionTagDto[]>();
  readonly savedSearches = input.required<SavedSearchMembershipDto[]>();
}
