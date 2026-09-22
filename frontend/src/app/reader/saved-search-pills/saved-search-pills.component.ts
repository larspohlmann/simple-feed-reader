import { Component, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { IconComponent } from '../../shared/icon/icon.component';
import { SavedSearchMembershipDto } from '../models';

/**
 * The pills a card shows for the saved searches an entry belongs to — a
 * neutral, search-glyphed sibling of the feed tag pills, each linking to that
 * saved search. Clicks stop propagating so a pill inside a clickable card opens
 * the search, not the entry. Renders nothing when the entry matches none.
 */
@Component({
  selector: 'app-saved-search-pills',
  imports: [RouterLink, IconComponent],
  templateUrl: './saved-search-pills.component.html',
  styleUrl: './saved-search-pills.component.scss',
})
export class SavedSearchPillsComponent {
  readonly memberships = input.required<SavedSearchMembershipDto[]>();
}
