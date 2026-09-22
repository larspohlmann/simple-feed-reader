import { Component, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { IconComponent } from '../../shared/icon/icon.component';
import { SavedSearchMembershipDto } from '../models';

/**
 * Pills linking to the saved searches an entry belongs to. Clicks stop
 * propagating so a pill inside a clickable card opens the search, not the entry.
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
